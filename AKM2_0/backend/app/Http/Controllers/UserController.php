<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Organization;
use App\Models\AgentBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Services\ActivityLogService;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $organizationId = $user ? $user->organization_id : null;
            $roleId = $user ? $user->role_id : null;

            $query = User::with(['organization', 'role', 'agent', 'agentBalance']);
            
            // A Global SuperAdmin must have role_id 7 AND no organization_id
            $isGlobalAdmin = ($roleId == 7 && $organizationId === null);

            if ($isGlobalAdmin) {
                // Global admin can see:
                // 1. Users with no organization
                // 2. Users with an organization ONLY if they are also SuperAdmins (role_id 7)
                $query->where(function($q) {
                    $q->whereNull('organization_id')
                      ->orWhere('role_id', 7);
                });
            } else {
                if ($organizationId) {
                    $query->where('organization_id', $organizationId);
                } else {
                    $query->whereNull('organization_id');
                }
            }

            if ($request->has('role')) {
                $roleName = $request->input('role');
                $query->whereHas('role', function($q) use ($roleName) {
                    $q->where('role_name', 'LIKE', '%' . $roleName . '%');
                });
            }

            if ($request->has('role_id')) {
                $requestedRoleId = $request->input('role_id');
                if (is_array($requestedRoleId)) {
                    $query->whereIn('role_id', $requestedRoleId);
                } elseif (is_string($requestedRoleId) && strpos($requestedRoleId, ',') !== false) {
                    $query->whereIn('role_id', explode(',', $requestedRoleId));
                } else {
                    $query->where('role_id', $requestedRoleId);
                }
            }
            
            $users = $query->get();
            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            \Log::error('Fetch users failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'salutation' => 'nullable|string|max:10|in:Mr,Ms,Mrs,Dr,Prof',
            'first_name' => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:1',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email_address' => 'required|string|email|max:255|unique:users,email_address',
            'contact_number' => 'nullable|string|max:20|regex:/^[+]?[0-9\s\-\(\)]+$/',
            'password' => 'required|string|min:8',
            'organization_id' => 'nullable|integer',
            'role_id' => 'nullable|integer|exists:roles,id',
            'agent_id' => 'nullable|integer|exists:agents,id',
            'active' => 'sometimes|boolean',
            'commission' => 'nullable|numeric|min:0',
            'quota' => 'nullable|numeric|min:0',
            'incentives_value' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            
            $isGlobalAdmin = !$authUser || ($roleId == 7 && $organizationId === null);

            // Generate user ID with proper error handling
            
                $userData = [
                    'salutation' => $request->salutation,
                    'first_name' => $request->first_name,
                    'middle_initial' => $request->middle_initial,
                    'last_name' => $request->last_name,
                    'username' => $request->username,
                    'email_address' => $request->email_address,   // correct field
                    'contact_number' => $request->contact_number, // correct field
                    'password_hash' => $request->password,
                    'organization_id' => $isGlobalAdmin ? ($request->organization_id && $request->organization_id > 0 ? $request->organization_id : null) : $organizationId,
                    'role_id' => $request->role_id && $request->role_id > 0 ? $request->role_id : null,
                    'agent_id' => $request->agent_id && $request->agent_id > 0 ? $request->agent_id : null,
                    'active' => $request->has('active') ? $request->active : 1,
                ];
            
            $user = User::create($userData);
            
            if (!$user) {
                throw new \Exception('Failed to create user');
            }

            $user->load(['organization', 'role', 'agent', 'agentBalance']);

            if ($user->role_id == 4 || ($user->role && strtolower($user->role->role_name) === 'agent')) {
                AgentBalance::updateOrCreate(
                    ['agent_id' => $user->id],
                    [
                        'balance' => 0.00,
                        'commission' => $request->commission ?? 0.00,
                        'quota' => $request->quota ?? 0.00,
                        'incentives_value' => $request->incentives_value ?? 0.00,
                        'remarks' => $request->remarks ?? null,
                    ]
                );
            }

            // Try to log user creation activity (but don't fail if logging fails)
            try {
                ActivityLogService::userCreated(
                    null, // For now, no authenticated user
                    $user,
                    ['created_by' => 'system']
                );
            } catch (\Exception $logError) {
                \Log::warning('Failed to log user creation activity: ' . $logError->getMessage());
            }
            
            $responseUser = $user->load(['organization', 'role', 'agent', 'agentBalance']);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $responseUser
            ], 201);
        } catch (\Exception $e) {
            \Log::error('User creation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isGlobalAdmin = ($roleId == 7 && $organizationId === null);
            
            $user = User::with(['organization', 'role', 'agent', 'agentBalance'])->findOrFail($id);
            
            if (!$isGlobalAdmin) {
                if ($organizationId) {
                    if ($user->organization_id !== $organizationId) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized access to user record.'], 403);
                    }
                } else {
                    if ($user->organization_id !== null) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized access to user record.'], 403);
                    }
                }
            } else {
                // Global admin can only see other org users if they are SuperAdmin
                if ($user->organization_id !== null && $user->role_id != 7) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Global admins can only view users in other organizations if they are SuperAdmins.'], 403);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        // Validate the user ID first
        if (!$id || $id <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user ID provided',
                'error' => 'User ID must be a positive integer'
            ], 400);
        }
        
        $validator = Validator::make($request->all(), [
            'salutation' => 'sometimes|string|max:10|in:Mr,Ms,Mrs,Dr,Prof',
            'first_name' => 'sometimes|string|max:255',
            'middle_initial' => 'sometimes|nullable|string|max:1',
            'last_name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,' . $id . ',id',
            'email_address' => 'sometimes|string|email|max:255|unique:users,email_address,' . $id . ',id',
            'contact_number' => 'sometimes|nullable|string|max:50',
            'password' => 'sometimes|string|min:8',
            'organization_id' => 'sometimes|nullable|integer',
            'role_id' => 'sometimes|nullable|integer|exists:roles,id',
            'agent_id' => 'sometimes|nullable|integer|exists:agents,id',
            'active' => 'sometimes|boolean',
            'commission' => 'sometimes|nullable|numeric|min:0',
            'quota' => 'sometimes|nullable|numeric|min:0',
            'incentives_value' => 'sometimes|nullable|numeric|min:0',
            'remarks' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            
            $isGlobalAdmin = ($roleId == 7 && $organizationId === null);

            $user = User::findOrFail($id);
            
            if (!$isGlobalAdmin) {
                if ($organizationId) {
                    if ($user->organization_id !== $organizationId) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. You can only update users within your organization.'], 403);
                    }
                } else {
                    if ($user->organization_id !== null) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. You can only update users without an organization.'], 403);
                    }
                }
            } else {
                // Global admin can only update other org users if they are SuperAdmin
                if ($user->organization_id !== null && $user->role_id != 7) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Global admins can only update users in other organizations if they are SuperAdmins.'], 403);
                }
            }

            $oldData = $user->toArray();
            $updateData = [];
            
            // Only include fields that are actually in the request
            $fields = ['salutation', 'first_name', 'middle_initial', 'last_name', 'username', 'email_address', 'contact_number', 'active'];
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->input($field);
                }
            }
            
            if ($request->has('password')) {
                $updateData['password_hash'] = $request->password;
            }
            
            // Handle organization_id - only if present in request
            if ($request->has('organization_id')) {
                if ($isGlobalAdmin) {
                    $updateData['organization_id'] = $request->organization_id && $request->organization_id > 0 ? $request->organization_id : null;
                }
            }
            
            // Handle role_id - only if present in request
            if ($request->has('role_id')) {
                $updateData['role_id'] = $request->role_id && $request->role_id > 0 ? $request->role_id : null;
            }

            // Handle agent_id - only if present in request
            if ($request->has('agent_id')) {
                $updateData['agent_id'] = $request->agent_id && $request->agent_id > 0 ? $request->agent_id : null;
            }

            $user->update($updateData);
            $user->load(['organization', 'role', 'agent', 'agentBalance']);

            if ($user->role_id == 4 || ($user->role && strtolower($user->role->role_name) === 'agent')) {
                $balanceData = [];
                if ($request->has('commission')) {
                    $balanceData['commission'] = $request->commission;
                }
                if ($request->has('quota')) {
                    $balanceData['quota'] = $request->quota;
                }
                if ($request->has('incentives_value')) {
                    $balanceData['incentives_value'] = $request->incentives_value;
                }
                if ($request->has('remarks')) {
                    $balanceData['remarks'] = $request->remarks;
                }
                // Check if record exists, if not, initialize balance to 0.00
                if (!AgentBalance::where('agent_id', $user->id)->exists()) {
                    $balanceData['balance'] = 0.00;
                    if (!isset($balanceData['commission'])) {
                        $balanceData['commission'] = 0.00;
                    }
                    if (!isset($balanceData['quota'])) {
                        $balanceData['quota'] = 0.00;
                    }
                    if (!isset($balanceData['incentives_value'])) {
                        $balanceData['incentives_value'] = 0.00;
                    }
                }
                if (!empty($balanceData)) {
                    AgentBalance::updateOrCreate(
                        ['agent_id' => $user->id],
                        $balanceData
                    );
                }
            }

            // Try to log user update activity (but don't fail if logging fails)
            try {
                $changes = array_diff_assoc($updateData, $oldData);
                ActivityLogService::userUpdated(
                    null, // For now, no authenticated user
                    $user,
                    $changes
                );
            } catch (\Exception $logError) {
                \Log::warning('Failed to log user update activity: ' . $logError->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user->load(['organization', 'role', 'agent', 'agentBalance'])
            ]);
        } catch (\Exception $e) {
            \Log::error('User update failed for ID ' . $id . ': ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            
            $isGlobalAdmin = ($roleId == 7 && $organizationId === null);

            $user = User::findOrFail($id);

            if (!$isGlobalAdmin) {
                if ($organizationId) {
                    if ($user->organization_id !== $organizationId) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. You can only delete users within your organization.'], 403);
                    }
                } else {
                    if ($user->organization_id !== null) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. You can only delete users without an organization.'], 403);
                    }
                }
            } else {
                // Global admin can only delete other org users if they are SuperAdmin
                if ($user->organization_id !== null && $user->role_id != 7) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Global admins can only delete users in other organizations if they are SuperAdmins.'], 403);
                }
            }

            $username = $user->username;
            $user->delete();

            // Try to log user deletion activity (but don't fail if logging fails)
            try {
                ActivityLogService::userDeleted(
                    null, // For now, no authenticated user
                    $id,
                    $username
                );
            } catch (\Exception $logError) {
                \Log::warning('Failed to log user deletion activity: ' . $logError->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePushToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'push_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $user->push_token = $request->push_token;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Push token updated successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to update push token: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update push token',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}