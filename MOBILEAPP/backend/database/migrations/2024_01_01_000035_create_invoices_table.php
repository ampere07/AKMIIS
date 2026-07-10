<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->dateTime('invoice_date')->nullable();
            $table->decimal('invoice_balance', 10, 2)->nullable();
            $table->decimal('others_and_basic_charges', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->decimal('received_payment', 10, 2)->nullable();
            $table->dateTime('due_date')->nullable();
            $table->string('status', 100)->nullable();
            $table->string('payment_portal_log_ref', 255)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
