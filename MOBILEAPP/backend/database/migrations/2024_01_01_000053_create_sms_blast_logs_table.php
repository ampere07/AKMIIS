<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_blast_logs', function (Blueprint $table) {
            $table->id();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->integer('billing_day')->nullable();
            $table->unsignedBigInteger('lcpnap_id')->nullable();
            $table->unsignedBigInteger('lcp_id')->nullable();
            $table->integer('message_count')->nullable();
            $table->dateTime('timestamp')->nullable();
            $table->decimal('credit_used', 10, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_blast_logs');
    }
};
