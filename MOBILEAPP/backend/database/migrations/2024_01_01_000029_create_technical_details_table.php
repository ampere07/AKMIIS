<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('username', 255)->unique();
            $table->string('username_status', 100)->nullable();
            $table->string('connection_type', 100)->nullable();
            $table->string('router_model', 255)->nullable();
            $table->string('router_modem_sn', 255)->unique()->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('lcp', 255)->nullable();
            $table->string('nap', 255)->nullable();
            $table->string('port', 255)->nullable();
            $table->string('vlan', 255)->nullable();
            $table->string('lcpnap', 255)->nullable();
            $table->unsignedBigInteger('usage_type_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_details');
    }
};
