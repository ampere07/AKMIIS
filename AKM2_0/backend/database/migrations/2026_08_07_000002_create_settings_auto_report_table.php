<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row global switch for the scheduled-report cron (reports:queue).
 * When is_enabled is false, QueueScheduledReports skips all processing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings_auto_report')) {
            return;
        }

        Schema::create('settings_auto_report', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\DB::table('settings_auto_report')->insert([
            'is_enabled' => true,
            'updated_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_auto_report');
    }
};
