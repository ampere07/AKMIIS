<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique()->nullable();
            $table->string('username', 255)->unique()->nullable();
            $table->string('session_status', 100)->nullable();
            $table->string('session_group', 100)->nullable();
            $table->string('session_id', 255)->nullable();
            $table->decimal('total_download', 12, 2)->nullable();
            $table->decimal('total_upload', 12, 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('session_mac_address', 17)->unique()->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_status');
    }
};
