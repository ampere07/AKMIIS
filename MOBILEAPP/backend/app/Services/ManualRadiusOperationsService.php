<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Throwable;
use Exception;
use App\Models\DisconnectedLog;
use App\Models\ReconnectionLog;

class ManualRadiusOperationsService
{
    private $logName = 'Manual_DCRC';


    /**
     * Disconnect user from RADIUS and update database
     */
    public function disconnectUser(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $username = $params['username'] ?? '';
            $remarks = $params['remarks'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== DISCONNECT USER START ===");
            $this->writeLog("Account: $accountNo | Username: $username | Remarks: $remarks");

            if (empty($username)) {
                throw new Exception("Username is required for disconnect operation");
            }

            // Determine status based on remarks
            $status = ($remarks === "Pullout") ? "Pullout" : "Disconnected";
            $this->writeLog("Status Set: $status");

            // Get RADIUS configurations
            $radiusEndpoints = $this->getRadiusEndpoints();

            // Try to get active session ID before it's gone
            $sessionId = null;
            if (!empty($username)) {
                $sessPath = "/rest/user-manage/session?user=" . urlencode($username);
                foreach ($radiusEndpoints as $endpoint) {
                    $fullUrl = $endpoint['url'] . $sessPath;
                    $sessResult = $this->callApiWithRetry($fullUrl, 'GET', null, $endpoint['username'], $endpoint['password']);
                    if ($sessResult && is_array($sessResult) && isset($sessResult[0]['.id'])) {
                        $sessionId = $sessResult[0]['.id'];
                        $this->writeLog("[SESSION] Found session ID for logging: $sessionId");
                        break;
                    }
                }
            }

            // Perform RADIUS operations
            $radiusApplied = $this->radiusOps(
                $radiusEndpoints,
                $username,
                'Disconnected',
                $status,
                true, // isDisconnectAction
                $accountNo,
                $updatedBy
            );

            if (!$radiusApplied) {
                throw new Exception("Failed to connect to RADIUS server or apply disconnect for user '{$username}'");
            }

            // LOG DISCONNECTION TO DATABASE
            try {
                $billingAccount = null;
                if (!empty($accountNo)) {
                    $billingAccount = DB::table('billing_accounts')->where('account_no', $accountNo)->first();
                }

                if (!$billingAccount && !empty($username)) {
                    $techDetail = DB::table('technical_details')->where('username', $username)->first();
                    if ($techDetail) {
                        $billingAccount = DB::table('billing_accounts')->where('id', $techDetail->account_id)->first();
                    }
                }

                if ($billingAccount) {
                    // Find user ID for created_by/updated_by columns
                    $userId = null;
                    if (!empty($updatedBy) && $updatedBy !== 'System') {
                        $userId = DB::table('users')
                            ->where('username', $updatedBy)
                            ->orWhere('email_address', $updatedBy)
                            ->orWhere('contact_number', $updatedBy)
                            ->value('id');
                    }

                    DisconnectedLog::create([
                        'account_id' => $billingAccount->id,
                        'session_id' => $sessionId ?? null,
                        'username' => $username,
                        'remarks' => $remarks ?: "Manual Disconnect",
                        'created_by_user' => $updatedBy,
                        'updated_by_user' => $updatedBy,
                    ]);
                    $this->writeLog("[DB] Disconnection log entry created for Account: " . ($billingAccount->account_no ?? 'Unknown'));
                }
            } catch (Throwable $dbEx) {
                $this->writeLog("[DB ERROR] Failed to create disconnection log: " . $dbEx->getMessage());
            }

            $this->writeLog("[SUCCESS] User disconnected successfully");
            $this->writeLog("=== DISCONNECT USER END ===");

            return [
                'status' => 'success',
                'message' => 'User disconnected successfully',
                'output' => 'Success: User Disconnected'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("=== DISCONNECT USER END ===");
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'output' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Restrict user from RADIUS and update database
     */
    public function restrictedUser(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $username = $params['username'] ?? '';
            $remarks = $params['remarks'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== RESTRICTED USER START ===");
            $this->writeLog("Account: $accountNo | Username: $username | Remarks: $remarks");

            if (empty($username)) {
                throw new Exception("Username is required for restrict operation");
            }

            // Determine status 
            $status = "Restricted";
            $this->writeLog("Status Set: $status");

            // Get RADIUS configurations
            $radiusEndpoints = $this->getRadiusEndpoints();

            // Try to get active session ID before it's gone
            $sessionId = null;
            if (!empty($username)) {
                $sessPath = "/rest/user-manage/session?user=" . urlencode($username);
                foreach ($radiusEndpoints as $endpoint) {
                    $fullUrl = $endpoint['url'] . $sessPath;
                    $sessResult = $this->callApiWithRetry($fullUrl, 'GET', null, $endpoint['username'], $endpoint['password']);
                    if ($sessResult && is_array($sessResult) && isset($sessResult[0]['.id'])) {
                        $sessionId = $sessResult[0]['.id'];
                        $this->writeLog("[SESSION] Found session ID for logging: $sessionId");
                        break;
                    }
                }
            }

            // Perform RADIUS operations
            $radiusApplied = $this->radiusOps(
                $radiusEndpoints,
                $username,
                'Restricted', // RADIUS profile group
                $status,      // Database status name
                true,         // isDisconnectAction (kicking session to apply profile)
                $accountNo,
                $updatedBy
            );

            if (!$radiusApplied) {
                throw new Exception("Failed to connect to RADIUS server or apply restrict for user '{$username}'");
            }

            // LOG RESTRICTION TO DATABASE
            try {
                $billingAccount = null;
                if (!empty($accountNo)) {
                    $billingAccount = DB::table('billing_accounts')->where('account_no', $accountNo)->first();
                }

                if (!$billingAccount && !empty($username)) {
                    $techDetail = DB::table('technical_details')->where('username', $username)->first();
                    if ($techDetail) {
                        $billingAccount = DB::table('billing_accounts')->where('id', $techDetail->account_id)->first();
                    }
                }

                if ($billingAccount) {
                    DisconnectedLog::create([
                        'account_id' => $billingAccount->id,
                        'session_id' => $sessionId ?? null,
                        'username' => $username,
                        'remarks' => $remarks ?: "Manual Restricted",
                        'created_by_user' => $updatedBy,
                        'updated_by_user' => $updatedBy,
                    ]);
                    $this->writeLog("[DB] Restriction log entry created for Account: " . ($billingAccount->account_no ?? 'Unknown'));
                }
            } catch (Throwable $dbEx) {
                $this->writeLog("[DB ERROR] Failed to create restriction log: " . $dbEx->getMessage());
            }

            $this->writeLog("[SUCCESS] User restricted successfully");
            $this->writeLog("=== RESTRICTED USER END ===");

            return [
                'status' => 'success',
                'message' => 'User restricted successfully',
                'output' => 'Success: User Restricted'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("=== RESTRICTED USER END ===");
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'output' => 'Error: ' . $e->getMessage()
            ];
        }
    }


    /**
     * Reconnect user to RADIUS and update database
     */
    public function reconnectUser(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $username = $params['username'] ?? '';
            $rawPlan = $params['plan'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== RECONNECT USER START ===");
            $this->writeLog("Account: $accountNo | Username: $username | Raw Plan: $rawPlan");

            if (empty($username)) {
                throw new Exception("Username is required for reconnect operation");
            }

            if (empty($rawPlan)) {
                throw new Exception("Plan is required for reconnect operation");
            }

            // Clean plan name (strip price suffix like "SWIFT 1000", "STARTER - P799.00", etc.)
            $cleanPlan = preg_replace('/\s*-\s*(?:P|₱)?\d+.*/i', '', $rawPlan);
            $cleanPlan = preg_replace('/\s+(?:P|₱)?\d+.*/i', '', $cleanPlan);
            $cleanPlan = trim($cleanPlan);
            $this->writeLog("Clean Plan: $cleanPlan");

            // Get RADIUS configurations
            $radiusEndpoints = $this->getRadiusEndpoints();

            // Perform RADIUS operations
            try {
                // MOVE LOGGING HERE to ensure it captures the attempt
                // Global Reconnection Logging
                try {
                    $billingAccount = null;
                    if (!empty($accountNo)) {
                        $billingAccount = DB::table('billing_accounts')->where('account_no', $accountNo)->first();
                    }

                    if (!$billingAccount && !empty($username)) {
                        $techDetail = DB::table('technical_details')->where('username', $username)->first();
                        if ($techDetail) {
                            $billingAccount = DB::table('billing_accounts')->where('id', $techDetail->account_id)->first();
                        }
                    }

                    if ($billingAccount) {
                        // User requested: plan value will be from account_no -> billing_accounts (customer_id) -> customers (desired_plan)
                        $customer = DB::table('customers')->where('id', $billingAccount->customer_id)->first();
                        $desiredPlan = $customer ? $customer->desired_plan : ($rawPlan ?: 'N/A');
                        
                        $planId = null;
                        if ($desiredPlan && $desiredPlan != 'N/A') {
                            $cleanPlanForId = $desiredPlan;
                            if (strpos($desiredPlan, ' - ') !== false) {
                                $cleanPlanForId = explode(' - ', $desiredPlan)[0];
                            }
                            // FIXED: Changed table name from 'plans' to 'plan_list'
                            $planId = DB::table('plan_list')->where('plan_name', $cleanPlanForId)->value('id');
                            if (!$planId) {
                                // Try searching in description if not found in name
                                $planId = DB::table('plan_list')->where('description', 'like', "%{$cleanPlanForId}%")->value('id');
                            }
                        }

                        $reconnectionFee = $params['reconnectionFee'] ?? 0;
                        $remarks = $params['remarks'] ?? 'Auto-Reconnect via RADIUS Service';
                        
                        // Extra check: if it's a Service Order, try to get fee
                        if ($reconnectionFee == 0 && isset($params['serviceOrderId'])) {
                            $reconnectionFee = DB::table('service_orders')->where('id', $params['serviceOrderId'])->value('service_charge') ?? 0;
                        }

                        // Insert into reconnection_logs
                        // Find user ID for created_by/updated_by columns
                        $userId = null;
                        if (!empty($updatedBy) && $updatedBy !== 'System') {
                            $userId = DB::table('users')
                                ->where('username', $updatedBy)
                                ->orWhere('email_address', $updatedBy)
                                ->orWhere('contact_number', $updatedBy)
                                ->value('id');
                        }

                        ReconnectionLog::create([
                            'account_id' => $billingAccount->id,
                            'username' => $username,
                            'plan_id' => $planId,
                            'reconnection_fee' => $reconnectionFee,
                            'remarks' => $remarks,
                            'created_by_user' => $userId ? (string)$userId : $updatedBy,
                            'updated_by_user' => $userId ? (string)$userId : $updatedBy,
                        ]);
                        
                        $this->writeLog("[DB] Reconnection log entry created for Account: " . ($billingAccount->account_no ?? 'Unknown'));
                    } else {
                        $this->writeLog("[WARNING] Could not find billing account for reconnection log (Account: $accountNo, User: $username)");
                    }
                } catch (Throwable $dbEx) {
                    $this->writeLog("[DB ERROR] Failed to create reconnection log: " . $dbEx->getMessage());
                    // Log the full error to help debugging
                    \Log::error("Reconnection Log Insertion Failed: " . $dbEx->getMessage(), [
                        'accountNo' => $accountNo,
                        'username' => $username,
                        'updatedBy' => $updatedBy
                    ]);
                }

                $radiusApplied = $this->radiusOps(
                    $radiusEndpoints,
                    $username,
                    $cleanPlan,
                    'Active',
                    true, // isDisconnectAction
                    $accountNo,
                    $updatedBy
                );

                if (!$radiusApplied) {
                    throw new Exception("Failed to connect to RADIUS server or apply reconnect for user '{$username}'");
                }
            } catch (Throwable $radiusEx) {
                $this->writeLog("[RADIUS ERROR] Operation failed: " . $radiusEx->getMessage());
                throw $radiusEx; // Re-throw to be caught by outer catch (returns error status -> caller queues)
            }

            // NOTE: Email notification is handled by the calling controller (ServiceOrderController / ServiceOrderApiController)
            // to avoid duplicate emails. Do NOT add email sending here.

            $this->writeLog("[SUCCESS] User reconnected successfully");
            $this->writeLog("=== RECONNECT USER END ===");

            return [
                'status' => 'success',
                'message' => 'User reconnected successfully',
                'output' => 'Success: User Reconnected'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("=== RECONNECT USER END ===");
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'output' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update user group/plan in RADIUS (only disconnects if group changes)
     */
    public function updateGroup(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $username = $params['username'] ?? '';
            $rawPlan = $params['plan'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== UPDATE GROUP START ===");
            $this->writeLog("Account: $accountNo | Username: $username | Raw Plan: $rawPlan");

            if (empty($username)) {
                throw new Exception("Username is required for group update operation");
            }

            if (empty($rawPlan)) {
                throw new Exception("Plan is required for group update operation");
            }

            // Clean plan name (strip price suffix like "SWIFT 1000", "STARTER - P799.00", etc.)
            $cleanPlan = preg_replace('/\s*-\s*(?:P|₱)?\d+.*/i', '', $rawPlan);
            $cleanPlan = preg_replace('/\s+(?:P|₱)?\d+.*/i', '', $cleanPlan);
            $cleanPlan = trim($cleanPlan);
            $this->writeLog("Clean Plan: $cleanPlan");

            // Get RADIUS configurations
            $radiusEndpoints = $this->getRadiusEndpoints();

            // Perform RADIUS operations without forcing session disconnect
            $this->radiusOps(
                $radiusEndpoints,
                $username,
                $cleanPlan,
                'Active',
                false, // isDisconnectAction = false
                $accountNo,
                $updatedBy
            );

            $this->writeLog("[SUCCESS] User group updated successfully");
            $this->writeLog("=== UPDATE GROUP END ===");

            return [
                'status' => 'success',
                'message' => 'User group updated successfully',
                'output' => 'Success: User group updated'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("=== UPDATE GROUP END ===");
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'output' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update user credentials in RADIUS and database
     */
    public function updateCredentials(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $oldUsername = $params['username'] ?? '';
            $newUsername = $params['newUsername'] ?? '';
            $newPassword = $params['newPassword'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== UPDATE CREDENTIALS START ===");
            $this->writeLog("Account: $accountNo | Old User: $oldUsername | New User: $newUsername");

            if (empty($oldUsername)) {
                throw new Exception("Current username is required");
            }

            if (empty($newUsername)) {
                throw new Exception("New username is required");
            }

            // Get RADIUS configurations
            $radiusEndpoints = $this->getRadiusEndpoints();

            // Step 1: Update database first (as requested)
            $this->updateDatabaseCredentials(
                $accountNo,
                $oldUsername,
                $newUsername,
                $updatedBy
            );

            // Step 2: Update RADIUS credentials
            $radiusSuccess = $this->updateRadiusCredentials(
                $radiusEndpoints,
                $oldUsername,
                $newUsername,
                $newPassword
            );

            if (!$radiusSuccess) {
                // DB username was already updated, but RADIUS could not be reached/updated.
                // Report failure so the caller queues the RADIUS rename for automatic retry.
                $this->writeLog("[WARNING] Database was updated, but RADIUS update failed. Will be queued for retry.");
                throw new Exception("Failed to connect to RADIUS server or update credentials for user '{$oldUsername}'");
            }

            $this->writeLog("[SUCCESS] Credentials updated successfully");
            $this->writeLog("=== UPDATE CREDENTIALS END ===");

            return [
                'status' => 'success',
                'message' => 'Credentials updated successfully',
                'output' => 'Success: Credentials Updated (RADIUS updated, DB username updated)'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("=== UPDATE CREDENTIALS END ===");
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'output' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Disable user in RADIUS by setting disabled=yes
     */
    public function disabledUser(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $username = $params['username'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== DISABLE USER START ===");
            $this->writeLog("Account: $accountNo | Username: $username");

            if (empty($username)) {
                throw new Exception("Username is required");
            }

            $radiusEndpoints = $this->getRadiusEndpoints();
            $radiusId = null;

            // Step 1: Find user ID in RADIUS
            $userPath = "/rest/user-manage/user/" . urlencode($username);
            foreach ($radiusEndpoints as $endpoint) {
                $fullUrl = $endpoint['url'] . $userPath;
                $result = $this->callApiWithRetry($fullUrl, 'GET', null, $endpoint['username'], $endpoint['password']);
                if ($result && isset($result['.id'])) {
                    $radiusId = $result['.id'];
                    break;
                }
            }

            if (!$radiusId) {
                throw new Exception("User '$username' not found in RADIUS");
            }

            // Step 2: Patch user to set disabled=yes
            $payload = ['disabled' => 'true'];
            $success = false;
            foreach ($radiusEndpoints as $endpoint) {
                $targetUrl = $endpoint['url'] . "/rest/user-manage/user/" . $radiusId;
                $result = $this->callApiWithRetry($targetUrl, 'PATCH', $payload, $endpoint['username'], $endpoint['password']);
                if ($result !== false) {
                    $success = true;
                    $this->writeLog("[DISABLE] Set disabled=yes for '$username' at {$endpoint['url']}");
                }
            }

            if (!$success) {
                throw new Exception("Failed to update disabled status in RADIUS");
            }

            // Step 3: Kill active session
            $this->killUserSession($radiusEndpoints, $username);

            $this->writeLog("[SUCCESS] User disabled successfully");
            $this->writeLog("=== DISABLE USER END ===");

            return [
                'status' => 'success',
                'message' => 'User disabled successfully',
                'output' => 'Success: User Disabled (disabled=yes)'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("=== DISABLE USER END ===");
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Enable user in RADIUS by setting disabled=no
     */
    public function enabledUser(array $params): array
    {
        try {
            $accountNo = $params['accountNumber'] ?? '';
            $username = $params['username'] ?? '';
            $updatedBy = $params['updatedBy'] ?? 'System';

            $this->writeLog("=== ENABLE USER START ===");
            $this->writeLog("Account: $accountNo | Username: $username");

            if (empty($username)) {
                throw new Exception("Username is required");
            }

            $radiusEndpoints = $this->getRadiusEndpoints();
            $radiusId = null;

            // Step 1: Find user ID in RADIUS
            $userPath = "/rest/user-manage/user/" . urlencode($username);
            foreach ($radiusEndpoints as $endpoint) {
                $fullUrl = $endpoint['url'] . $userPath;
                $result = $this->callApiWithRetry($fullUrl, 'GET', null, $endpoint['username'], $endpoint['password']);
                if ($result && isset($result['.id'])) {
                    $radiusId = $result['.id'];
                    break;
                }
            }

            if (!$radiusId) {
                throw new Exception("User '$username' not found in RADIUS");
            }

            // Step 2: Patch user to set disabled=no
            $payload = ['disabled' => 'false'];
            $success = false;
            foreach ($radiusEndpoints as $endpoint) {
                $targetUrl = $endpoint['url'] . "/rest/user-manage/user/" . $radiusId;
                $result = $this->callApiWithRetry($targetUrl, 'PATCH', $payload, $endpoint['username'], $endpoint['password']);
                if ($result !== false) {
                    $success = true;
                    $this->writeLog("[ENABLE] Set disabled=no for '$username' at {$endpoint['url']}");
                }
            }

            if (!$success) {
                throw new Exception("Failed to update disabled status in RADIUS");
            }

            $this->writeLog("[SUCCESS] User enabled successfully");
            $this->writeLog("=== ENABLE USER END ===");

            return [
                'status' => 'success',
                'message' => 'User enabled successfully',
                'output' => 'Success: User Enabled (disabled=no)'
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("=== ENABLE USER END ===");
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }


    /**
     * Update RADIUS credentials (username and password)
     */
    /**
     * Update RADIUS credentials (username and password)
     */
    public function updateRadiusCredentials(array $radiusEndpoints, string $oldUsername, string $newUsername, ?string $newPassword = null): bool
    {
        $this->writeLog("[CREDENTIALS] Attempting RADIUS update: '$oldUsername' -> '$newUsername'");

        $totalSuccessCount = 0;

        foreach ($radiusEndpoints as $index => $endpoint) {
            $serverName = "Server #" . ($index + 1) . " ({$endpoint['url']})";
            $this->writeLog("[CREDENTIALS] Processing $serverName");

            // 1. Find the user on THIS specific server to get the correct ID
            $userPath = "/rest/user-manage/user/" . urlencode($oldUsername);
            $findResult = $this->callApiWithRetry($endpoint['url'] . $userPath, 'GET', null, $endpoint['username'], $endpoint['password']);

            if (!$findResult || !isset($findResult['.id'])) {
                $this->writeLog("[CREDENTIALS] [SKIP] User '$oldUsername' not found on $serverName");
                continue;
            }

            $radiusId = $findResult['.id'];
            $targetUrl = $endpoint['url'] . "/rest/user-manage/user/" . $radiusId;

            // 2. DISABLE user temporarily to prevent instant auto-reconnect during rename
            $this->writeLog("[CREDENTIALS] Temporarily disabling user to clear sessions...");
            $this->callApiWithRetry($targetUrl, 'PATCH', ['disabled' => 'true'], $endpoint['username'], $endpoint['password']);

            // 3. KILL active sessions for the OLD username
            $sessPath = "/rest/user-manage/session?user=" . urlencode($oldUsername);
            $sessions = $this->callApiWithRetry($endpoint['url'] . $sessPath, 'GET', null, $endpoint['username'], $endpoint['password']);
            
            if ($sessions && is_array($sessions)) {
                foreach ($sessions as $session) {
                    if (isset($session['.id'])) {
                        $this->callApiWithRetry($endpoint['url'] . "/rest/user-manage/session/" . $session['.id'], 'DELETE', null, $endpoint['username'], $endpoint['password']);
                    }
                }
            }
            
            // Small pause for RADIUS to stabilize
            sleep(1);

            // 4. UPDATE credentials (Rename)
            $payload = ['name' => $newUsername];
            if (!empty($newPassword)) {
                $payload['password'] = $newPassword;
            }

            $this->writeLog("[CREDENTIALS] Applying rename in RADIUS...");
            $patchResult = $this->callApiWithRetry($targetUrl, 'PATCH', $payload, $endpoint['username'], $endpoint['password']);

            // 5. RE-ENABLE the user
            $this->writeLog("[CREDENTIALS] Re-enabling user...");
            $this->callApiWithRetry($targetUrl, 'PATCH', ['disabled' => 'false'], $endpoint['username'], $endpoint['password']);

            if ($patchResult !== false) {
                $this->writeLog("[CREDENTIALS] [SUCCESS] Updated credentials on $serverName");
                $totalSuccessCount++;
            }
        }

        return $totalSuccessCount > 0;
    }

    /**
     * Update database credentials (username only)
     */
    private function updateDatabaseCredentials(string $accountNo, string $oldUsername, string $newUsername, string $updatedBy): void
    {
        $rowsUpdated = 0;
        $accountId = null;

        // PPPoE Username is in technical_details table, not billing_accounts
        if (!empty($accountNo)) {
            $billingAccount = DB::table('billing_accounts')->where('account_no', $accountNo)->first();
            if ($billingAccount) {
                $accountId = $billingAccount->id;
                $rowsUpdated = DB::table('technical_details')
                    ->where('account_id', $billingAccount->id)
                    ->update([
                        'username' => $newUsername,
                        'updated_at' => now(),
                        'updated_by' => $updatedBy
                    ]);

                if ($rowsUpdated > 0) {
                    $this->writeLog("[DB] Updated username via Account No: $accountNo");
                }
            }
        }

        // Fallback to old username in technical_details if account no update didn't happen
        if ($rowsUpdated === 0) {
            $this->writeLog("[DB] Account No update failed/skipped. Trying old username...");
            
            $techDetail = DB::table('technical_details')->where('username', $oldUsername)->first();
            if ($techDetail) {
                $accountId = $techDetail->account_id;
            }

            $rowsUpdated = DB::table('technical_details')
                ->where('username', $oldUsername)
                ->update([
                    'username' => $newUsername,
                    'updated_at' => now(),
                    'updated_by' => $updatedBy
                ]);

            if ($rowsUpdated > 0) {
                $this->writeLog("[DB] Database credentials updated successfully via username");
            }
        }

        // If technical_details was updated successfully, also sync job_orders
        if ($rowsUpdated > 0) {
            if ($accountId) {
                $joUpdated = DB::table('job_orders')
                    ->where('account_id', $accountId)
                    ->update([
                        'pppoe_username' => $newUsername,
                        'username' => $newUsername,
                        'updated_at' => now()
                    ]);
                
                $this->writeLog("[DB] Synced job_orders username & pppoe_username for Account ID: $accountId ($joUpdated rows affected)");
            } else {
                $this->writeLog("[WARNING] Could not determine account_id to sync job_orders table");
            }
        } else {
            $this->writeLog("[WARNING] RADIUS updated, but database update affected 0 rows");
        }
    }

    /**
     * Core RADIUS operations (disconnect/reconnect)
     */
    private function radiusOps(
        array $radiusEndpoints,
        string $username,
        string $targetGroup,
        string $dbStatus,
        bool $isDisconnectAction,
        string $accountNo = '',
        string $updatedBy = 'System'
    ): bool {
        $this->writeLog("[RADIUS OPS] User: $username | Target: $targetGroup | isDC: " . ($isDisconnectAction ? 'Yes' : 'No'));

        $radiusId = null;
        $currentRadiusGroup = null;
        $userPath = "/rest/user-manage/user/" . urlencode($username);

        // Whether the change was actually applied/confirmed on a live RADIUS server.
        // Stays false when the server is unreachable so the caller can queue a retry
        // instead of falsely reporting success.
        $radiusApplied = false;

        // Find user in RADIUS servers
        foreach ($radiusEndpoints as $endpoint) {
            $fullUrl = $endpoint['url'] . $userPath;
            $result = $this->callApiWithRetry(
                $fullUrl,
                'GET',
                null,
                $endpoint['username'],
                $endpoint['password']
            );

            if ($result && isset($result['.id'])) {
                $radiusId = $result['.id'];
                $currentRadiusGroup = $result['group'] ?? '';
                $this->writeLog("[FOUND] RADIUS ID: $radiusId | Current Group: '$currentRadiusGroup'");
                break;
            }
        }

        if ($radiusId) {
            $patchHappened = false;

            // Check if group needs updating
            $needsPatch = ($currentRadiusGroup !== $targetGroup);
            if ($needsPatch) {
                $this->writeLog("[PATCH] Mismatch ($currentRadiusGroup != $targetGroup). Updating group...");
                $payload = ['group' => $targetGroup];

                // FAILOVER LOGIC: Try each server in order, stop on first success
                foreach ($radiusEndpoints as $index => $endpoint) {
                    $this->writeLog("[PATCH] Trying RADIUS server #" . ($index + 1) . ": {$endpoint['url']}");
                    $targetUrl = $endpoint['url'] . "/rest/user-manage/user/" . $radiusId;
                    $result = $this->callApiWithRetry(
                        $targetUrl,
                        'PATCH',
                        $payload,
                        $endpoint['username'],
                        $endpoint['password']
                    );

                    if ($result !== false) {
                        $this->writeLog("[PATCH] Success at {$endpoint['url']} (Server #" . ($index + 1) . ")");
                        $patchHappened = true;
                        break; // Stop on first successful server
                    } else {
                        $this->writeLog("[PATCH] Failed at {$endpoint['url']}, trying next server...");
                    }
                }
            }

            // The operation is considered applied if no patch was needed (already in the
            // desired group) or the patch succeeded on at least one server.
            $radiusApplied = $needsPatch ? $patchHappened : true;

            // Determine if session should be killed
            $shouldKill = $isDisconnectAction || $patchHappened;

            if ($shouldKill) {
                $this->writeLog("[DECISION] Killing session...");
                $this->killUserSession($radiusEndpoints, $username, $patchHappened);
            } else {
                $this->writeLog("[DECISION] No changes needed, keeping session");
            }
        } else {
            // User could not be located on ANY RADIUS server. This happens when every
            // server is unreachable/timing out (connection error) or the user is missing.
            // Either way the change was NOT applied — report failure so it gets queued.
            $this->writeLog("[WARNING] User '$username' not found in RADIUS (server unreachable or user missing)");
        }

        // Update database (local status) regardless — the queue handles RADIUS retry.
        $this->updateDatabaseStatus($accountNo, $username, $dbStatus, $updatedBy);

        return $radiusApplied;
    }

    /**
     * Update database status
     */
    private function updateDatabaseStatus(string $accountNo, string $username, string $dbStatus, string $updatedBy): void
    {
        // Get status ID from billing_status table
        $statusId = DB::table('billing_status')->where('status_name', $dbStatus)->value('id');

        // If status name not found (e.g. "Inactive" or "Restricted"), try "Disconnected" as fallback
        if (!$statusId && in_array($dbStatus, ['Inactive', 'Restricted'])) {
             $statusId = DB::table('billing_status')->where('status_name', 'Disconnected')->value('id');
        }

        if (!$statusId) {
            $this->writeLog("[WARNING] Status '$dbStatus' not found in billing_status table. Database update skipped.");
            return;
        }

        $rowsUpdated = 0;

        // Try via Account No first
        if (!empty($accountNo)) {
            $rowsUpdated = DB::table('billing_accounts')
                ->where('account_no', $accountNo)
                ->update([
                    'billing_status_id' => $statusId,
                    'updated_at' => now(),
                    'updated_by' => $updatedBy
                ]);
        }

        // Fallback to searching technical_details since billing_accounts doesn't have username
        if ($rowsUpdated === 0 && !empty($username)) {
            try {
                $techDetail = DB::table('technical_details')->where('username', $username)->first();
                if ($techDetail) {
                    $rowsUpdated = DB::table('billing_accounts')
                        ->where('id', $techDetail->account_id)
                        ->update([
                            'billing_status_id' => $statusId,
                            'updated_at' => now(),
                            'updated_by' => $updatedBy
                        ]);
                }
            } catch (Throwable $e) {
                $this->writeLog("[DB ERROR] Error updating status via tech details: " . $e->getMessage());
            }
        }

        if ($rowsUpdated > 0) {
            $this->writeLog("[DB] Status updated to: $dbStatus (ID: $statusId)");
        } else {
            $this->writeLog("[WARNING] Database status update affected 0 rows for Account: $accountNo, User: $username");
        }
    }

    /**
     * Kill active user sessions (with failover support)
     */
    private function killUserSession(array $radiusEndpoints, string $username, bool $useFailover = true): void
    {
        $sessPath = "/rest/user-manage/session?user=" . urlencode($username);
        $sessions = null;
        $activeEndpoint = null;

        // Find active sessions
        foreach ($radiusEndpoints as $endpoint) {
            $fullUrl = $endpoint['url'] . $sessPath;
            $result = $this->callApiWithRetry(
                $fullUrl,
                'GET',
                null,
                $endpoint['username'],
                $endpoint['password']
            );

            if ($result && is_array($result)) {
                $sessions = $result;
                $activeEndpoint = $endpoint;
                $this->writeLog("[SESSION] Found " . count($sessions) . " active session(s) on {$endpoint['url']}");
                break;
            }
        }

        if (!$sessions || empty($sessions)) {
            $this->writeLog("[SESSION] No active session found");
            return;
        }

        // Kill sessions - FAILOVER LOGIC: only kill on the server where we found the session
        foreach ($sessions as $session) {
            if (isset($session['.id'])) {
                $sessionId = $session['.id'];
                
                if ($useFailover && $activeEndpoint) {
                    // Only kill on the active endpoint
                    $delUrl = $activeEndpoint['url'] . "/rest/user-manage/session/" . $sessionId;
                    $result = $this->callApiWithRetry(
                        $delUrl,
                        'DELETE',
                        null,
                        $activeEndpoint['username'],
                        $activeEndpoint['password']
                    );
                    if ($result !== false) {
                        $this->writeLog("[KILL] Terminated session ID $sessionId on {$activeEndpoint['url']}");
                    }
                } else {
                    // Legacy: Kill on all endpoints
                    foreach ($radiusEndpoints as $endpoint) {
                        $delUrl = $endpoint['url'] . "/rest/user-manage/session/" . $sessionId;
                        $this->callApiWithRetry(
                            $delUrl,
                            'DELETE',
                            null,
                            $endpoint['username'],
                            $endpoint['password']
                        );
                        $this->writeLog("[KILL] Terminated session ID $sessionId on {$endpoint['url']}");
                    }
                }
            }
        }
    }

    /**
     * Get RADIUS endpoint configurations
     */
    private function getRadiusEndpoints(): array
    {
        $radiusConfigs = DB::table('radius_config')
            ->orderBy('id')
            ->get();

        if ($radiusConfigs->isEmpty()) {
            throw new Exception("No RADIUS configurations found");
        }

        $endpoints = [];
        foreach ($radiusConfigs as $config) {
            $endpoints[] = [
                'url' => "{$config->ssl_type}://{$config->ip}:{$config->port}",
                'username' => $config->username,
                'password' => $config->password
            ];
        }

        return $endpoints;
    }

    /**
     * Call API with retry logic
     * Tries both HTTPS and HTTP protocols per URL (same strategy as RadiusStatusSyncService)
     */
    private function callApiWithRetry(
        string $url,
        string $method,
        ?array $payload,
        string $username,
        string $password,
        int $retries = 1
    ) {
        // Build list of URLs to try: configured protocol first, then alternate
        // This mirrors RadiusStatusSyncService which tries both protocols per config
        $urlsToTry = [$url];
        if (str_starts_with($url, 'https://')) {
            $urlsToTry[] = str_replace('https://', 'http://', $url);
        } elseif (str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $urlsToTry[] = str_replace('http://', 'https://', $url);
        }

        foreach ($urlsToTry as $tryUrl) {
            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                try {
                    $this->writeLog("[API] Attempt $attempt/$retries: $method $tryUrl");

                    $response = Http::withBasicAuth($username, $password)
                        ->connectTimeout(2)
                        ->timeout(4)
                        ->withOptions(['verify' => false]);

                    switch (strtoupper($method)) {
                        case 'GET':
                            $response = $response->get($tryUrl);
                            break;
                        case 'POST':
                            $response = $response->post($tryUrl, $payload);
                            break;
                        case 'PATCH':
                            $response = $response->patch($tryUrl, $payload);
                            break;
                        case 'DELETE':
                            $response = $response->delete($tryUrl);
                            break;
                        default:
                            return false;
                    }

                    if ($response->successful()) {
                        $data = $response->json();
                        return $data;
                    } else {
                        $this->writeLog("[API] HTTP Error {$response->status()}: " . $response->body());
                    }

                } catch (Exception $e) {
                    $this->writeLog("[API] Exception on attempt $attempt: " . $e->getMessage());

                    if ($attempt < $retries) {
                        sleep(1);
                    }
                }
            }
        }

        $this->writeLog("[API] Request failed after trying all protocols and attempts.");
        return false;
    }

    /**
     * Write to log file
     */
    private function writeLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$this->logName}] {$message}";
        
        // Write to custom log file
        $logPath = storage_path('logs/manual_radius_operations.log');
        file_put_contents($logPath, $logMessage . PHP_EOL, FILE_APPEND);
        
        // Mirror errors to the radiusrelated channel for visibility in the Log Viewer
        if (str_contains(strtoupper($message), 'ERROR') || 
            str_contains(strtoupper($message), 'EXCEPTION') || 
            str_contains(strtoupper($message), 'FAILED')) {
            \Log::channel('radiusrelated')->error("[{$this->logName}] {$message}");
        }
        
        // Also log to Laravel default log
        Log::channel('single')->info("[{$this->logName}] {$message}");
    }

    /**
     * Delete user account from RADIUS
     */
    public function deleteAccount(string $username): array
    {
        try {
            $this->writeLog("=== DELETE ACCOUNT START ===");
            $this->writeLog("Username: $username");

            if (empty($username)) {
                throw new Exception("Username is required for delete operation");
            }

            // Get RADIUS configurations
            $radiusEndpoints = $this->getRadiusEndpoints();
            
            $deleteCount = 0;
            foreach ($radiusEndpoints as $endpoint) {
                // Construct path using username directly as requested
                $targetPath = "/rest/user-manage/user/" . urlencode($username);
                $targetUrl = $endpoint['url'] . $targetPath;
                
                $this->writeLog("[DELETE] Calling endpoint: $targetUrl");

                $delResult = $this->callApiWithRetry(
                    $targetUrl,
                    'DELETE',
                    null, // No payload for delete request
                    $endpoint['username'],
                    $endpoint['password']
                );
                
                if ($delResult !== false) {
                    $this->writeLog("[DELETE] Successfully deleted user '$username' from {$endpoint['url']}");
                    $deleteCount++;
                } else {
                    $this->writeLog("[DELETE] Failed to delete user '$username' from {$endpoint['url']} (or user already deleted)");
                }
            }

            $this->writeLog("=== DELETE ACCOUNT END ===");

            return [
                'status' => 'success',
                'message' => "Delete operation completed ($deleteCount servers affected)",
                'delete_count' => $deleteCount
            ];

        } catch (Throwable $e) {
            $this->writeLog("[EXCEPTION] " . $e->getMessage());
            $this->writeLog("=== DELETE ACCOUNT END ===");
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}






