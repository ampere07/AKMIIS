<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            if (!Schema::hasColumn('installments', 'invoice_id')) {
                $table->unsignedBigInteger('invoice_id')->nullable()->after('account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            if (Schema::hasColumn('installments', 'invoice_id')) {
                $table->dropColumn('invoice_id');
            }
        });
    }
};
