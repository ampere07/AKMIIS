<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_balance', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_balance', 'achievement')) {
                $table->decimal('achievement', 10, 2)->default(0)->after('bonus');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('agent_balance', function (Blueprint $table) {
            if (Schema::hasColumn('agent_balance', 'achievement')) {
                $table->dropColumn('achievement');
            }
        });
    }
};
