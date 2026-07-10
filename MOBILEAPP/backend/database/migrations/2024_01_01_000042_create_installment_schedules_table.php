<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installment_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->integer('installment_no')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'paid', 'overdue'])->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedules');
    }
};
