<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->dateTime('timestamp')->nullable();
            $table->string('assigned_email', 255)->nullable();
            $table->unsignedBigInteger('visit_by_user_id')->nullable();
            $table->string('visit_with', 255)->nullable();
            $table->string('visit_status', 100)->nullable();
            $table->text('visit_remarks')->nullable();
            $table->string('application_status', 100)->nullable();
            $table->unsignedBigInteger('status_remarks_id')->nullable();
            $table->string('image1_url', 255)->nullable();
            $table->string('image2_url', 255)->nullable();
            $table->string('image3_url', 255)->nullable();
            $table->string('house_front_picture_url', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            
            // No foreign key constraints to allow flexibility
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_visits');
    }
};
