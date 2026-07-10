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
        Schema::table('work_order', function (Blueprint $table) {
            if (!Schema::hasColumn('work_order', 'start_time')) {
                $table->dateTime('start_time')->nullable();
            }
            if (!Schema::hasColumn('work_order', 'end_time')) {
                $table->dateTime('end_time')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
