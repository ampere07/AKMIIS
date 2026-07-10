<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('invoice_used_id')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('remaining', 10, 2)->nullable();
            $table->string('status', 100)->nullable();
            $table->dateTime('used_date')->nullable();
            $table->dateTime('processed_date')->nullable();
            $table->unsignedBigInteger('processed_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
