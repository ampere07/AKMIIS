<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\JobOrder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the installation fee invoice once a job order's onsite status becomes Done.
 *
 * Mirrors the SOA notification flow (BillingNotificationService): a PDF rendered
 * from an email_templates design, an email carrying that PDF, and an SMS from
 * sms_templates. It runs before the job order is approved, so there is no
 * billing account yet — everything is read from the job order and its
 * application, and the job order ID stands in as the invoice number.
 */
class InstallationFeeNotificationService
{
    public const EMAIL_TEMPLATE_CODE = 'INSTALLATION_TEMPLATE';

    public const SMS_TEMPLATE_TYPE = 'InstallationFee';

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
            'email_queued' => false,
            'sms_sent' => false,
            'errors' => []
        ];

        $installationFee = (float) ($jobOrder->installation_fee ?? 0);

        if ($installationFee <= 0) {
            $results['skipped'] = true;
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
        $dueDate = $invoiceDate->copy()->addDays((int) config('billing.due_days_add', 7));

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
