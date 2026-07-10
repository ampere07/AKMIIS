<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->enum('movement_type', ['Used', 'Borrowed', 'Returned', 'Adjusted', 'Defective'])->nullable();
            $table->integer('quantity')->nullable();
            $table->string('serial_number', 255)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('requested_with', 255)->nullable();
            $table->string('status', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
