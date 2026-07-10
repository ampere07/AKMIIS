<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    public function index()
    {
        try {
            $user = auth()->user();
            $organizationId = $user ? $user->organization_id : null;

            $query = Role::withCount(['users']);

            if ($organizationId) {
                // Allow system roles (id <= 8) OR roles belonging to the user's organization
                $query->where(function($q) use ($organizationId) {
                    $q->where('id', '<=', 8)
                      ->orWhere('organization_id', $organizationId);
                });
            }

            $roles = $query->get();
            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_name' => 'required|string|max:255|unique:roles',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
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
            $organizationId = $user ? $user->organization_id : null;

            $role = Role::create($request->all() + [
                'created_by_user_id' => $user->id ?? 1,
                'updated_by_user_id' => $user->id ?? 1,
                'organization_id' => $organizationId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $role = Role::with(['users'])->findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $role
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        if ($id <= 8) {
            return response()->json([
                'success' => false,
                'message' => 'System roles cannot be edited'
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'role_name' => 'sometimes|string|max:255|unique:roles,role_name,' . $id,
            'description' => 'sometimes|nullable|string',
            'permissions' => 'sometimes|nullable|array',
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
            $organizationId = $user ? $user->organization_id : null;

            $role = Role::findOrFail($id);

            // Check if user belongs to an organization and if it matches the role's organization
            // System roles (ID <= 8) are already blocked from update above
            if ($organizationId && $role->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update roles within your organization.'
                ], 403);
            }

            // Don't allow organization_id to be changed via update
            $updateData = $request->except('organization_id');

            $role->update($updateData + [
                'updated_by_user_id' => $user->id ?? 1
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        if ($id <= 8) {
            return response()->json([
                'success' => false,
                'message' => 'System roles cannot be deleted'
            ], 403);
        }
        try {
            $user = auth()->user();
            $organizationId = $user ? $user->organization_id : null;

            $role = Role::findOrFail($id);

            // Check if user belongs to an organization and if it matches the role's organization
            // System roles (ID <= 8) are already blocked from delete above
            if ($organizationId && $role->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only delete roles within your organization.'
                ], 403);
            }
            
            // Check if role has users
            if ($role->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete role that has assigned users'
                ], 400);
            }

            $role->delete();

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
