<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_list', function (Blueprint $table) {
            $table->id();
            $table->string('plan_name', 255)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('modified_by_user')->nullable();
            $table->dateTime('modified_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_list');
    }
};
