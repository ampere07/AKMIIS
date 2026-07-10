<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_list', function (Blueprint $table) {
            $table->id();
            $table->string('group_name', 255)->unique();
            $table->string('fb_page_link', 255)->nullable();
            $table->string('fb_messenger_link', 255)->nullable();
            $table->string('template', 255)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->string('portal_url', 255)->nullable();
            $table->string('hotline', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->unsignedBigInteger('modified_by_user_id')->nullable();
            $table->dateTime('modified_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_list');
    }
};
