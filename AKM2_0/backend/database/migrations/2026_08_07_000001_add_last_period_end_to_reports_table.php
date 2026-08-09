<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the end date of the last reporting window the scheduled-report cron
 * (QueueScheduledReports) has processed for each report, so recurring emails
 * roll forward to the next window instead of resending the original date_range
 * every run. Null means no cron-triggered window has been sent yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reports')) {
            return;
        }

        if (!Schema::hasColumn('reports', 'last_period_end')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->date('last_period_end')->nullable()->after('date_range');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('reports')) {
            return;
        }

        if (Schema::hasColumn('reports', 'last_period_end')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropColumn('last_period_end');
            });
        }
    }
};
