<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('second_government_valid_id_url', 'secondary_government_valid_id_url');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->string('promo_url', 255)->nullable()->after('house_front_picture_url');
            $table->string('nearest_landmark1_url', 255)->nullable()->after('promo_url');
            $table->string('nearest_landmark2_url', 255)->nullable()->after('nearest_landmark1_url');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('secondary_government_valid_id_url', 'second_government_valid_id_url');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'promo_url',
                'nearest_landmark1_url',
                'nearest_landmark2_url'
            ]);
        });
    }
};
