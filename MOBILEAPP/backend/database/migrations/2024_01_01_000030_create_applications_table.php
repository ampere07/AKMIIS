<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp')->nullable();
            $table->string('email_address', 255)->nullable();
            $table->string('first_name', 255)->nullable();
            $table->char('middle_initial', 1)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('mobile_number', 50)->nullable();
            $table->string('secondary_mobile_number', 50)->nullable();
            $table->text('installation_address')->nullable();
            $table->text('landmark')->nullable();
            $table->string('region', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('barangay', 255)->nullable();
            $table->string('village', 255)->nullable();
            $table->unsignedBigInteger('desired_plan_id')->nullable();
            $table->unsignedBigInteger('promo_id')->nullable();
            $table->unsignedBigInteger('referrer_account_id')->nullable();
            $table->string('referred_by', 255)->nullable();
            $table->string('proof_of_billing_url', 255)->nullable();
            $table->string('government_valid_id_url', 255)->nullable();
            $table->string('second_government_valid_id_url', 255)->nullable();
            $table->string('house_front_picture_url', 255)->nullable();
            $table->string('document_attachment_url', 255)->nullable();
            $table->string('other_isp_bill_url', 255)->nullable();
            $table->boolean('terms_agreed')->default(false);
            $table->string('status', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
