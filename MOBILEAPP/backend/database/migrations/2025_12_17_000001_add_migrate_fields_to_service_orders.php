<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('new_router_modem_sn')->nullable()->after('Repair_Category');
            $table->string('new_lcp')->nullable()->after('new_router_modem_sn');
            $table->string('new_nap')->nullable()->after('new_lcp');
            $table->string('new_port')->nullable()->after('new_nap');
            $table->string('new_vlan')->nullable()->after('new_port');
            $table->string('router_model')->nullable()->after('new_vlan');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn([
                'new_router_modem_sn',
                'new_lcp',
                'new_nap',
                'new_port',
                'new_vlan',
                'router_model',
            ]);
        });
    }
};
