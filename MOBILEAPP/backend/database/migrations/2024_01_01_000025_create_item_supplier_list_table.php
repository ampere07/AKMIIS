<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_supplier_list', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name', 255)->unique();
            $table->string('contact_number', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_supplier_list');
    }
};
