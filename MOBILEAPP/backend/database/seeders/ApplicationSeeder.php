<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Application;

class ApplicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $applications = [
            [
                'customer_name' => 'JUMER A REPTIN',
                'timestamp' => '2025-09-18 18:20:09',
                'address' => '0097 J SUMULONG ST SAUDI VILLAGE BRGY LUNSAD BINANGONAN RIZAL, Lunsad, Binangonan, Rizal',
                'status' => 'pending',
                'location' => 'binangonan',
                'email' => 'jumerreptin@example.com',
                'mobile_number' => '09853910967',
                'secondary_number' => '09121488743',
                'visit_date' => '2025-09-19 14:00:00',
                'visit_by' => 'John Denver Dones',
                'visit_with' => 'Leonardo Bayos',
                'notes' => 'Customer requested evening installation',
                'last_modified' => '2025-09-18 16:30:00',
                'modified_by' => 'Admin User'
            ],
            [
                'customer_name' => 'RICHARD RENZ M MEDINA',
                'timestamp' => '2025-09-18 16:00:40',
                'address' => '514 CEQUEÑA COMPOUND SAN VALENTIN BRGY PANTOK BINANGONAN RIZAL, Pantok, Binangonan, Rizal',
                'status' => 'in_progress',
                'location' => 'binangonan',
                'email' => 'richardmedina@example.com',
                'mobile_number' => '09876543210',
                'secondary_number' => '09123456789',
                'visit_date' => '2025-09-19 10:00:00',
                'visit_by' => 'Maria Santos',
                'visit_with' => 'Paulo Reyes',
                'notes' => 'Follow-up on previous installation',
                'last_modified' => '2025-09-18 14:30:00',
                'modified_by' => 'Support Staff'
            ],
            [
                'customer_name' => 'Jann Vince C Enriquez',
                'timestamp' => '2025-09-18 15:22:58',
                'address' => 'Sitio Hulo, Pila-pila, Binangonan, Rizal',
                'status' => 'in_progress',
                'location' => 'binangonan',
                'email' => 'jannvince@example.com',
                'mobile_number' => '09765432109',
                'secondary_number' => '09234567890',
                'visit_date' => '2025-09-19 16:00:00',
                'visit_by' => 'John Denver Dones',
                'visit_with' => 'Leonardo Bayos',
                'notes' => 'Customer requested weekend installation',
                'last_modified' => '2025-09-18 13:45:00',
                'modified_by' => 'Sales Agent'
            ],
            [
                'customer_name' => 'Michelle Liwanag Vergara',
                'timestamp' => '2025-09-18 14:05:00',
                'address' => 'Blk 26 Lot 11 phase 1B Mabuhay Homes Pantok Binangonan, Rizal, Pantok, Binangonan, Rizal',
                'status' => 'duplicate',
                'location' => 'binangonan',
                'email' => 'michelle@example.com',
                'mobile_number' => '09123456789',
                'secondary_number' => '09987654321',
                'visit_date' => '2025-09-20 09:00:00',
                'visit_by' => 'Maria Santos',
                'visit_with' => 'Paulo Reyes',
                'notes' => 'Duplicate entry - refer to application #10',
                'last_modified' => '2025-09-18 15:10:00',
                'modified_by' => 'Support Staff'
            ],
        ];

        foreach ($applications as $application) {
            Application::create($application);
        }
    }
}
