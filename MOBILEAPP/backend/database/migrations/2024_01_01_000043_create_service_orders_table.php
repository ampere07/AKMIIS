<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id', 50)->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->dateTime('timestamp')->nullable();
            $table->string('support_status', 100)->nullable();
            $table->unsignedBigInteger('concern_id')->nullable();
            $table->text('concern_remarks')->nullable();
            $table->string('priority_level', 50)->nullable();
            $table->string('requested_by', 255)->nullable();
            $table->string('assigned_email', 255)->nullable();
            $table->string('visit_status', 100)->nullable();
            $table->unsignedBigInteger('visit_by_user_id')->nullable();
            $table->string('visit_with', 255)->nullable();
            $table->text('visit_remarks')->nullable();
            $table->unsignedBigInteger('repair_category_id')->nullable();
            $table->text('support_remarks')->nullable();
            $table->decimal('service_charge', 10, 2)->nullable();
            $table->string('new_router_sn', 255)->nullable();
            $table->unsignedBigInteger('new_lcpnap_id')->nullable();
            $table->unsignedBigInteger('new_plan_id')->nullable();
            $table->string('client_signature_url', 255)->nullable();
            $table->string('image1_url', 255)->nullable();
            $table->string('image2_url', 255)->nullable();
            $table->string('image3_url', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
