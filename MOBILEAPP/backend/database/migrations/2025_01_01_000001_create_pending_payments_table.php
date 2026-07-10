<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 50);
            $table->string('reference_no', 100)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status', 50)->default('PENDING');
            $table->dateTime('payment_date');
            $table->string('provider', 50)->nullable();
            $table->string('plan', 100)->nullable();
            $table->string('payment_id', 255)->nullable();
            $table->string('payment_method_id', 255)->nullable();
            $table->text('json_payload')->nullable();
            $table->text('payment_url')->nullable();
            $table->longText('callback_payload')->nullable();
            $table->string('reconnect_status', 50)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            
            $table->index('account_no');
            $table->index('status');
            $table->index('reference_no');
            $table->index(['status', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payments');
    }
};
