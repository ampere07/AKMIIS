<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->dateTime('timestamp')->nullable();
            $table->dateTime('date_installed')->nullable();
            $table->decimal('installation_fee', 10, 2)->nullable();
            $table->integer('billing_day')->nullable();
            $table->unsignedBigInteger('billing_status_id')->nullable();
            $table->string('modem_router_sn', 255)->nullable();
            $table->string('router_model', 255)->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('lcpnap', 255)->nullable();
            $table->string('port', 255)->nullable();
            $table->string('vlan', 255)->nullable();
            $table->string('username', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('connection_type', 100)->nullable();
            $table->unsignedBigInteger('usage_type_id')->nullable();
            $table->string('username_status', 100)->nullable();
            $table->unsignedBigInteger('visit_by_user_id')->nullable();
            $table->string('visit_with', 255)->nullable();
            $table->string('onsite_status', 100)->nullable();
            $table->text('onsite_remarks')->nullable();
            $table->unsignedBigInteger('status_remarks_id')->nullable();
            $table->string('contract_link', 255)->nullable();
            $table->string('client_signature_url', 255)->nullable();
            $table->string('setup_image_url', 255)->nullable();
            $table->string('speedtest_image_url', 255)->nullable();
            $table->string('signed_contract_image_url', 255)->nullable();
            $table->string('box_reading_image_url', 255)->nullable();
            $table->string('router_reading_image_url', 255)->nullable();
            $table->string('port_label_image_url', 255)->nullable();
            $table->string('house_front_picture_url', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
