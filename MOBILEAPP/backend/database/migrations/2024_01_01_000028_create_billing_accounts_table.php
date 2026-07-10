<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('account_no', 255)->unique();
            $table->date('date_installed')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->decimal('account_balance', 12, 2)->default(0.00);
            $table->dateTime('balance_update_date')->nullable();
            $table->integer('billing_day')->nullable();
            $table->unsignedBigInteger('billing_status_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_accounts');
    }
};
