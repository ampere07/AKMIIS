<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rebates', function (Blueprint $table) {
            $table->string('month', 50)->nullable()->after('selected_rebate');
        });
    }

    public function down(): void
    {
        Schema::table('rebates', function (Blueprint $table) {
            $table->dropColumn('month');
        });
    }
};
