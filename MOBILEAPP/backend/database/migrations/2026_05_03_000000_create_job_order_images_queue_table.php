<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_order_images_queue', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('organization_id')->nullable();
            $table->bigInteger('job_order_id')->unsigned();
            $table->string('field_name');
            $table->string('local_path');
            $table->string('original_filename');
            $table->string('gdrive_url')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_order_images_queue');
    }
};
