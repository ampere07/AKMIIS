<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupDataSeeder extends Seeder
{
    public function run()
    {
        // Seed Modem Router SNs
        $modemRouterSNs = [
            ['SN' => 'MR001', 'Model' => 'TP-Link Archer C6'],
            ['SN' => 'MR002', 'Model' => 'Huawei HG8245H'],
            ['SN' => 'MR003', 'Model' => 'ZTE F670L'],
            ['SN' => 'MR004', 'Model' => 'Nokia G-240W-A'],
            ['SN' => 'MR005', 'Model' => 'Fiberhome HG6245N']
        ];

        foreach ($modemRouterSNs as $modem) {
            DB::table('modem_router_sn')->updateOrInsert(
                ['SN' => $modem['SN']],
                $modem + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed Contract Templates
        $contractTemplates = [
            ['Template_Name' => 'Standard', 'Description' => 'Standard Service Agreement'],
            ['Template_Name' => 'Premium', 'Description' => 'Premium Service Agreement'],
            ['Template_Name' => 'Corporate', 'Description' => 'Corporate Service Agreement'],
            ['Template_Name' => 'Residential', 'Description' => 'Residential Service Agreement']
        ];

        foreach ($contractTemplates as $template) {
            DB::table('contract_templates')->updateOrInsert(
                ['Template_Name' => $template['Template_Name']],
                $template + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed LCPs
        $lcps = [
            ['LCP_ID' => 'LCP001', 'Name' => 'Central Distribution Point'],
            ['LCP_ID' => 'LCP002', 'Name' => 'East Distribution Point'],
            ['LCP_ID' => 'LCP003', 'Name' => 'West Distribution Point'],
            ['LCP_ID' => 'LCP004', 'Name' => 'North Distribution Point'],
            ['LCP_ID' => 'LCP005', 'Name' => 'South Distribution Point']
        ];

        foreach ($lcps as $lcp) {
            DB::table('lcp')->updateOrInsert(
                ['LCP_ID' => $lcp['LCP_ID']],
                $lcp + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed NAPs
        $naps = [
            ['NAP_ID' => 'NAP001', 'Location' => 'Binangonan Central'],
            ['NAP_ID' => 'NAP002', 'Location' => 'Rizal Municipality'],
            ['NAP_ID' => 'NAP003', 'Location' => 'Antipolo Area'],
            ['NAP_ID' => 'NAP004', 'Location' => 'Cainta Junction'],
            ['NAP_ID' => 'NAP005', 'Location' => 'Taytay Center']
        ];

        foreach ($naps as $nap) {
            DB::table('nap')->updateOrInsert(
                ['NAP_ID' => $nap['NAP_ID']],
                $nap + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed Ports
        $ports = [
            ['PORT_ID' => 'P001', 'Label' => 'Port 1'],
            ['PORT_ID' => 'P002', 'Label' => 'Port 2'],
            ['PORT_ID' => 'P003', 'Label' => 'Port 3'],
            ['PORT_ID' => 'P004', 'Label' => 'Port 4'],
            ['PORT_ID' => 'P005', 'Label' => 'Port 5'],
            ['PORT_ID' => 'P006', 'Label' => 'Port 6'],
            ['PORT_ID' => 'P007', 'Label' => 'Port 7'],
            ['PORT_ID' => 'P008', 'Label' => 'Port 8']
        ];

        foreach ($ports as $port) {
            DB::table('ports')->updateOrInsert(
                ['PORT_ID' => $port['PORT_ID']],
                $port + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed VLANs
        $vlans = [
            ['VLAN_ID' => 'V100', 'Value' => '100'],
            ['VLAN_ID' => 'V200', 'Value' => '200'],
            ['VLAN_ID' => 'V300', 'Value' => '300'],
            ['VLAN_ID' => 'V400', 'Value' => '400'],
            ['VLAN_ID' => 'V500', 'Value' => '500']
        ];

        foreach ($vlans as $vlan) {
            DB::table('vlans')->updateOrInsert(
                ['VLAN_ID' => $vlan['VLAN_ID']],
                $vlan + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Seed LCPNAP combinations
        $lcpnaps = [
            ['LCPNAP_ID' => 'LCP001-NAP001', 'Combined_Location' => 'Central - Binangonan Central'],
            ['LCPNAP_ID' => 'LCP002-NAP002', 'Combined_Location' => 'East - Rizal Municipality'],
            ['LCPNAP_ID' => 'LCP003-NAP003', 'Combined_Location' => 'West - Antipolo Area'],
            ['LCPNAP_ID' => 'LCP004-NAP004', 'Combined_Location' => 'North - Cainta Junction'],
            ['LCPNAP_ID' => 'LCP005-NAP005', 'Combined_Location' => 'South - Taytay Center']
        ];

        foreach ($lcpnaps as $lcpnap) {
            DB::table('lcpnap')->updateOrInsert(
                ['LCPNAP_ID' => $lcpnap['LCPNAP_ID']],
                $lcpnap + ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->command->info('Lookup data seeded successfully!');
    }
}