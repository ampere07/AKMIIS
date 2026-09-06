<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records whether this order's service charge has already been posted to the
     * customer's account_balance. visit_status -> Done and support_status ->
     * Resolved both trigger the charge, so without a marker on the row the second
     * transition bills the customer a second time.
     *
     * hasColumn-guarded: the column was added by hand on the live database before
     * this migration existed.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('service_orders', 'service_charge_status')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->string('service_charge_status', 50)->nullable()->after('service_charge');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_orders', 'service_charge_status')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->dropColumn('service_charge_status');
            });
        }
    }
};
