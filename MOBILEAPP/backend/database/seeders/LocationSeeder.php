<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Region;
use App\Models\City;
use App\Models\Barangay;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::transaction(function () {
            // Create sample regions
            $ncr = Region::create([
                'code' => 'R_NCR_' . time(),
                'name' => 'National Capital Region (NCR)',
                'description' => 'Metro Manila',
                'is_active' => true
            ]);

            $car = Region::create([
                'code' => 'R_CAR_' . time() + 1,
                'name' => 'Cordillera Administrative Region (CAR)',
                'description' => 'Mountain Province',
                'is_active' => true
            ]);

            $region3 = Region::create([
                'code' => 'R_R3_' . time() + 2,
                'name' => 'Region 3 - Central Luzon',
                'description' => 'Central Plains of Luzon',
                'is_active' => true
            ]);

            $region4a = Region::create([
                'code' => 'R_R4A_' . time() + 3,
                'name' => 'Region 4A - CALABARZON',
                'description' => 'Southern Tagalog Mainland',
                'is_active' => true
            ]);

            // Add cities to NCR
            $manila = City::create([
                'region_id' => $ncr->id,
                'code' => 'C_MANILA_' . time(),
                'name' => 'Manila',
                'description' => 'Capital City of the Philippines',
                'is_active' => true
            ]);

            $quezonCity = City::create([
                'region_id' => $ncr->id,
                'code' => 'C_QUEZON_' . time() + 1,
                'name' => 'Quezon City',
                'description' => 'Largest city in Metro Manila',
                'is_active' => true
            ]);

            $makati = City::create([
                'region_id' => $ncr->id,
                'code' => 'C_MAKATI_' . time() + 2,
                'name' => 'Makati',
                'description' => 'Financial District',
                'is_active' => true
            ]);

            $pasig = City::create([
                'region_id' => $ncr->id,
                'code' => 'C_PASIG_' . time() + 3,
                'name' => 'Pasig',
                'description' => 'Business District',
                'is_active' => true
            ]);

            // Add barangays to Manila
            Barangay::create([
                'city_id' => $manila->id,
                'code' => 'B_ERMITA_' . time(),
                'name' => 'Ermita',
                'description' => 'Historic district in Manila',
                'is_active' => true
            ]);

            Barangay::create([
                'city_id' => $manila->id,
                'code' => 'B_BINONDO_' . time() + 1,
                'name' => 'Binondo',
                'description' => 'Chinatown district',
                'is_active' => true
            ]);

            Barangay::create([
                'city_id' => $manila->id,
                'code' => 'B_MALATE_' . time() + 2,
                'name' => 'Malate',
                'description' => 'University belt area',
                'is_active' => true
            ]);

            // Add barangays to Quezon City
            Barangay::create([
                'city_id' => $quezonCity->id,
                'code' => 'B_DILIMAN_' . time() + 3,
                'name' => 'Diliman',
                'description' => 'UP Diliman area',
                'is_active' => true
            ]);

            Barangay::create([
                'city_id' => $quezonCity->id,
                'code' => 'B_CUBAO_' . time() + 4,
                'name' => 'Cubao',
                'description' => 'Commercial district',
                'is_active' => true
            ]);

            Barangay::create([
                'city_id' => $quezonCity->id,
                'code' => 'B_COMMONWEALTH_' . time() + 5,
                'name' => 'Commonwealth',
                'description' => 'Commonwealth Avenue area',
                'is_active' => true
            ]);

            // Add cities to CAR
            $baguio = City::create([
                'region_id' => $car->id,
                'code' => 'C_BAGUIO_' . time() + 4,
                'name' => 'Baguio City',
                'description' => 'Summer Capital of the Philippines',
                'is_active' => true
            ]);

            // Add barangays to Baguio
            Barangay::create([
                'city_id' => $baguio->id,
                'code' => 'B_BURNHAM_' . time() + 6,
                'name' => 'Burnham-Legarda',
                'description' => 'Near Burnham Park',
                'is_active' => true
            ]);

            Barangay::create([
                'city_id' => $baguio->id,
                'code' => 'B_SESSION_' . time() + 7,
                'name' => 'Session Road Area',
                'description' => 'Main commercial district',
                'is_active' => true
            ]);

            // Add cities to Region 3
            $angeles = City::create([
                'region_id' => $region3->id,
                'code' => 'C_ANGELES_' . time() + 5,
                'name' => 'Angeles City',
                'description' => 'City in Pampanga',
                'is_active' => true
            ]);

            $sanFernando = City::create([
                'region_id' => $region3->id,
                'code' => 'C_SANFERNANDO_' . time() + 6,
                'name' => 'San Fernando',
                'description' => 'Capital of Pampanga',
                'is_active' => true
            ]);

            // Add cities to Region 4A
            $antipolo = City::create([
                'region_id' => $region4a->id,
                'code' => 'C_ANTIPOLO_' . time() + 7,
                'name' => 'Antipolo City',
                'description' => 'City in Rizal Province',
                'is_active' => true
            ]);

            $batangas = City::create([
                'region_id' => $region4a->id,
                'code' => 'C_BATANGAS_' . time() + 8,
                'name' => 'Batangas City',
                'description' => 'Capital of Batangas Province',
                'is_active' => true
            ]);

            // Add barangays to Antipolo
            Barangay::create([
                'city_id' => $antipolo->id,
                'code' => 'B_MAYAMOT_' . time() + 8,
                'name' => 'Mayamot',
                'description' => 'Barangay in Antipolo',
                'is_active' => true
            ]);

            Barangay::create([
                'city_id' => $antipolo->id,
                'code' => 'B_SANROQUE_' . time() + 9,
                'name' => 'San Roque',
                'description' => 'Barangay in Antipolo',
                'is_active' => true
            ]);
        });

        $this->command->info('Location data seeded successfully!');
    }
}
