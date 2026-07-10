<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppPlanSeeder extends Seeder
{
    /**
     * Run the database seeds for app_plans table.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic Plan',
                'description' => 'Basic internet plan for home users with 25 Mbps speed',
                'price' => 999.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Premium Plan',
                'description' => 'High-speed internet plan for businesses with 100 Mbps speed',
                'price' => 1999.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Enterprise Plan',
                'description' => 'Ultra-fast internet plan for large enterprises with 500 Mbps speed',
                'price' => 4999.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Student Plan',
                'description' => 'Affordable internet plan for students with 15 Mbps speed',
                'price' => 599.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Family Plan',
                'description' => 'Perfect for families with multiple devices, 50 Mbps speed',
                'price' => 1499.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Starter Plan',
                'description' => 'Entry-level internet plan with 10 Mbps speed',
                'price' => 499.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Business Plan',
                'description' => 'Professional internet plan for small businesses with 75 Mbps speed',
                'price' => 2499.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'Gaming Plan',
                'description' => 'Low-latency internet plan optimized for gaming with 150 Mbps speed',
                'price' => 2999.00,
                'is_active' => true,
                'modified_date' => now(),
                'modified_by' => 'system@ampere.com',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        // Check if app_plans table exists and has data
        try {
            $existingCount = DB::table('app_plans')->count();
            
            if ($existingCount === 0) {
                foreach ($plans as $plan) {
                    DB::table('app_plans')->insert($plan);
                }
                
                echo "Seeded " . count($plans) . " plans into app_plans table.\n";
            } else {
                echo "app_plans table already contains {$existingCount} records. Skipping seeding.\n";
            }
        } catch (\Exception $e) {
            echo "Error seeding app_plans table: " . $e->getMessage() . "\n";
        }
    }
}