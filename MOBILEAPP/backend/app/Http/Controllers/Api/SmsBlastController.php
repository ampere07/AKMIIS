<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SmsBlastLog;
use App\Models\SmsConfig;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class SmsBlastController extends Controller
{
    public function index(Request $request)
    {
        try {
            $currentUser = Auth::user();
            $query = DB::table('sms_blast_logs')
                ->leftJoin('barangay', 'sms_blast_logs.barangay_id', '=', 'barangay.id')
                ->leftJoin('city', 'barangay.city_id', '=', 'city.id')
                ->leftJoin('lcpnap', 'sms_blast_logs.lcpnap_id', '=', 'lcpnap.id')
                ->leftJoin('lcp', 'sms_blast_logs.lcp_id', '=', 'lcp.id')
                ->leftJoin('users as creator', 'sms_blast_logs.created_by_user_id', '=', 'creator.id')
                ->leftJoin('users as updater', 'sms_blast_logs.updated_by_user_id', '=', 'updater.id')
                ->select(
                    'sms_blast_logs.*',
                    'barangay.barangay as barangay_name',
                    'city.city as city_name',
                    'lcpnap.lcpnap_name',
                    'lcp.lcp_name',
                    'creator.email_address as user_email',
                    'updater.email_address as modified_email'
                );

            // Apply organization filter
            if ($currentUser) {
                if ($currentUser->organization_id) {
                    $query->where('sms_blast_logs.organization_id', $currentUser->organization_id);
                } else {
                    $query->whereNull('sms_blast_logs.organization_id');
                }
            }

            $query->orderBy('sms_blast_logs.created_at', 'desc');

            if ($request->has('barangay') && $request->barangay !== 'All') {
                $query->where('barangay.barangay', $request->barangay);
            }

            if ($request->has('city') && $request->city !== 'All') {
                $query->where('city.city', $request->city);
            }

            $records = $query->get();

            // Format data for frontend
            $data = $records->map(function ($record) {
                // Logic based target selection
                $target = '';
                $type = '';
                
                if (isset($record->barangay_id) && $record->barangay_id > 0) {
                    $target = $record->barangay_name ?? 'Barangay ' . $record->barangay_id;
                    $type = 'Barangay';
                } elseif (isset($record->lcpnap_id) && $record->lcpnap_id > 0) {
                    $target = $record->lcpnap_name ?? 'NAP ' . $record->lcpnap_id;
                    $type = 'LCPNAP';
                } elseif (isset($record->lcp_id) && $record->lcp_id > 0) {
                    $target = $record->lcp_name ?? 'LCP ' . $record->lcp_id;
                    $type = 'LCP';
                } elseif (isset($record->billing_day) && $record->billing_day > 0) {
                    $target = 'Day ' . $record->billing_day;
                    $type = 'Billing Day';
                }

                if (empty($target)) $target = 'N/A';
                if (empty($type)) $type = 'N/A';

                return [
                    'id' => (string)$record->id,
                    'target_name' => $target,
                    'target_type' => $type,
                    'barangay' => $record->barangay_name ?? 'N/A',
                    'city' => $record->city_name ?? 'N/A',
                    'message' => $record->message,
                    'billing_day' => $record->billing_day,
                    'message_count' => $record->message_count,
                    'credit_used' => $record->credit_used,
                    'modifiedDate' => $record->updated_at ? \Carbon\Carbon::parse($record->updated_at)->format('n/j/Y g:i:s A') : ($record->created_at ? \Carbon\Carbon::parse($record->created_at)->format('n/j/Y g:i:s A') : 'N/A'),
                    'modifiedEmail' => $record->modified_email ?? $record->user_email ?? 'N/A',
                    'userEmail' => $record->user_email ?? 'N/A',
                    'organization_id' => $record->organization_id
                ];
            });

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $currentUser = Auth::user();
            $validated = $request->validate([
                'message' => 'required|string',
                'barangay_id' => 'nullable|integer',
                'lcpnap_id' => 'nullable|integer',
                'lcp_id' => 'nullable|integer',
                'billing_day' => 'nullable|integer',
            ]);

            $recipients = collect();

            if (!empty($validated['barangay_id'])) {
                // Get the barangay name from barangay table
                $barangay = DB::table('barangay')->where('id', $validated['barangay_id'])->first();
                if ($barangay) {
                    $customerQuery = DB::table('customers')
                        ->join('billing_accounts', 'customers.id', '=', 'billing_accounts.customer_id')
                        ->where('customers.barangay', $barangay->barangay)
                        ->where('billing_accounts.billing_status_id', 1)
                        ->whereNotNull('customers.contact_number_primary');
                    
                    if ($currentUser && $currentUser->organization_id) {
                        $customerQuery->where('customers.organization_id', $currentUser->organization_id);
                    }
                    
                    $recipients = $customerQuery->select('customers.contact_number_primary as contact_no', 'customers.id as customer_id')
                        ->get();
                }
            } elseif (!empty($validated['lcp_id'])) {
                $lcp = DB::table('lcp')->where('id', $validated['lcp_id'])->first();
                if ($lcp) {
                    $techQuery = DB::table('technical_details')
                        ->join('billing_accounts', 'technical_details.account_no', '=', 'billing_accounts.account_no')
                        ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                        ->where('technical_details.lcp', $lcp->lcp_name)
                        ->where('billing_accounts.billing_status_id', 1)
                        ->whereNotNull('customers.contact_number_primary');
                    
                    if ($currentUser && $currentUser->organization_id) {
                        $techQuery->where('technical_details.organization_id', $currentUser->organization_id);
                    }
                    
                    $recipients = $techQuery->select('customers.contact_number_primary as contact_no', 'customers.id as customer_id')
                        ->get();
                }
            } elseif (!empty($validated['lcpnap_id'])) {
                $lcpnap = DB::table('lcpnap')->where('id', $validated['lcpnap_id'])->first();
                if ($lcpnap) {
                    $napQuery = DB::table('technical_details')
                        ->join('billing_accounts', 'technical_details.account_no', '=', 'billing_accounts.account_no')
                        ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                        ->where('technical_details.lcpnap', $lcpnap->lcpnap_name)
                        ->where('billing_accounts.billing_status_id', 1)
                        ->whereNotNull('customers.contact_number_primary');
                    
                    if ($currentUser && $currentUser->organization_id) {
                        $napQuery->where('technical_details.organization_id', $currentUser->organization_id);
                    }
                    
                    $recipients = $napQuery->select('customers.contact_number_primary as contact_no', 'customers.id as customer_id')
                        ->get();
                }
            } elseif (!empty($validated['billing_day'])) {
                $billingQuery = DB::table('billing_accounts')
                    ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                    ->where('billing_accounts.billing_day', $validated['billing_day'])
                    ->where('billing_accounts.billing_status_id', 1) // Active
                    ->whereNotNull('customers.contact_number_primary');
                
                if ($currentUser && $currentUser->organization_id) {
                    $billingQuery->where('billing_accounts.organization_id', $currentUser->organization_id);
                }
                
                $recipients = $billingQuery->select('customers.contact_number_primary as contact_no', 'customers.id as customer_id')
                    ->get();
            }

            $recipientCount = $recipients->count();

            $smsLog = new SmsBlastLog();
            $smsLog->fill($validated);
            $smsLog->timestamp = now();
            $smsLog->message_count = $recipientCount;
            $smsLog->credit_used = $recipientCount; 
            $smsLog->created_by_user_id = $currentUser->id ?? 1;
            $smsLog->updated_by_user_id = $currentUser->id ?? 1;
            $smsLog->organization_id = $currentUser->organization_id ?? null;
            $smsLog->save();

            // Create Activity Log
            ActivityLog::log(
                'SMS Blast Created',
                "New SMS Blast created with {$recipientCount} recipients. Message: " . substr($validated['message'], 0, 100) . (strlen($validated['message']) > 100 ? '...' : ''),
                'info',
                [
                    'resource_type' => 'SmsBlast',
                    'resource_id' => $smsLog->id,
                    'additional_data' => [
                        'message_count' => $recipientCount,
                        'target_type' => !empty($validated['barangay_id']) ? 'Barangay' : (!empty($validated['lcp_id']) ? 'LCP' : (!empty($validated['lcpnap_id']) ? 'LCPNAP' : (!empty($validated['billing_day']) ? 'Billing Day' : 'Unknown'))),
                        'target_id' => $validated['barangay_id'] ?? $validated['lcp_id'] ?? $validated['lcpnap_id'] ?? $validated['billing_day'] ?? null,
                        'organization_id' => $smsLog->organization_id
                    ]
                ]
            );

            // Send actual SMS via Itexmo
            if ($recipientCount > 0) {
                $smsService = new \App\Services\ItexmoSmsService();
                foreach ($recipients as $recipient) {
                    $smsService->send([
                        'contact_no' => $recipient->contact_no,
                        'message' => $validated['message']
                    ]);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "SMS Blast log created and SMS sent to {$recipientCount} recipients successfully",
                'data' => $smsLog
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process SMS blast: ' . $e->getMessage()
            ], 500);
        }
    }
}
