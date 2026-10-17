<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google Drive link to the installation fee invoice PDF sent when the job
     * order's onsite status becomes Done (InstallationFeeNotificationService).
     * Guarded because the column may already have been added by hand.
     */
    public function up(): void
    {
        if (Schema::hasColumn('job_orders', 'invoice_url')) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('invoice_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('job_orders', 'invoice_url')) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('invoice_url');
        });
    }
};
