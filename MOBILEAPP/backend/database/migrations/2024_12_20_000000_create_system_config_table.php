<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_config', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->text('config_value')->nullable();
            $table->string('updated_by');
            $table->timestamps();
        });

        DB::table('system_config')->insert([
            'config_key' => 'image_logo',
            'config_value' => null,
            'updated_by' => 'system',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_config');
    }
};
