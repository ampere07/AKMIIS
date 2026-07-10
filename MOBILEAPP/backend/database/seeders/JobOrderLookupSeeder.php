<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ModemRouterSN;
use App\Models\ContractTemplate;
use App\Models\LCP;
use App\Models\NAP;
use App\Models\Port;
use App\Models\VLAN;
use App\Models\LCPNAP;

class JobOrderLookupSeeder extends Seeder
{
    public function run()
    {
        // Seed ModemRouterSN
        $modemRouters = [
            ['SN' => 'MR001', 'Model' => 'TP-Link Archer C6'],
            ['SN' => 'MR002', 'Model' => 'Huawei HG8245H'],
            ['SN' => 'MR003', 'Model' => 'ZTE F670L'],
            ['SN' => 'MR004', 'Model' => 'Nokia G-240W-C'],
            ['SN' => 'MR005', 'Model' => 'Fiberhome AN5506-04-F'],
            ['SN' => 'MR006', 'Model' => 'PLDT Home Fibr'],
            ['SN' => 'MR007', 'Model' => 'Converge FiberX'],
            ['SN' => 'MR008', 'Model' => 'Sky Fiber Router'],
            ['SN' => 'MR009', 'Model' => 'Globe At Home'],
            ['SN' => 'MR010', 'Model' => 'Smart Bro Home WiFi'],
        ];

        foreach ($modemRouters as $modem) {
            ModemRouterSN::firstOrCreate(['SN' => $modem['SN']], $modem);
        }

        // Seed ContractTemplate
        $contractTemplates = [
            ['Template_Name' => 'Standard Contract', 'Description' => 'Standard fiber internet contract'],
            ['Template_Name' => 'Premium Contract', 'Description' => 'Premium service contract with additional benefits'],
            ['Template_Name' => 'Business Contract', 'Description' => 'Business-grade service contract'],
            ['Template_Name' => 'Residential Contract', 'Description' => 'Home/residential service contract'],
            ['Template_Name' => 'Student Contract', 'Description' => 'Special rate for students'],
            ['Template_Name' => 'Senior Citizen Contract', 'Description' => 'Discounted rate for senior citizens'],
            ['Template_Name' => 'Corporate Contract', 'Description' => 'Enterprise-level service contract'],
        ];

        foreach ($contractTemplates as $template) {
            ContractTemplate::firstOrCreate(['Template_Name' => $template['Template_Name']], $template);
        }

        // Seed LCP
        $lcps = [
            ['LCP_ID' => 'LCP001', 'Name' => 'Rizal LCP Main'],
            ['LCP_ID' => 'LCP002', 'Name' => 'Binangonan LCP'],
            ['LCP_ID' => 'LCP003', 'Name' => 'Antipolo LCP'],
            ['LCP_ID' => 'LCP004', 'Name' => 'Cainta LCP'],
            ['LCP_ID' => 'LCP005', 'Name' => 'Taytay LCP'],
            ['LCP_ID' => 'LCP006', 'Name' => 'Angono LCP'],
            ['LCP_ID' => 'LCP007', 'Name' => 'Teresa LCP'],
            ['LCP_ID' => 'LCP008', 'Name' => 'Morong LCP'],
            ['LCP_ID' => 'LCP009', 'Name' => 'Tanay LCP'],
            ['LCP_ID' => 'LCP010', 'Name' => 'Jalajala LCP'],
        ];

        foreach ($lcps as $lcp) {
            LCP::firstOrCreate(['LCP_ID' => $lcp['LCP_ID']], $lcp);
        }

        // Seed NAP
        $naps = [
            ['NAP_ID' => 'NAP001', 'Location' => 'Barangay San Isidro'],
            ['NAP_ID' => 'NAP002', 'Location' => 'Barangay Poblacion'],
            ['NAP_ID' => 'NAP003', 'Location' => 'Barangay Libid'],
            ['NAP_ID' => 'NAP004', 'Location' => 'Barangay Kalinawan'],
            ['NAP_ID' => 'NAP005', 'Location' => 'Barangay Darangan'],
            ['NAP_ID' => 'NAP006', 'Location' => 'Barangay Pantay'],
            ['NAP_ID' => 'NAP007', 'Location' => 'Barangay Pila-pila'],
            ['NAP_ID' => 'NAP008', 'Location' => 'Barangay Mambog'],
            ['NAP_ID' => 'NAP009', 'Location' => 'Barangay Calumpang'],
            ['NAP_ID' => 'NAP010', 'Location' => 'Barangay Mahabang Parang'],
        ];

        foreach ($naps as $nap) {
            NAP::firstOrCreate(['NAP_ID' => $nap['NAP_ID']], $nap);
        }

        // Seed Port
        $ports = [
            ['PORT_ID' => 'PORT001', 'Label' => 'Port 1 - Main'],
            ['PORT_ID' => 'PORT002', 'Label' => 'Port 2 - Secondary'],
            ['PORT_ID' => 'PORT003', 'Label' => 'Port 3 - Backup'],
            ['PORT_ID' => 'PORT004', 'Label' => 'Port 4 - Residential'],
            ['PORT_ID' => 'PORT005', 'Label' => 'Port 5 - Business'],
            ['PORT_ID' => 'PORT006', 'Label' => 'Port 6 - Premium'],
            ['PORT_ID' => 'PORT007', 'Label' => 'Port 7 - Standard'],
            ['PORT_ID' => 'PORT008', 'Label' => 'Port 8 - Corporate'],
        ];

        foreach ($ports as $port) {
            Port::firstOrCreate(['PORT_ID' => $port['PORT_ID']], $port);
        }

        // Seed VLAN
        $vlans = [
            ['VLAN_ID' => 'VLAN100', 'Value' => '100 - Residential'],
            ['VLAN_ID' => 'VLAN200', 'Value' => '200 - Business'],
            ['VLAN_ID' => 'VLAN300', 'Value' => '300 - Premium'],
            ['VLAN_ID' => 'VLAN400', 'Value' => '400 - Corporate'],
            ['VLAN_ID' => 'VLAN500', 'Value' => '500 - Management'],
            ['VLAN_ID' => 'VLAN600', 'Value' => '600 - Guest'],
            ['VLAN_ID' => 'VLAN700', 'Value' => '700 - VoIP'],
            ['VLAN_ID' => 'VLAN800', 'Value' => '800 - IPTV'],
        ];

        foreach ($vlans as $vlan) {
            VLAN::firstOrCreate(['VLAN_ID' => $vlan['VLAN_ID']], $vlan);
        }

        // Seed LCPNAP
        $lcpnaps = [
            ['LCPNAP_ID' => 'LCPNAP001', 'Combined_Location' => 'Rizal Main - San Isidro'],
            ['LCPNAP_ID' => 'LCPNAP002', 'Combined_Location' => 'Binangonan - Poblacion'],
            ['LCPNAP_ID' => 'LCPNAP003', 'Combined_Location' => 'Antipolo - Libid'],
            ['LCPNAP_ID' => 'LCPNAP004', 'Combined_Location' => 'Cainta - Kalinawan'],
            ['LCPNAP_ID' => 'LCPNAP005', 'Combined_Location' => 'Taytay - Darangan'],
            ['LCPNAP_ID' => 'LCPNAP006', 'Combined_Location' => 'Angono - Pantay'],
            ['LCPNAP_ID' => 'LCPNAP007', 'Combined_Location' => 'Teresa - Pila-pila'],
            ['LCPNAP_ID' => 'LCPNAP008', 'Combined_Location' => 'Morong - Mambog'],
            ['LCPNAP_ID' => 'LCPNAP009', 'Combined_Location' => 'Tanay - Calumpang'],
            ['LCPNAP_ID' => 'LCPNAP010', 'Combined_Location' => 'Jalajala - Mahabang Parang'],
        ];

        foreach ($lcpnaps as $lcpnap) {
            LCPNAP::firstOrCreate(['LCPNAP_ID' => $lcpnap['LCPNAP_ID']], $lcpnap);
        }

        $this->command->info('Job Order lookup tables seeded successfully!');
    }
}
