<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_order_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->integer('quantity')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_items');
    }
};
