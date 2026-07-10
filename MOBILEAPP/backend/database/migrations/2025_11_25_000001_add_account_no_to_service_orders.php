<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('service_orders', 'account_no')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->string('account_no')->nullable()->after('id');
                $table->index('account_no');
            });
            
            if (Schema::hasColumn('service_orders', 'account_id')) {
                DB::statement('
                    UPDATE service_orders so
                    JOIN billing_accounts ba ON so.account_id = ba.id
                    SET so.account_no = ba.account_no
                    WHERE so.account_no IS NULL
                ');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_orders', 'account_no')) {
            Schema::table('service_orders', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
                $table->dropColumn('account_no');
            });
        }
    }
};
