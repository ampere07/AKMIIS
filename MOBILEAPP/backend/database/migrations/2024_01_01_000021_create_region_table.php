<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region', function (Blueprint $table) {
            $table->id();
            $table->string('region', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region');
    }
};
