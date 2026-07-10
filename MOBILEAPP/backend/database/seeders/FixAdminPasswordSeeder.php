<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FixAdminPasswordSeeder extends Seeder
{
    public function run(): void
    {
        // Update the admin user's password to the correct hash
        $updated = DB::table('users')
            ->where('username', 'admin')
            ->update([
                'password_hash' => bcrypt('admin123'),
                'updated_at' => now()
            ]);

        if ($updated) {
            echo "Admin password updated successfully!\n";
            echo "==============================\n";
            echo "Login Credentials:\n";
            echo "Username: admin\n";
            echo "Password: admin123\n";
            echo "==============================\n";
        } else {
            echo "No admin user found to update.\n";
        }
    }
}
