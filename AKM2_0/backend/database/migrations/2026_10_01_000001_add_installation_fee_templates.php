<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registers the installation fee invoice templates sent when a job order's
     * onsite status becomes Done (InstallationFeeNotificationService).
     *
     * The PDF design is the SOA_TEMPLATE layout — same header image, account
     * block, Bill Summary / Bill Description boxes, How to Pay steps, payment
     * stub and footer notices — with the statement rows replaced by a single
     * installation fee charge. The header image is copied from the live
     * SOA_TEMPLATE (it is an embedded image, too large to keep in source), as
     * are its margins and sender settings. Editable afterwards in the Email
     * Templates page.
     *
     * A migration rather than a seeder: seeders here generate demo data and are
     * not run on production, and these templates have to exist wherever the app does.
     */
    private const EMAIL_TEMPLATE_CODE = 'INSTALLATION_TEMPLATE';

    private const SMS_TEMPLATE_NAME = 'Installation Fee SMS';

    private const SMS_TEMPLATE_TYPE = 'InstallationFee';

    private const SMS_MESSAGE = 'Hi {{customer_name}}, your installation is complete. '
        . 'Your installation fee invoice {{invoice_no}} is now available. Amount Due: {{installation_fee}}. '
        . 'Due Date: {{due_date}}. Please pay via portal.akmiis.com. ignore if paid.';

    // email_body is a varchar(255): kept short, like SOA_TEMPLATE's. The invoice itself is the attached PDF.
    private const EMAIL_BODY = 'Hi {{customer_name}}, your installation fee invoice {{invoice_no}} is now available. '
        . 'Amount Due: {{installation_fee}}. Due Date: {{due_date}}. Please pay via portal.akmiis.com.';

    public function up(): void
    {
        if (Schema::hasTable('email_templates')) {
            $this->addEmailTemplate();
        }

        if (Schema::hasTable('sms_templates')) {
            $this->addSmsTemplate();
        }
    }

    /**
     * Removes the rows only if they are still the ones this migration inserted.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_templates')) {
            DB::table('email_templates')
                ->where('Template_Code', self::EMAIL_TEMPLATE_CODE)
                ->where('modified_by', 'System')
                ->delete();
        }

        if (Schema::hasTable('sms_templates')) {
            DB::table('sms_templates')
                ->where('template_name', self::SMS_TEMPLATE_NAME)
                ->where('message_content', self::SMS_MESSAGE)
                ->delete();
        }
    }

    private function addEmailTemplate(): void
    {
        // Must not overwrite a design someone has since edited in the Email Templates page
        if (DB::table('email_templates')->where('Template_Code', self::EMAIL_TEMPLATE_CODE)->exists()) {
            return;
        }

        $soa = DB::table('email_templates')->where('Template_Code', 'SOA_TEMPLATE')->first();

        DB::table('email_templates')->insert([
            'Template_Code' => self::EMAIL_TEMPLATE_CODE,
            'Subject_Line' => 'AKM IIS: INSTALLATION FEE INVOICE',
            'Body_HTML' => $this->extractHeader($soa->Body_HTML ?? '') . $this->bodyHtml(),
            'email_body' => self::EMAIL_BODY,
            'Description' => 'The HTML design for the Installation Fee Invoice PDF',
            'Is_Active' => true,
            'Page_Margin' => $soa->Page_Margin ?? '0in',
            'Image_Margin' => $soa->Image_Margin ?? '0px',
            'cc' => $soa->cc ?? null,
            'bcc' => $soa->bcc ?? null,
            'email_sender' => $soa->email_sender ?? null,
            'reply_to' => $soa->reply_to ?? null,
            'sender_name' => $soa->sender_name ?? null,
            'modified_by' => 'System',
            'modifiet_at' => now(),
            'organization_id' => $soa->organization_id ?? null,
        ]);
    }

    private function addSmsTemplate(): void
    {
        if (DB::table('sms_templates')->where('template_type', self::SMS_TEMPLATE_TYPE)->exists()) {
            return;
        }

        DB::table('sms_templates')->insert([
            'template_name' => self::SMS_TEMPLATE_NAME,
            'template_type' => self::SMS_TEMPLATE_TYPE,
            'message_content' => self::SMS_MESSAGE,
            'variables' => json_encode([
                '{{customer_name}}', '{{company_name}}', '{{invoice_no}}',
                '{{installation_fee}}', '{{due_date}}', '{{payment_link}}', '{{plan_name}}',
            ]),
            'is_active' => 1,
            'organization_id' => null,
            'created_by' => 'System',
            'updated_by' => 'System',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The SOA design opens with its header image paragraph (<p><img …></p>).
     * Falls back to a full-bleed image inserted by the editor's Header button.
     */
    private function extractHeader(string $html): string
    {
        if (preg_match('/^\s*<p[^>]*>\s*<img\b[^>]*>\s*<\/p>/i', $html, $match)) {
            return $match[0];
        }

        if (preg_match('/<img\b[^>]*margin:\s*0[;\s"\'][^>]*>/i', $html, $match)) {
            return $match[0];
        }

        return '';
    }

    private function bodyHtml(): string
    {
        // Cell border styles as written by the SOA template's editor
        $none = 'border-width: 0px !important; border-style: none !important; border-color: transparent !important;';
        $font = 'font-family: arial, helvetica, sans-serif;';
        $left = 'border-left: 2px solid black; border-top: 0px none transparent !important; border-right: 0px none transparent !important; border-bottom: 0px none transparent !important; line-height: 1;';
        $right = 'border-right: 2px solid black; border-top: 0px none transparent !important; border-bottom: 0px none transparent !important; border-left: 0px none transparent !important; line-height: 1;';
        $stubLeft = 'line-height: 1; text-align: left; border-left: 1px solid rgb(0, 0, 0); border-top: 0px none transparent !important; border-right: 0px none transparent !important; border-bottom: 0px none transparent !important;';
        $stubRight = 'line-height: 1; text-align: right; border-right: 1px solid rgb(0, 0, 0); border-top: 0px none transparent !important; border-bottom: 0px none transparent !important; border-left: 0px none transparent !important;';
        $dashes = str_repeat('&ndash; ', 74);

        return <<<HTML
<p dir="ltr" style="text-align: center;"><span style="{$font} font-size: 14pt;"><strong>INSTALLATION FEE INVOICE</strong></span></p>
<div dir="ltr" align="left">
<table style="width: 95%; height: 121.562px; border-collapse: collapse; margin-left: auto; margin-right: auto; margin-bottom: 20px;"><colgroup><col style="width: 14.9063%;" width="97"><col style="width: 54.0007%;" width="443"><col style="width: 31.093%;" width="251"></colgroup>
<tbody>
<tr style="height: 34.375px;">
<td style="{$none}"><span style="{$font} font-size: 8pt;">Account Name:</span></td>
<td style="{$none}"><span style="{$font} font-size: 10pt;"><strong>{{Full_Name}}</strong></span></td>
<td style="border: 2px solid black; text-align: center;"><span style="{$font}"><strong>BILLING INFORMATION</strong></span></td>
</tr>
<tr style="height: 21.375px;">
<td style="{$none}"><span style="font-size: 8pt; {$font}">Address:</span></td>
<td style="{$none}"><span style="font-size: 8pt; {$font}">{{Address}}</span></td>
<td style="border-top: 1px solid black; border-left: 2px solid black; border-right: 2px solid black; border-bottom: 0px none transparent !important;"><span style="{$font} font-size: 11pt;">Invoice No.: <strong>{{Invoice_No}}</strong></span></td>
</tr>
<tr style="height: 32.4375px;">
<td style="{$none}"><span style="font-size: 8pt; {$font}">Contact No.:</span></td>
<td style="{$none}"><span style="font-size: 8pt; {$font}">{{Contact_No}}</span></td>
<td style="border-left: 2px solid black; border-right: 2px solid black; border-top: 0px none transparent !important; border-bottom: 0px none transparent !important;"><span style="{$font} font-size: 10pt;">Date Installed: {{Date_Installed}}</span></td>
</tr>
<tr style="height: 33.375px;">
<td style="{$none}"><span style="font-size: 8pt; {$font}">Email Address:</span></td>
<td style="{$none}"><span style="font-size: 8pt; {$font}">{{Email}}</span></td>
<td style="border-bottom: 2px solid black; border-left: 2px solid black; border-right: 2px solid black; border-top: 0px none transparent !important;"><span style="{$font} font-size: 9pt;">Invoice Date: {{Invoice_Date}}</span></td>
</tr>
</tbody>
</table>
</div>
<div dir="ltr" align="left">
<table style="border-collapse: collapse; width: 96.9573%; margin-left: auto; margin-right: auto;"><colgroup><col style="width: 14.1409%;" width="80"><col style="width: 34.1148%;" width="235"><col style="width: 17.588%;" width="128"><col style="width: 2.12648%;" width="17"><col style="width: 32.0299%;" width="324"></colgroup>
<tbody>
<tr style="height: 39.1667px;">
<td style="border: 2px solid black; text-align: center; line-height: 1;" colspan="3"><strong><span style="font-size: 10pt; {$font}">BILL SUMMARY</span></strong></td>
<td style="{$none}">&nbsp;</td>
<td style="border-width: 2px 2px 1px; border-style: solid; border-color: black; text-align: center;"><span style="font-size: 10pt;"><strong><span style="{$font}">BILL DESCRIPTION</span></strong></span></td>
</tr>
<tr style="height: 44.375px;">
<td style="border-top: 2px solid black; border-right: 0px none transparent !important; border-bottom: 1px solid black; border-left: 2px solid black; line-height: 1;" colspan="2"><span style="font-size: 9pt; {$font}"><strong>Installation Charges</strong></span></td>
<td style="border-top: 2px solid black; border-right: 2px solid black; border-bottom: 1px solid black; border-left: 0px none transparent !important; line-height: 1;">&nbsp;</td>
<td style="{$none}">&nbsp;</td>
<td style="border-top: 2px solid rgb(0, 0, 0); border-right: 2px solid rgb(0, 0, 0); border-bottom: 0px none transparent !important; border-left: 2px solid rgb(0, 0, 0); text-align: center;"><span style="font-size: 9pt; {$font}">{{Plan}}</span></td>
</tr>
<tr style="height: 33.8715px;">
<!-- Explicit widths: the SOA gets its column widths from its longer row labels; PDF rendering ignores colgroup -->
<td style="{$left} width: 14%;">&nbsp;</td>
<td style="{$none} line-height: 1; width: 34%;"><span style="font-size: 10pt; {$font}">Installation Fee</span></td>
<td style="{$right} text-align: right; width: 14%;"><span style="font-size: 10pt; {$font}">{{Installation_Fee}}</span></td>
<td style="{$none} width: 4%;">&nbsp;</td>
<td style="width: 34%; border-right: 2px solid black; border-bottom: 2px solid black; border-left: 2px solid black; text-align: center; border-top: 0px none transparent !important;"><span style="font-size: 9pt; {$font}">Installed on: {{Date_Installed}}</span></td>
</tr>
<tr style="height: 36.6667px;">
<td style="{$left}">&nbsp;</td>
<td style="{$none} line-height: 1;">&nbsp;</td>
<td style="{$right}">&nbsp;</td>
<td style="{$none}">&nbsp;</td>
<td style="text-align: center; border-top: 1px solid black; border-right: 0px none transparent !important; border-bottom: 0px none transparent !important; border-left: 0px none transparent !important;"><span style="text-decoration: underline; font-size: 12pt; {$font}"><strong>How to Pay</strong></span></td>
</tr>
<tr style="height: 35.6944px;">
<td style="{$left}">&nbsp;</td>
<td style="{$none} line-height: 1;">&nbsp;</td>
<td style="{$right}">&nbsp;</td>
<td style="{$none}">&nbsp;</td>
<td style="{$none} text-align: left;"><span style="font-size: 12pt; {$font}">1. Go to portal.akmiis.com</span></td>
</tr>
<tr style="height: 35.6944px;">
<td style="{$left}">&nbsp;</td>
<td style="{$none} line-height: 1;">&nbsp;</td>
<td style="{$right}">&nbsp;</td>
<td style="{$none}">&nbsp;</td>
<td style="{$none} text-align: left;"><span style="font-size: 12pt; {$font}">2. Login your credentials</span></td>
</tr>
<tr style="height: 35.6944px;">
<td style="border-top: 1px solid black; border-right: 0px none transparent !important; border-bottom: 0px none transparent !important; border-left: 2px solid black; line-height: 1;"><span style="text-decoration: underline; {$font}"><span style="font-size: 9pt;"><strong>DUE DATE</strong></span></span></td>
<td style="border-top: 1px solid black; border-left: 0px none transparent !important; line-height: 1; border-right: 0px none transparent !important; border-bottom: 0px none transparent !important;">&nbsp;</td>
<td style="border-top: 1px solid black; border-right: 2px solid black; border-bottom: 0px none transparent !important; border-left: 0px none transparent !important; line-height: 1; text-align: right;"><span style="text-decoration: underline; {$font}"><strong><span style="font-size: 9pt;">AMOUNT DUE</span></strong></span></td>
<td style="{$none}">&nbsp;</td>
<td style="{$none} text-align: left;"><span style="font-size: 12pt; {$font}">3. Click/Tap pay now</span></td>
</tr>
<tr style="height: 43.7674px;">
<td style="border-top: 0px none transparent !important; border-right: 0px none transparent !important; border-bottom: 2px solid black; border-left: 2px solid black; line-height: 1;" colspan="2"><span style="{$font}"><strong><span style="font-size: 9pt; color: #e03e2d;">Please pay on or before</span></strong></span><br><span style="color: #e03e2d; {$font}"><em><span style="font-size: 12pt;"><strong>{{Due_Date}}</strong></span></em></span></td>
<td style="border-top: 0px none transparent !important; border-right: 2px solid black; border-bottom: 2px solid black; border-left: 0px none transparent !important; line-height: 1; text-align: right;"><span style="font-size: 10pt;"><strong><span style="{$font}">{{Amount_Due}}</span></strong></span></td>
<td style="{$none}">&nbsp;</td>
<td style="{$none} text-align: left;"><span style="font-size: 12pt; {$font}">4. Input amount to be paid</span></td>
</tr>
<tr style="height: 35.6944px;">
<td style="border-top: 1px solid black; border-right: 0px none transparent !important; border-bottom: 2px solid black; border-left: 2px solid black; line-height: 1;" colspan="2"><span style="font-size: 12pt; {$font}"><strong>TOTAL AMOUNT DUE</strong></span></td>
<td style="border-top: 1px solid black; border-right: 2px solid black; border-bottom: 2px solid black; border-left: 0px none transparent !important; line-height: 1; text-align: right;"><span style="font-size: 12pt; {$font}"><strong>{{Total_Due}}</strong></span></td>
<td style="{$none}">&nbsp;</td>
<td style="{$none} text-align: left;"><span style="font-size: 12pt; {$font}">5. Choose your payment method</span></td>
</tr>
<tr style="height: 35.6944px;">
<td style="{$none}" colspan="3">&nbsp;</td>
<td style="{$none}">&nbsp;</td>
<td style="{$none} text-align: left;"><span style="font-size: 12pt; {$font}">6. Save the reference no.</span></td>
</tr>
<tr style="height: 45.7813px;">
<td style="{$none} line-height: 1; text-align: center;" colspan="5"><span style="{$font}"><span style="font-size: 9pt;"><strong>Please pay your installation fee on or before the due date indicated on this invoice.</strong></span></span></td>
</tr>
<tr style="height: 29.5486px;">
<td style="{$none} line-height: 1; text-align: center;" colspan="5"><span style="font-size: 9pt; {$font}"><strong>{$dashes}</strong></span></td>
</tr>
<tr style="height: 30.1042px;">
<td style="border: 1px solid rgb(0, 0, 0); line-height: 1; text-align: center;" colspan="5"><span style="{$font}"><span style="font-size: 12pt;"><strong>PAYMENT STUB</strong></span></span></td>
</tr>
<tr style="height: 27.3264px;">
<td style="{$stubLeft}"><span style="font-size: 9pt; {$font}"><strong>Account Name:</strong></span></td>
<td style="{$none} line-height: 1; text-align: left;"><span style="font-size: 9pt; {$font}">{{Full_Name}}</span></td>
<td style="{$none} line-height: 1; text-align: right;" colspan="2"><span style="{$font}"><strong><span style="font-size: 9pt;">DUE DATE:</span></strong></span></td>
<td style="{$stubRight}"><span style="font-size: 9pt; {$font}">{{Due_Date}}</span></td>
</tr>
<tr style="height: 26.7708px;">
<td style="{$stubLeft}"><span style="font-size: 9pt; {$font}"><strong>Address:</strong></span></td>
<td style="{$none} line-height: 1; text-align: left;" colspan="2"><span style="font-size: 9pt; {$font}">{{Address}}</span></td>
<td style="{$none} line-height: 1;">&nbsp;</td>
<td style="{$stubRight}">&nbsp;</td>
</tr>
<tr style="height: 26.7708px;">
<td style="{$stubLeft}"><span style="font-size: 9pt; {$font}"><strong>Contact No.:</strong></span></td>
<td style="{$none} line-height: 1; text-align: left;"><span style="font-size: 9pt; {$font}">{{Contact_No}}</span></td>
<td style="{$none} line-height: 1; text-align: right;" colspan="2"><span style="font-size: 9pt; {$font}"><strong>Installation Fee</strong>:&nbsp;</span></td>
<td style="{$stubRight}"><span style="font-size: 9pt; {$font}">{{Installation_Fee}}</span></td>
</tr>
<tr style="height: 26.7708px;">
<td style="{$stubLeft}"><span style="font-size: 9pt; {$font}"><strong>Date Installed:</strong></span></td>
<td style="{$none} line-height: 1; text-align: left;" colspan="2"><span style="font-size: 9pt; {$font}">{{Date_Installed}}</span></td>
<td style="{$none} line-height: 1;">&nbsp;</td>
<td style="{$stubRight}">&nbsp;</td>
</tr>
<tr style="height: 27.3264px;">
<td style="line-height: 1; text-align: left; border-bottom: 1px solid rgb(0, 0, 0); border-left: 1px solid rgb(0, 0, 0); border-top: 0px none transparent !important; border-right: 0px none transparent !important;"><span style="font-size: 9pt; {$font}"><strong>Invoice No.:</strong></span></td>
<td style="line-height: 1; text-align: left; border-bottom: 1px solid rgb(0, 0, 0); border-top: 0px none transparent !important; border-right: 0px none transparent !important; border-left: 0px none transparent !important;"><span style="font-size: 9pt; {$font}">{{Invoice_No}}</span></td>
<td style="line-height: 1; text-align: right; border-bottom: 1px solid rgb(0, 0, 0); border-top: 0px none transparent !important; border-right: 0px none transparent !important; border-left: 0px none transparent !important;" colspan="2"><span style="font-size: 9pt; {$font}"><strong>TOTAL AMOUNT DUE:</strong>&nbsp;</span></td>
<td style="line-height: 1; text-align: right; border-bottom: 1px solid rgb(0, 0, 0); border-right: 1px solid rgb(0, 0, 0); border-top: 0px none transparent !important; border-left: 0px none transparent !important;"><span style="font-size: 9pt; {$font}">{{Total_Due}}</span></td>
</tr>
<tr style="height: 46.3194px;">
<td style="{$none} line-height: 1; text-align: center;" colspan="5"><span style="font-size: 8pt; {$font}">FOR BILLING CONCERNS: PLEASE MESSAGE US AT OUR FACEBOOK ACCOUNT <strong><em><a href="https://www.facebook.com/akmiis">www.facebook.com/akmiis</a></em></strong><br>Contact No. : 0997 374 4561</span></td>
</tr>
<tr style="height: 62.5347px;">
<td style="border: 1px dashed rgb(0, 0, 0); line-height: 1; text-align: center;" colspan="5"><span style="font-size: 7pt; {$font}">This document has been sent <strong>ELECTRONICALLY</strong> and does not require any signature<br><strong>Payment Reminder:</strong> The <strong>Installation Fee</strong> must be fully paid on or before the due date indicated on this invoice.</span></td>
</tr>
</tbody>
</table>
</div>
<p>&nbsp;</p>
HTML;
    }
};
