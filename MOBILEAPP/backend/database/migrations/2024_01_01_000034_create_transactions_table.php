<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->enum('transaction_type', ['Installation Fee', 'Recurring Fee', 'Security Deposit'])->nullable();
            $table->decimal('received_payment', 10, 2)->nullable();
            $table->dateTime('payment_date')->nullable();
            $table->dateTime('date_processed')->nullable();
            $table->unsignedBigInteger('processed_by_user_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('reference_no', 255)->nullable();
            $table->string('or_no', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 100)->nullable();
            $table->string('image_url', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
