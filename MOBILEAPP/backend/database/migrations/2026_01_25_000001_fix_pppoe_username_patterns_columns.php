<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pppoe_username_patterns', function (Blueprint $table) {
            $table->string('created_by')->change();
            $table->string('updated_by')->change();
        });
    }

    public function down(): void
    {
        Schema::table('pppoe_username_patterns', function (Blueprint $table) {
            $table->integer('created_by')->change();
            $table->integer('updated_by')->change();
        });
    }
};
