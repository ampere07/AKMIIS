<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_queue', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 50)->nullable();
            $table->string('contact_no', 50);
            $table->text('message');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('time_sent')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            
            $table->index('status');
            $table->index('account_no');
            $table->index('time_sent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_queue');
    }
};
