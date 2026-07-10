<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_config', function (Blueprint $table) {
            // Add disconnection_fee column (if not exists)
            // Note: disconnection_day already exists in the table, we'll use that for offset
            if (!Schema::hasColumn('billing_config', 'disconnection_fee')) {
                $table->decimal('disconnection_fee', 10, 2)->default(0.00)->after('disconnection_notice')
                    ->comment('Fee to charge when account is disconnected');
            }

            // Add pullout_offset column (days before creating pullout request)
            if (!Schema::hasColumn('billing_config', 'pullout_offset')) {
                $table->integer('pullout_offset')->default(30)->after('disconnection_fee')
                    ->comment('Days after due date to create pullout request');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_config', function (Blueprint $table) {
            if (Schema::hasColumn('billing_config', 'disconnection_fee')) {
                $table->dropColumn('disconnection_fee');
            }
            if (Schema::hasColumn('billing_config', 'pullout_offset')) {
                $table->dropColumn('pullout_offset');
            }
        });
    }
};
