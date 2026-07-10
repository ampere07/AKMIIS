<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staggered_installation', function (Blueprint $table) {
            if (!Schema::hasColumn('staggered_installation', 'status')) {
                $table->string('status', 50)->default('Pending')->after('remarks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staggered_installation', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
