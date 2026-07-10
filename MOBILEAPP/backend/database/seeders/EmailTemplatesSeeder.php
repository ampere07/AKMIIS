<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailTemplatesSeeder extends Seeder
{
    public function run()
    {
        $templates = [
            [
                'Template_Code' => 'SOA_DESIGN',
                'Template_Name' => 'Statement of Account PDF Design',
                'Template_Description' => 'PDF template for SOA generation',
                'Subject' => null,
                'Body_HTML' => $this->getSoaPdfTemplate(),
                'Body_Text' => null,
                'Is_Active' => true,
                'Created_Date' => now(),
                'Modified_Date' => now(),
            ],
            [
                'Template_Code' => 'INVOICE_DESIGN',
                'Template_Name' => 'Invoice PDF Design',
                'Template_Description' => 'PDF template for Invoice generation',
                'Subject' => null,
                'Body_HTML' => $this->getInvoicePdfTemplate(),
                'Body_Text' => null,
                'Is_Active' => true,
                'Created_Date' => now(),
                'Modified_Date' => now(),
            ],
            [
                'Template_Code' => 'SOA_DESIGN_EMAIL',
                'Template_Name' => 'Statement of Account Email',
                'Template_Description' => 'Email template for SOA notification',
                'Subject' => 'Your Statement of Account - {{Account_No}}',
                'Body_HTML' => $this->getSoaEmailTemplate(),
                'Body_Text' => null,
                'Is_Active' => true,
                'Created_Date' => now(),
                'Modified_Date' => now(),
            ],
            [
                'Template_Code' => 'INVOICE_DESIGN_EMAIL',
                'Template_Name' => 'Invoice Email',
                'Template_Description' => 'Email template for Invoice notification',
                'Subject' => 'Your Invoice - {{Account_No}}',
                'Body_HTML' => $this->getInvoiceEmailTemplate(),
                'Body_Text' => null,
                'Is_Active' => true,
                'Created_Date' => now(),
                'Modified_Date' => now(),
            ],
        ];

        foreach ($templates as $template) {
            DB::table('email_templates')->updateOrInsert(
                ['Template_Code' => $template['Template_Code']],
                $template
            );
        }
    }

    private function getSoaPdfTemplate()
    {
        return <<<'HTML'
<div style="padding: 40px; font-family: Arial, sans-serif; font-size: 12px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="margin: 0; color: #333;">STATEMENT OF ACCOUNT</h1>
        <p style="margin: 5px 0; color: #666;">{{Statement_Date}}</p>
    </div>

    <div style="margin-bottom: 20px;">
        <strong>Account Number:</strong> {{Account_No}}<br>
        <strong>Customer Name:</strong> {{Full_Name}}<br>
        <strong>Address:</strong> {{Address}}<br>
        <strong>Contact:</strong> {{Contact_No}}<br>
        <strong>Email:</strong> {{Email}}
    </div>

    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <tr style="background: #f0f0f0;">
            <th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Description</th>
            <th style="padding: 10px; text-align: right; border: 1px solid #ddd;">Amount</th>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Previous Balance</td>
            <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">₱ {{Prev_Balance}}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Payment Received</td>
            <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">(₱ {{Prev_Payment}})</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Remaining Balance</td>
            <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">₱ {{Rem_Balance}}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Monthly Service Fee</td>
            <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">₱ {{Monthly_Fee}}</td>
        </tr>
        {{Row_Service}}
        {{Row_Staggered}}
        {{Row_Discounts}}
        {{Row_Rebates}}
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">VAT (12%)</td>
            <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">₱ {{VAT}}</td>
        </tr>
        <tr style="background: #f0f0f0; font-weight: bold;">
            <td style="padding: 10px; border: 1px solid #ddd;">TOTAL AMOUNT DUE</td>
            <td style="padding: 10px; text-align: right; border: 1px solid #ddd;">₱ {{Total_Due}}</td>
        </tr>
    </table>

    <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107;">
        <strong>Due Date:</strong> {{Due_Date}}<br>
        <strong>Plan:</strong> {{Plan}}
    </div>

    <div style="margin-top: 20px; text-align: center; font-size: 11px; color: #666;">
        <p>Pay online at: {{Payment_Link}}</p>
        <p>Thank you for your business!</p>
    </div>
</div>
HTML;
    }

    private function getInvoicePdfTemplate()
    {
        return <<<'HTML'
<div style="padding: 40px; font-family: Arial, sans-serif; font-size: 12px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="margin: 0; color: #333;">INVOICE</h1>
        <p style="margin: 5px 0; color: #666;">{{Statement_Date}}</p>
    </div>

    <div style="margin-bottom: 20px;">
        <strong>Invoice Number:</strong> {{Invoice_No}}<br>
        <strong>Account Number:</strong> {{Account_No}}<br>
        <strong>Customer Name:</strong> {{Full_Name}}<br>
        <strong>Address:</strong> {{Address}}<br>
        <strong>Contact:</strong> {{Contact_No}}<br>
        <strong>Email:</strong> {{Email}}
    </div>

    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <tr style="background: #f0f0f0;">
            <th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Description</th>
            <th style="padding: 10px; text-align: right; border: 1px solid #ddd;">Amount</th>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd;">Service Fee</td>
            <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">₱ {{Invoice_Balance}}</td>
        </tr>
        <tr style="background: #f0f0f0; font-weight: bold;">
            <td style="padding: 10px; border: 1px solid #ddd;">TOTAL AMOUNT</td>
            <td style="padding: 10px; text-align: right; border: 1px solid #ddd;">₱ {{Total_Amount}}</td>
        </tr>
    </table>

    <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107;">
        <strong>Due Date:</strong> {{Due_Date}}<br>
        <strong>Plan:</strong> {{Plan}}
    </div>

    <div style="margin-top: 20px; text-align: center; font-size: 11px; color: #666;">
        <p>Pay online at: {{Payment_Link}}</p>
        <p>Thank you for your business!</p>
    </div>
</div>
HTML;
    }

    private function getSoaEmailTemplate()
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 20px; font-family: Arial, sans-serif; background-color: #f5f5f5;">
    <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #333; margin: 0;">Statement of Account</h1>
        </div>

        <p style="color: #666; line-height: 1.6;">Dear {{customer_name}},</p>

        <p style="color: #666; line-height: 1.6;">
            Your Statement of Account for account <strong>{{account_no}}</strong> is now available.
        </p>

        <div style="background-color: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <p style="margin: 10px 0; color: #333;">
                <strong>Total Amount Due:</strong> ₱{{total_amount}}
            </p>
            <p style="margin: 10px 0; color: #333;">
                <strong>Due Date:</strong> {{due_date}}
            </p>
            <p style="margin: 10px 0; color: #333;">
                <strong>Plan:</strong> {{plan}}
            </p>
        </div>

        <p style="color: #666; line-height: 1.6;">
            Please find your detailed Statement of Account attached to this email.
        </p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{google_drive_url}}" style="display: inline-block; padding: 12px 30px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                View SOA
            </a>
        </div>

        <p style="color: #666; line-height: 1.6; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            If you have any questions, please contact us at {{contact_no}}.
        </p>
    </div>
</body>
</html>
HTML;
    }

    private function getInvoiceEmailTemplate()
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 20px; font-family: Arial, sans-serif; background-color: #f5f5f5;">
    <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #333; margin: 0;">Invoice</h1>
        </div>

        <p style="color: #666; line-height: 1.6;">Dear {{customer_name}},</p>

        <p style="color: #666; line-height: 1.6;">
            Your Invoice for account <strong>{{account_no}}</strong> is now available.
        </p>

        <div style="background-color: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <p style="margin: 10px 0; color: #333;">
                <strong>Total Amount:</strong> ₱{{total_amount}}
            </p>
            <p style="margin: 10px 0; color: #333;">
                <strong>Due Date:</strong> {{due_date}}
            </p>
            <p style="margin: 10px 0; color: #333;">
                <strong>Plan:</strong> {{plan}}
            </p>
        </div>

        <p style="color: #666; line-height: 1.6;">
            Please find your detailed Invoice attached to this email.
        </p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{google_drive_url}}" style="display: inline-block; padding: 12px 30px; background-color: #28a745; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                View Invoice
            </a>
        </div>

        <p style="color: #666; line-height: 1.6; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            If you have any questions, please contact us at {{contact_no}}.
        </p>
    </div>
</body>
</html>
HTML;
    }
}
