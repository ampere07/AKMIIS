<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port', function (Blueprint $table) {
            $table->id();
            $table->string('port_name', 255)->unique();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port');
    }
};
