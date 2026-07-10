<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_order_id');
            $table->string('customer_name');
            $table->string('account_no')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('onsite_status');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            
            $table->index('job_order_id');
            $table->index('is_read');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_notifications');
    }
};
