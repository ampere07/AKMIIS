<?php

namespace App\Http\Controllers;

use App\Models\StatementOfAccount;
use App\Models\Invoice;
use App\Models\BillingAccount;
use App\Services\InstallationFeeNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Billing Records Controller
 * 
 * Handles direct fetching of SOA and Invoice records from database tables
 * Separate from BillingGenerationController to avoid conflicts
 */
class BillingRecordsController extends Controller
{
    /**
     * Get all Statement of Account records
     * Fetches directly from statement_of_accounts table
     */
    public function getSOARecords(Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            // Pagination parameters - smaller default for faster loading
            $perPage = $request->get('per_page', 50); // Reduced to 50 for faster response
            $page = $request->get('page', 1);
            $fastMode = $request->get('fast', false); // Fast mode: skip customer data loading
            
            // Build base query - use simple query for fast mode, joins for normal mode
            if ($fastMode) {
                // Fast mode: Direct query without joins
                $query = StatementOfAccount::query();
            } else {
                // Normal mode: Include joins for customer data
                $query = StatementOfAccount::query()
                    ->select('statement_of_accounts.*')
                    ->leftJoin('billing_accounts', 'statement_of_accounts.account_no', '=', 'billing_accounts.account_no')
                    ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id');
            }

            if (!$isSuperAdmin && $organizationId) {
                $query->where($fastMode ? 'organization_id' : 'statement_of_accounts.organization_id', $organizationId);
            }
            
            // Filter by account number
            if ($request->has('account_no')) {
                $query->where($fastMode ? 'account_no' : 'statement_of_accounts.account_no', $request->account_no);
            }
            
            if ($request->has('account_id')) {
                $query->where($fastMode ? 'account_no' : 'statement_of_accounts.account_no', $request->account_id);
            }
            
            // Filter by date range
            if ($request->has('date_from') && $request->has('date_to')) {
                $query->whereBetween($fastMode ? 'statement_date' : 'statement_of_accounts.statement_date', [
                    $request->date_from,
                    $request->date_to
                ]);
            }
            
            // Filter by recently updated
            if ($request->has('updated_since')) {
                $query->where($fastMode ? 'updated_at' : 'statement_of_accounts.updated_at', '>=', date('Y-m-d H:i:s', strtotime($request->updated_since)));
            }

            
            // Calculate total count based on filtered query
            $totalCount = (clone $query)->count();

            // Fetch one extra record to check if there are more pages (more efficient than COUNT)
            $statements = $query
                ->orderBy('statement_of_accounts.statement_date', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage + 1) // Fetch one extra
                ->get();
            
            // Check if there are more pages
            $hasMore = $statements->count() > $perPage;
            
            // Remove the extra record if it exists
            if ($hasMore) {
                $statements = $statements->slice(0, $perPage);
            }
            
            // Fast mode: Return data immediately without customer details
            if ($fastMode) {
                $statementsData = $statements->map(function($statement) {
                    return $statement->toArray();
                });
                
                Log::info('Fetched SOA records (fast mode)', [
                    'count' => $statementsData->count(),
                    'page' => $page,
                    'has_more' => $hasMore
                ]);
                
                return response()->json([
                    'success' => true,
                    'data' => $statementsData->values(),
                    'count' => $statementsData->count(),
                    'total' => $totalCount, // Use filtered count
                    'pagination' => [
                        'current_page' => (int)$page,
                        'per_page' => (int)$perPage,
                        'has_more' => $hasMore
                    ],
                    'fast_mode' => true
                ]);
            }
            
            // Normal mode: Fetch all related data in bulk to avoid N+1 queries
            $accountNos = $statements->pluck('account_no')->unique()->toArray();
            
            // Bulk fetch accounts with customers
            $accounts = BillingAccount::whereIn('account_no', $accountNos)
                ->with('customer')
                ->get()
                ->keyBy('account_no');
            
            // Convert to array and attach account and customer data
            $statementsData = $statements->map(function($statement) use ($accounts) {
                $data = $statement->toArray();
                $account = $accounts->get($statement->account_no);
                
                if ($account) {
                    $data['account'] = [
                        'account_no' => $account->account_no,
                        'date_installed' => $account->date_installed,
                        'billing_day' => $account->billing_day,
                    ];
                    
                    if ($account->customer) {
                        $data['account']['customer'] = [
                            'full_name' => $account->customer->full_name ?? '',
                            'contact_number_primary' => $account->customer->contact_number_primary ?? '',
                            'email_address' => $account->customer->email_address ?? '',
                            'address' => $account->customer->address ?? '',
                            'desired_plan' => $account->customer->desired_plan ?? '',
                            'barangay' => $account->customer->barangay ?? '',
                            'city' => $account->customer->city ?? '',
                            'region' => $account->customer->region ?? '',
                        ];
                    }
                }
                
                return $data;
            });
            
            Log::info('Fetched SOA records successfully', [
                'count' => $statementsData->count(),
                'page' => $page,
                'has_more' => $hasMore
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $statementsData->values(), // Reset array keys
                'count' => $statementsData->count(),
                'total' => $totalCount, // Use filtered count
                'pagination' => [
                    'current_page' => (int)$page,
                    'per_page' => (int)$perPage,
                    'has_more' => $hasMore
                ],
                'fast_mode' => false
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching SOA records: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch SOA records',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all Invoice records
     * Fetches directly from invoices table
     */
    public function getInvoiceRecords(Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            // Pagination parameters - smaller default for faster loading
            $perPage = $request->get('per_page', 50); // Reduced to 50 for faster response
            $page = $request->get('page', 1);
            $fastMode = $request->get('fast', false); // Fast mode: skip customer data loading
            
            // Base query - direct fetch for both modes since we load relations manually
            $query = Invoice::query()
                ->select('invoices.*')
                ->leftJoin('billing_accounts', 'invoices.account_no', '=', 'billing_accounts.account_no')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id');

            if (!$isSuperAdmin && $organizationId) {
                $query->where('invoices.organization_id', $organizationId);
            }
            
            // Filter by account number
            if ($request->has('account_no')) {
                $query->where('account_no', $request->account_no);
            }
            
            if ($request->has('account_id')) {
                $query->where('account_no', $request->account_id);
            }
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by date range
            if ($request->has('date_from') && $request->has('date_to')) {
                $query->whereBetween('invoice_date', [
                    $request->date_from,
                    $request->date_to
                ]);
            }
            
            // Calculate total count based on filtered query
            $totalCount = (clone $query)->count();

            // Fetch one extra record to check if there are more pages (more efficient than COUNT)
            $invoices = $query
                ->orderBy('invoice_date', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage + 1) // Fetch one extra
                ->get();
            
            // Check if there are more pages
            $hasMore = $invoices->count() > $perPage;
            
            // Remove the extra record if it exists
            if ($hasMore) {
                $invoices = $invoices->slice(0, $perPage);
            }
            
            // Fast mode: Return data immediately without customer details
            if ($fastMode) {
                $invoicesData = $invoices->map(function($invoice) {
                    return $invoice->toArray();
                });
                
                Log::info('Fetched invoice records (fast mode)', [
                    'count' => $invoicesData->count(),
                    'page' => $page,
                    'has_more' => $hasMore
                ]);
                
                return response()->json([
                    'success' => true,
                    'data' => $invoicesData->values(),
                    'count' => $invoicesData->count(),
                    'total' => $totalCount, // Use filtered count
                    'pagination' => [
                        'current_page' => (int)$page,
                        'per_page' => (int)$perPage,
                        'has_more' => $hasMore
                    ],
                    'fast_mode' => true
                ]);
            }
            
            // Normal mode: Fetch all related data in bulk to avoid N+1 queries
            $accountNos = $invoices->pluck('account_no')->unique()->toArray();
            
            // Bulk fetch accounts with customers
            $accounts = BillingAccount::whereIn('account_no', $accountNos)
                ->with('customer')
                ->get()
                ->keyBy('account_no');
            
            // Convert to array and attach account and customer data
            $invoicesData = $invoices->map(function($invoice) use ($accounts) {
                $data = $invoice->toArray();
                $account = $accounts->get($invoice->account_no);
                
                if ($account) {
                    $data['account'] = [
                        'account_no' => $account->account_no,
                        'date_installed' => $account->date_installed,
                        'billing_day' => $account->billing_day,
                    ];
                    
                    if ($account->customer) {
                        $data['account']['customer'] = [
                            'full_name' => $account->customer->full_name ?? '',
                            'contact_number_primary' => $account->customer->contact_number_primary ?? '',
                            'email_address' => $account->customer->email_address ?? '',
                            'address' => $account->customer->address ?? '',
                            'desired_plan' => $account->customer->desired_plan ?? '',
                            'barangay' => $account->customer->barangay ?? '',
                            'city' => $account->customer->city ?? '',
                            'region' => $account->customer->region ?? '',
                        ];
                    }
                }
                
                return $data;
            });
            
            Log::info('Fetched invoice records successfully', [
                'count' => $invoicesData->count(),
                'page' => $page,
                'has_more' => $hasMore
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $invoicesData->values(), // Reset array keys
                'count' => $invoicesData->count(),
                'total' => $totalCount, // Use filtered count
                'pagination' => [
                    'current_page' => (int)$page,
                    'per_page' => (int)$perPage,
                    'has_more' => $hasMore
                ],
                'fast_mode' => false
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching invoice records: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch invoice records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all Job Order (installation fee) invoice records
     *
     * A job order bills its installation fee when it is approved
     * (InstallationFeeNotificationService): numbered JO-{id}, dated and due on the install
     * date. invoice_url is the PDF on Google Drive; job orders completed before the PDF was
     * introduced have none.
     */
    public function getJobOrderInvoiceRecords(Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $query = DB::table('job_orders as jo')
                ->leftJoin('applications as app', 'app.id', '=', 'jo.application_id')
                ->leftJoin('billing_accounts as ba', 'ba.id', '=', 'jo.account_id')
                ->leftJoin('customers as c', 'c.id', '=', 'ba.customer_id')
                ->where(function ($q) {
                    $q->where(function ($done) {
                        $done->where('jo.onsite_status', 'Done')->where('jo.installation_fee', '>', 0);
                    })->orWhere(function ($withPdf) {
                        $withPdf->whereNotNull('jo.invoice_url')->where('jo.invoice_url', '<>', '');
                    });
                })
                ->select([
                    'jo.id', 'jo.installation_fee', 'jo.date_installed', 'jo.onsite_status', 'jo.invoice_url',
                    'jo.organization_id', 'jo.created_at', 'jo.updated_at', 'ba.account_no',
                    'app.first_name as app_first_name', 'app.middle_initial as app_middle_initial', 'app.last_name as app_last_name',
                    'app.mobile_number as app_mobile_number', 'app.email_address as app_email_address',
                    'app.installation_address as app_installation_address', 'app.location as app_location',
                    'app.barangay as app_barangay', 'app.city as app_city', 'app.region as app_region',
                    'app.desired_plan as app_desired_plan',
                    'c.first_name as c_first_name', 'c.middle_initial as c_middle_initial', 'c.last_name as c_last_name',
                    'c.contact_number_primary as c_contact_number', 'c.email_address as c_email_address',
                    'c.address as c_address', 'c.location as c_location',
                    'c.barangay as c_barangay', 'c.city as c_city', 'c.region as c_region',
                    'c.desired_plan as c_desired_plan',
                ]);

            if (!$isSuperAdmin && $organizationId) {
                $query->where('jo.organization_id', $organizationId);
            }

            $records = $query->orderBy('jo.id', 'desc')->get()->map(function ($row) {
                // The application is what the invoice PDF is built from; the customer covers
                // job orders whose application is missing.
                $hasApp = $row->app_first_name !== null || $row->app_last_name !== null;
                $pick = fn ($appValue, $customerValue) => $hasApp ? $appValue : $customerValue;

                $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
                    $pick($row->app_first_name, $row->c_first_name),
                    $pick($row->app_middle_initial, $row->c_middle_initial),
                    $pick($row->app_last_name, $row->c_last_name),
                ]))));

                $barangay = $pick($row->app_barangay, $row->c_barangay);
                $city = $pick($row->app_city, $row->c_city);
                $region = $pick($row->app_region, $row->c_region);
                $address = implode(', ', array_filter([
                    $pick($row->app_installation_address, $row->c_address),
                    $pick($row->app_location, $row->c_location),
                    $barangay,
                    $city,
                    $region,
                ]));

                $plan = $pick($row->app_desired_plan, $row->c_desired_plan);
                if ($plan && strpos($plan, ' - P') !== false) {
                    $plan = trim(explode(' - P', $plan)[0]);
                }

                $invoiceDate = $row->date_installed ? Carbon::parse($row->date_installed) : null;

                return [
                    'id' => $row->id,
                    'invoice_no' => 'JO-' . $row->id,
                    'account_no' => $row->account_no,
                    'invoice_date' => $invoiceDate ? $invoiceDate->format('Y-m-d H:i:s') : null,
                    'due_date' => $invoiceDate ? InstallationFeeNotificationService::dueDate($invoiceDate)->format('Y-m-d') : null,
                    'installation_fee' => (float) $row->installation_fee,
                    'full_name' => $fullName,
                    'contact_number' => $pick($row->app_mobile_number, $row->c_contact_number),
                    'email_address' => $pick($row->app_email_address, $row->c_email_address),
                    'address' => $address,
                    'barangay' => $barangay,
                    'city' => $city,
                    'region' => $region,
                    'plan' => $plan,
                    'onsite_status' => $row->onsite_status,
                    'invoice_url' => $row->invoice_url ?: null,
                    'organization_id' => $row->organization_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $records->values(),
                'total' => $records->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching job order invoice records: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch job order invoice records',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}


