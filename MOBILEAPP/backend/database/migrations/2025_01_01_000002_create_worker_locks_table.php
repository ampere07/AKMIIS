<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_locks', function (Blueprint $table) {
            $table->id();
            $table->string('lock_name', 100)->unique();
            $table->timestamp('locked_at');
            $table->string('locked_by', 255)->nullable();
            $table->timestamp('created_at');
            
            $table->index('lock_name');
            $table->index('locked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_locks');
    }
};
