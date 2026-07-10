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
        Schema::table('payment_portal_logs', function (Blueprint $table) {
            $table->decimal('account_balance_before', 10, 2)->nullable()->after('total_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_portal_logs', function (Blueprint $table) {
            $table->dropColumn('account_balance_before');
        });
    }
};
