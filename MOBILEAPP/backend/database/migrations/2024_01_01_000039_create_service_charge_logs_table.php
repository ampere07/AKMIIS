<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_charge_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('service_order_id')->nullable();
            $table->decimal('service_charge', 10, 2)->nullable();
            $table->string('status', 100)->nullable();
            $table->dateTime('date_used')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_charge_logs');
    }
};
