<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days after the auto-disconnection date that the grace period charge is posted.
     * Previously the AutoDisconnectService::ADDITIONAL_INVOICE_OFFSET_DAYS constant (7);
     * the constant now only serves as the fallback when this column is NULL or <= 0.
     *
     * hasColumn-guarded: the column was added by hand on the live database before
     * this migration existed.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('billing_config', 'grace_charge_day')) {
            Schema::table('billing_config', function (Blueprint $table) {
                $table->integer('grace_charge_day')->nullable()->after('pullout_day');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_config', 'grace_charge_day')) {
            Schema::table('billing_config', function (Blueprint $table) {
                $table->dropColumn('grace_charge_day');
            });
        }
    }
};
