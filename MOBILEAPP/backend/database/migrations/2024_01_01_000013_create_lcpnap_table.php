<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lcpnap', function (Blueprint $table) {
            $table->id();
            $table->string('lcpnap_name', 255);
            $table->string('reading_image_url', 255)->nullable();
            $table->string('street', 255)->nullable();
            $table->string('region', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('barangay', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('lcp', 255)->nullable();
            $table->string('nap', 255)->nullable();
            $table->integer('port_total')->default(8);
            $table->text('coordinates')->nullable();
            $table->string('image1_url', 255)->nullable();
            $table->string('image2_url', 255)->nullable();
            $table->string('modified_by', 255)->nullable();
            $table->dateTime('modified_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lcpnap');
    }
};
