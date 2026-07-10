<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run()
    {
        // Ensure we have a default organization
        $organization = Organization::updateOrInsert(
            ['org_id' => 10000001],
            [
                'org_id' => 10000001,
                'org_name' => 'Default Organization',
                'org_type' => 'System',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Ensure we have an Administrator role
        $adminRole = Role::updateOrInsert(
            ['role_id' => 20000001],
            [
                'role_id' => 20000001,
                'role_name' => 'Administrator',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Create default admin user
        $adminUser = User::updateOrInsert(
            ['email' => 'admin@ampere.com'],
            [
                'user_id' => User::generateUserId(),
                'salutation' => 'Mr',
                'full_name' => 'System Administrator',
                'username' => 'admin',
                'email' => 'admin@ampere.com',
                'mobile_number' => null,
                'password_hash' => Hash::make('admin123'),
                'org_id' => 10000001,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Get the actual user instance to assign role
        $user = User::where('email', 'admin@ampere.com')->first();
        if ($user) {
            // Assign Administrator role to admin user
            $user->roles()->syncWithoutDetaching([20000001]);
        }
    }
}
