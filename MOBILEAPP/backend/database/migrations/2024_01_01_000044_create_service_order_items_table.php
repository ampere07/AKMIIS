<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_order_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->boolean('is_pullout')->nullable();
            $table->string('serial_number', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_items');
    }
};
