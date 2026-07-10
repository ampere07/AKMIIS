<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 255)->unique();
            $table->string('password_hash', 255);
            $table->string('email_address', 255)->nullable();
            $table->string('first_name', 255)->nullable();
            $table->char('middle_initial', 1)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('status', 100)->nullable();
            $table->dateTime('last_login')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
