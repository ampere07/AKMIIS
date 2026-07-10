<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserOnlySeeder extends Seeder
{
    public function run(): void
    {
        // Check if admin user already exists
        $existingUser = DB::table('users')->where('username', 'admin')->first();
        
        if ($existingUser) {
            echo "Admin user already exists!\n";
            echo "Username: admin\n";
            return;
        }

        // Get or create organization
        $organization = DB::table('organizations')->where('organization_name', 'Ampere Cloud')->first();
        if (!$organization) {
            $organizationId = DB::table('organizations')->insertGetId([
                'organization_name' => 'Ampere Cloud',
                'address' => 'Angono, Rizal, Philippines',
                'contact_number' => '+63-XXX-XXX-XXXX',
                'email_address' => 'info@amperecloud.com',
                'created_at' => now(),
                'created_by_user_id' => null,
                'updated_at' => now(),
                'updated_by_user_id' => null,
            ]);
        } else {
            $organizationId = $organization->id;
        }

        // Get or create role
        $role = DB::table('roles')->where('role_name', 'Administrator')->first();
        if (!$role) {
            $roleId = DB::table('roles')->insertGetId([
                'role_name' => 'Administrator',
                'description' => 'System Administrator with full access',
                'created_at' => now(),
                'created_by_user_id' => null,
                'updated_at' => now(),
                'updated_by_user_id' => null,
            ]);
        } else {
            $roleId = $role->id;
        }

        // Get or create group
        $group = DB::table('group_list')->where('group_name', 'Main Office')->first();
        if (!$group) {
            $groupId = DB::table('group_list')->insertGetId([
                'group_name' => 'Main Office',
                'fb_page_link' => null,
                'fb_messenger_link' => null,
                'template' => null,
                'company_name' => 'Ampere Cloud',
                'portal_url' => null,
                'hotline' => null,
                'email' => 'support@amperecloud.com',
                'modified_by_user_id' => null,
                'modified_date' => now(),
            ]);
        } else {
            $groupId = $group->id;
        }

        // Create admin user using bcrypt directly (DB::table bypasses Eloquent mutators)
        DB::table('users')->insert([
            'username' => 'admin',
            'password_hash' => bcrypt('admin123'),
            'email_address' => 'admin@amperecloud.com',
            'first_name' => 'System',
            'middle_initial' => 'A',
            'last_name' => 'Administrator',
            'contact_number' => '+63-XXX-XXX-XXXX',
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'group_id' => $groupId,
            'status' => 'active',
            'last_login' => null,
            'created_at' => now(),
            'created_by_user_id' => null,
            'updated_at' => now(),
            'updated_by_user_id' => null,
        ]);

        echo "Admin user created successfully!\n";
        echo "==============================\n";
        echo "Login Credentials:\n";
        echo "Username: admin\n";
        echo "Password: admin123\n";
        echo "==============================\n";
    }
}
