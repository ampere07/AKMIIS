<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pppoe_username_patterns')) {
            Schema::table('pppoe_username_patterns', function (Blueprint $table) {
                if (!Schema::hasColumn('pppoe_username_patterns', 'pattern_type')) {
                    $table->enum('pattern_type', ['username', 'password'])->after('pattern_name');
                }
            });
            
            DB::statement("ALTER TABLE pppoe_username_patterns DROP INDEX pppoe_username_patterns_pattern_name_unique");
            
            DB::statement("ALTER TABLE pppoe_username_patterns ADD UNIQUE KEY unique_pattern_type (pattern_type)");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pppoe_username_patterns')) {
            Schema::table('pppoe_username_patterns', function (Blueprint $table) {
                $table->dropUnique('unique_pattern_type');
                if (Schema::hasColumn('pppoe_username_patterns', 'pattern_type')) {
                    $table->dropColumn('pattern_type');
                }
            });
        }
    }
};
