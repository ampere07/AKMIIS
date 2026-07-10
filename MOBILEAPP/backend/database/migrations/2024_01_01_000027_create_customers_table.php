<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 255)->nullable();
            $table->char('middle_initial', 1)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('email_address', 255)->nullable();
            $table->string('contact_number_primary', 50)->nullable();
            $table->string('contact_number_secondary', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('barangay', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('region', 255)->nullable();
            $table->string('address_coordinates', 255)->nullable();
            $table->enum('housing_status', ['renter', 'owner'])->nullable();
            $table->string('referred_by', 255)->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
