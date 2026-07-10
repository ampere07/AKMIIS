<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create default organization
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

        // Create default role
        $roleId = DB::table('roles')->insertGetId([
            'role_name' => 'Administrator',
            'description' => 'System Administrator with full access',
            'created_at' => now(),
            'created_by_user_id' => null,
            'updated_at' => now(),
            'updated_by_user_id' => null,
        ]);

        // Create default group
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

        // Create billing status
        DB::table('billing_status')->insert([
            ['status_name' => 'Active', 'created_at' => now(), 'created_by_user_id' => null],
            ['status_name' => 'Disconnected', 'created_at' => now(), 'created_by_user_id' => null],
            ['status_name' => 'Suspended', 'created_at' => now(), 'created_by_user_id' => null],
            ['status_name' => 'Pending', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create usage types
        DB::table('usage_type')->insert([
            ['usage_name' => 'Residential', 'created_at' => now(), 'created_by_user_id' => null],
            ['usage_name' => 'Business', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create payment methods
        DB::table('payment_methods')->insert([
            ['payment_method' => 'Cash', 'created_at' => now(), 'created_by_user_id' => null],
            ['payment_method' => 'Bank Transfer', 'created_at' => now(), 'created_by_user_id' => null],
            ['payment_method' => 'GCash', 'created_at' => now(), 'created_by_user_id' => null],
            ['payment_method' => 'PayMaya', 'created_at' => now(), 'created_by_user_id' => null],
            ['payment_method' => 'Credit Card', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create sample plan
        DB::table('plan_list')->insert([
            'plan_name' => 'Basic Plan 25Mbps',
            'description' => 'Basic internet plan with 25Mbps speed',
            'price' => 999.00,
            'group_id' => $groupId,
            'modified_by_user' => null,
            'modified_date' => now(),
        ]);

        // Create inventory category
        DB::table('inventory_category')->insert([
            ['category_name' => 'Routers', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Cables', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Tools', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create expenses category
        DB::table('expenses_category')->insert([
            ['category_name' => 'Equipment Purchase', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Utilities', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Maintenance', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Salaries', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create support concerns
        DB::table('support_concern')->insert([
            ['concern_name' => 'No Internet Connection', 'created_at' => now(), 'created_by_user_id' => null],
            ['concern_name' => 'Slow Connection', 'created_at' => now(), 'created_by_user_id' => null],
            ['concern_name' => 'Intermittent Connection', 'created_at' => now(), 'created_by_user_id' => null],
            ['concern_name' => 'Router Issue', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create repair categories
        DB::table('repair_category')->insert([
            ['category_name' => 'Router Replacement', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Cable Repair', 'created_at' => now(), 'created_by_user_id' => null],
            ['category_name' => 'Port Change', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        // Create status remarks
        DB::table('status_remarks_list')->insert([
            ['status_remarks' => 'For Site Survey', 'created_at' => now(), 'created_by_user_id' => null],
            ['status_remarks' => 'For Installation', 'created_at' => now(), 'created_by_user_id' => null],
            ['status_remarks' => 'Installed', 'created_at' => now(), 'created_by_user_id' => null],
            ['status_remarks' => 'Cancelled', 'created_at' => now(), 'created_by_user_id' => null],
        ]);

        echo "Initial data seeded successfully!\n";
        echo "Login Credentials:\n";
        echo "Username: admin\n";
        echo "Password: admin123\n";
    }
}
