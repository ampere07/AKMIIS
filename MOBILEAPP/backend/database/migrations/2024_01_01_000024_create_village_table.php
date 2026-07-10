<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('village', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('village', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('village');
    }
};
