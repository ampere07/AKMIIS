<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebates_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rebates_id');
            $table->string('account_no', 255);
            $table->string('status', 50)->default('Unused');
            $table->string('month', 50)->nullable();
            
            $table->foreign('rebates_id')->references('id')->on('rebates')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebates_usage');
    }
};
