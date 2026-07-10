<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create the new table
        Schema::create('app_version_configs', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->text('config_value')->nullable();
            $table->string('updated_by');
            $table->timestamps();
        });

        // 2. Insert default values
        DB::table('app_version_configs')->insert([
            [
                'config_key' => 'latest_version',
                'config_value' => '2.5.15',
                'updated_by' => 'system',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'config_key' => 'min_version',
                'config_value' => '2.5.0',
                'updated_by' => 'system',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'config_key' => 'playstore_url',
                'config_value' => 'https://play.google.com/store/apps/details?id=com.atss.sync',
                'updated_by' => 'system',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);

        // 3. Clean up the old keys from system_config if they exist
        DB::table('system_config')
            ->whereIn('config_key', ['app_latest_version', 'app_min_version', 'playstore_url'])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_version_configs');
    }
};
