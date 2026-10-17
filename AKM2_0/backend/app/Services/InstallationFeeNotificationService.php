<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\JobOrder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the installation fee invoice once a job order is approved.
 *
 * Mirrors the SOA notification flow (BillingNotificationService): a PDF rendered
 * from an email_templates design, an email carrying that PDF, and an SMS from
 * sms_templates. Customer details are read from the job order and its application,
 * and the job order ID stands in as the invoice number (JO-{id}).
 *
 * The invoice is due on the installation date. It is a notice for the fee approval
 * put on the account balance, not an `invoices` row: monthly billing treats any
 * invoice row in a month as that month's bill, and an earlier one as the end of
 * first-bill proration, so a stored installation invoice would change the regular
 * bills. The customer pays the balance through the payment portal.
 */
class InstallationFeeNotificationService
{
    public const EMAIL_TEMPLATE_CODE = 'INSTALLATION_TEMPLATE';

    public const SMS_TEMPLATE_TYPE = 'InstallationFee';

    public const DRIVE_FOLDER = 'Installation Invoices';

    protected EmailQueueService $emailQueueService;
    protected ItexmoSmsService $smsService;

    public function __construct(EmailQueueService $emailQueueService, ItexmoSmsService $smsService)
    {
        $this->emailQueueService = $emailQueueService;
        $this->smsService = $smsService;
    }

    public function notify(JobOrder $jobOrder): array
    {
        $results = [
            'skipped' => false,
            'pdf_generated' => false,
            'invoice_url' => null,
            'email_queued' => false,
            'sms_sent' => false,
            'errors' => []
        ];

        $installationFee = (float) ($jobOrder->installation_fee ?? 0);

        if ($installationFee <= 0) {
            $results['skipped'] = true;
            return $results;
        }

        // Billed once: a job order whose invoice is already on file (including one sent at
        // onsite Done before the invoice moved to approval) is not sent a second one.
        if (!empty($jobOrder->invoice_url)) {
            $results['skipped'] = true;
            $results['invoice_url'] = $jobOrder->invoice_url;
            return $results;
        }

        try {
            $application = $jobOrder->application;

            if (!$application) {
                throw new \Exception("Application not found for job order {$jobOrder->id}");
            }

            $data = $this->prepareData($jobOrder, $application, $installationFee);

            $pdfPath = null;
            $pdfResult = $this->generatePdf($jobOrder, $data);
            if ($pdfResult['success']) {
                $results['pdf_generated'] = true;
                $pdfPath = $pdfResult['path'];

                $results['invoice_url'] = $this->storeInvoiceUrl($jobOrder, $pdfPath);
                if (!$results['invoice_url']) {
                    $results['errors'][] = 'Installation invoice PDF could not be saved to Google Drive';
                }
            } else {
                $results['errors'][] = 'Installation invoice PDF generation failed: ' . $pdfResult['error'];
            }

            // Always proceed to email — do NOT block on PDF failure
            if (!empty($application->email_address)) {
                $emailQueued = $this->emailQueueService->queueFromTemplate(
                    self::EMAIL_TEMPLATE_CODE,
                    array_merge($data, [
                        'recipient_email' => $application->email_address,
                        // The email processor deletes the attachment after sending
                        'attachment_path' => $pdfPath,
                    ])
                );

                $results['email_queued'] = $emailQueued !== null;
                if ($emailQueued === null) {
                    $results['errors'][] = "Email template '" . self::EMAIL_TEMPLATE_CODE . "' not found or inactive";
                    $this->deleteFile($pdfPath);
                }
            } else {
                $results['errors'][] = 'Customer has no email address';
                $this->deleteFile($pdfPath);
            }

            // Always proceed to SMS — do NOT block on PDF or email failure
            if (!empty($application->mobile_number)) {
                $smsMessage = $this->generateSmsMessage($data);

                if ($smsMessage) {
                    $smsResult = $this->smsService->send([
                        'contact_no' => $application->mobile_number,
                        'message' => $smsMessage
                    ]);
                    $results['sms_sent'] = $smsResult['success'];

                    if (!$smsResult['success']) {
                        $results['errors'][] = 'SMS failed: ' . ($smsResult['error'] ?? 'Unknown');
                    }
                } else {
                    $results['errors'][] = "SMS template '" . self::SMS_TEMPLATE_TYPE . "' not found or inactive";
                }
            } else {
                $results['errors'][] = 'Customer has no mobile number';
            }

            Log::info('Installation fee notification completed', [
                'job_order_id' => $jobOrder->id,
                'installation_fee' => $installationFee,
                'pdf_generated' => $results['pdf_generated'],
                'invoice_url' => $results['invoice_url'],
                'email_queued' => $results['email_queued'],
                'sms_sent' => $results['sms_sent'],
                'errors' => $results['errors']
            ]);
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            Log::error('Installation fee notification failed', [
                'job_order_id' => $jobOrder->id,
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Re-render an invoice already on file and point job_orders.invoice_url at the new PDF.
     *
     * For correcting PDFs generated before the due date moved to the installation date. The
     * customer is not notified again — no email, no SMS. The previous PDF is left on Google
     * Drive, and its link is returned, so the change can be undone by restoring invoice_url.
     *
     * @return array{success: bool, old_url: string|null, new_url: string|null, due_date: string|null, error: string|null}
     */
    public function regenerateInvoice(JobOrder $jobOrder): array
    {
        $result = ['success' => false, 'old_url' => $jobOrder->invoice_url ?: null, 'new_url' => null, 'due_date' => null, 'error' => null];

        $installationFee = (float) ($jobOrder->installation_fee ?? 0);
        if ($installationFee <= 0) {
            $result['error'] = 'No installation fee on this job order';
            return $result;
        }

        $application = $jobOrder->application;
        if (!$application) {
            $result['error'] = 'No application on this job order';
            return $result;
        }

        $data = $this->prepareData($jobOrder, $application, $installationFee);
        $result['due_date'] = $data['Due_Date'];

        $pdf = $this->generatePdf($jobOrder, $data);
        if (!$pdf['success']) {
            $result['error'] = 'PDF generation failed: ' . $pdf['error'];
            return $result;
        }

        $result['new_url'] = $this->storeInvoiceUrl($jobOrder, $pdf['path']);
        $this->deleteFile($pdf['path']);

        if (!$result['new_url']) {
            $result['error'] = 'Upload to Google Drive failed; invoice_url left unchanged';
            return $result;
        }

        $result['success'] = true;

        Log::info('Installation invoice regenerated', [
            'job_order_id' => $jobOrder->id,
            'due_date'     => $result['due_date'],
            'old_url'      => $result['old_url'],
            'new_url'      => $result['new_url'],
        ]);

        return $result;
    }

    protected function prepareData(JobOrder $jobOrder, $application, float $installationFee): array
    {
        $customerName = preg_replace('/\s+/', ' ', trim(implode(' ', [
            $application->first_name,
            $application->middle_initial,
            $application->last_name
        ])));

        $fullAddress = implode(', ', array_filter([
            $application->installation_address,
            $application->location,
            $application->barangay,
            $application->city,
            $application->region
        ]));

        $displayPlan = $application->desired_plan ?? 'N/A';
        if (strpos($displayPlan, ' - P') !== false) {
            $displayPlan = trim(explode(' - P', $displayPlan)[0]);
        }
        $displayPlan = str_replace('₱', 'P', $displayPlan);

        $invoiceDate = $jobOrder->date_installed
            ? \Carbon\Carbon::parse($jobOrder->date_installed)
            : now();
        $dueDate = self::dueDate($invoiceDate);

        $accountNo = $jobOrder->account_id
            ? (string) (DB::table('billing_accounts')->where('id', $jobOrder->account_id)->value('account_no') ?? '')
            : '';

        $amount = number_format($installationFee, 2);
        $brandName = DB::table('form_ui')->value('brand_name') ?? 'Your ISP';
        $paymentLink = config('app.payment_link', 'https://sync.akmiis.com');

        return [
            // PDF / email placeholders — same naming as the SOA template
            'Full_Name' => $customerName,
            'Address' => $fullAddress,
            'Street' => $application->installation_address,
            'Barangay' => $application->barangay,
            'City' => $application->city,
            'Province' => $application->region,
            'Contact_No' => $application->mobile_number,
            'Email' => $application->email_address,
            'Plan' => $displayPlan,
            'Account_No' => $accountNo,
            'Invoice_No' => 'JO-' . $jobOrder->id,
            'Job_Order_No' => $jobOrder->id,
            'Statement_Date' => $invoiceDate->format('F d, Y'),
            'Invoice_Date' => $invoiceDate->format('F d, Y'),
            'Date_Installed' => $invoiceDate->format('F d, Y'),
            'Due_Date' => $dueDate->format('F d, Y'),
            'Installation_Fee' => $amount,
            'Amount_Due' => $amount,
            'Total_Due' => $amount,
            'Payment_Link' => $paymentLink,
            'Company_Name' => $brandName,

            // SMS-style placeholders
            'customer_name' => $customerName,
            'account_no' => $accountNo,
            'plan_name' => $displayPlan,
            'installation_fee' => $amount,
            'amount' => $amount,
            'amount_due' => $amount,
            'total_due' => $amount,
            'due_date' => $dueDate->format('M d, Y'),
            'invoice_no' => 'JO-' . $jobOrder->id,
            'payment_link' => $paymentLink,
            'company_name' => $brandName,
            'portal_url' => 'sync.akmiis.com',
        ];
    }

    /**
     * The installation fee is due on the installation date itself — no due-day offset,
     * grace period or proration, none of which apply to a one-time fee.
     */
    public static function dueDate(\Carbon\Carbon $installationDate): \Carbon\Carbon
    {
        return $installationDate->copy()->startOfDay();
    }

    protected function generatePdf(JobOrder $jobOrder, array $data): array
    {
        try {
            $template = EmailTemplate::where('Template_Code', self::EMAIL_TEMPLATE_CODE)
                ->where('Is_Active', true)
                ->first();

            if (!$template || empty(trim($template->Body_HTML ?? ''))) {
                throw new \Exception('PDF template ' . self::EMAIL_TEMPLATE_CODE . ' not found');
            }

            $html = $this->replacePlaceholders($template->Body_HTML, $data);
            $html = $this->addStrictCss() . $html;

            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'Helvetica');
            $options->set('dpi', 96);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $directory = storage_path('app/installation_invoices');
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $path = $directory . DIRECTORY_SEPARATOR . "INSTALLATION-JO{$jobOrder->id}-" . uniqid() . '.pdf';
            file_put_contents($path, $dompdf->output());

            return ['success' => true, 'path' => $path];
        } catch (\Exception $e) {
            Log::error('Installation invoice PDF generation failed', [
                'job_order_id' => $jobOrder->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Uploads the invoice PDF to Google Drive and records the link in job_orders.invoice_url,
     * which the customer's Bills page reads. The local file stays for the email attachment.
     */
    protected function storeInvoiceUrl(JobOrder $jobOrder, string $pdfPath): ?string
    {
        try {
            // Resolved here, not injected: a Drive client that fails to build must not stop the email and SMS
            $drive = app(GoogleDriveService::class);
            $folderId = $drive->createFolder(self::DRIVE_FOLDER);
            $url = $drive->uploadFile($pdfPath, $folderId, "INSTALLATION-JO{$jobOrder->id}.pdf", 'application/pdf');

            // Query builder, so recording the link does not touch updated_at or fire model events
            DB::table('job_orders')->where('id', $jobOrder->id)->update(['invoice_url' => $url]);
            $jobOrder->invoice_url = $url;

            return $url;
        } catch (\Throwable $e) {
            Log::error('Installation invoice PDF upload failed', [
                'job_order_id' => $jobOrder->id,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    protected function generateSmsMessage(array $data): ?string
    {
        $template = DB::table('sms_templates')
            ->where('template_type', self::SMS_TEMPLATE_TYPE)
            ->where('is_active', 1)
            ->first();

        if (!$template) {
            Log::warning('Installation Fee SMS Template not found or inactive', ['template_type' => self::SMS_TEMPLATE_TYPE]);
            return null;
        }

        $message = $template->message_content;
        foreach ($data as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }

        return $message;
    }

    protected function replacePlaceholders(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);

            // Also replace space variant: {{Key Name}} (template may use spaces instead of underscores)
            $spaceKey = str_replace('_', ' ', $key);
            if ($spaceKey !== $key) {
                $template = str_replace('{{' . $spaceKey . '}}', (string) $value, $template);
            }
        }
        return $template;
    }

    // Same page CSS as the SOA PDF (GoogleDrivePdfGenerationService) so both render alike
    protected function addStrictCss(): string
    {
        return "
        <style>
            @page { margin: 0px; }
            html, body { margin: 0px; padding: 0px; font-family: Helvetica, Arial, sans-serif; font-size: 10pt; }
            .full-width { width: 100%; display: block; }
            .content-wrap { padding: 20px 40px; }
            table { width: 100%; border-collapse: collapse; border-spacing: 0; }
            td, th { padding: 4px; vertical-align: top; border: none; }
            table[border='1'] td, table[border='1'] th, .bordered td, .bordered th { border: 1px solid #000 !important; }
            p { margin: 0 0 8px 0; line-height: 1.3; }
            img { display: block; max-width: 100%; height: auto; }
        </style>";
    }

    protected function deleteFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            unlink($path);
        }
    }
}
