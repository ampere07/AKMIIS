<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->dateTime('statement_date')->nullable();
            $table->decimal('balance_from_previous_bill', 10, 2)->nullable();
            $table->decimal('payment_received_previous', 10, 2)->nullable();
            $table->decimal('remaining_balance_previous', 10, 2)->nullable();
            $table->decimal('monthly_service_fee', 10, 2)->nullable();
            $table->decimal('others_and_basic_charges', 10, 2)->nullable();
            $table->decimal('vat', 10, 2)->nullable();
            $table->dateTime('due_date')->nullable();
            $table->decimal('amount_due', 10, 2)->nullable();
            $table->decimal('total_amount_due', 10, 2)->nullable();
            $table->string('print_link', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_of_accounts');
    }
};
