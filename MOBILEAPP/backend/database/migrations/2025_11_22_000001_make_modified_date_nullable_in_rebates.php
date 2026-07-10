<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('rebates', function (Blueprint $table) {
            if (Schema::hasColumn('rebates', 'modified_date')) {
                $table->string('modified_date')->nullable()->change();
            }
        });
    }

    public function down()
    {
        Schema::table('rebates', function (Blueprint $table) {
            if (Schema::hasColumn('rebates', 'modified_date')) {
                $table->string('modified_date')->nullable(false)->change();
            }
        });
    }
};
