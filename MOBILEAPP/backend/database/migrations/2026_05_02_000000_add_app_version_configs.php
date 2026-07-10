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
        // Add app version configurations to system_config table
        $configs = [
            [
                'config_key' => 'app_latest_version',
                'config_value' => '2.5.15',
                'updated_by' => 'system',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'config_key' => 'app_min_version',
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
        ];

        foreach ($configs as $config) {
            DB::table('system_config')->updateOrInsert(
                ['config_key' => $config['config_key']],
                $config
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_config')
            ->whereIn('config_key', ['app_latest_version', 'app_min_version', 'playstore_url'])
            ->delete();
    }
};
