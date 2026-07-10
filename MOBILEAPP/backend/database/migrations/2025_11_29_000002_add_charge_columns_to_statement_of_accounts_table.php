<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_of_accounts', function (Blueprint $table) {
            $table->decimal('service_charge', 10, 2)->default(0)->after('others_and_basic_charges');
            $table->decimal('rebate', 10, 2)->default(0)->after('service_charge');
            $table->decimal('discounts', 10, 2)->default(0)->after('rebate');
            $table->decimal('staggered', 10, 2)->default(0)->after('discounts');
        });
    }

    public function down(): void
    {
        Schema::table('statement_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['service_charge', 'rebate', 'discounts', 'staggered']);
        });
    }
};
