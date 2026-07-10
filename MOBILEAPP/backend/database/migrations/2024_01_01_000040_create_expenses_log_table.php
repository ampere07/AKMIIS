<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('expense_date')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('photo_url', 255)->nullable();
            $table->unsignedBigInteger('processed_by_user_id')->nullable();
            $table->string('payee', 255)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('invoice_no', 255)->nullable();
            $table->string('cheque_no', 255)->nullable();
            $table->dateTime('received_date')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses_log');
    }
};
