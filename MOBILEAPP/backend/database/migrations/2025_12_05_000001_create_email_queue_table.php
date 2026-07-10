<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_queue', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 50)->nullable();
            $table->string('recipient_email', 150);
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->string('subject', 200);
            $table->longText('body_html');
            $table->string('attachment_path', 255)->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            
            $table->index('status');
            $table->index('account_no');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_queue');
    }
};
