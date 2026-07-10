<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('visit_by', 255)->nullable()->after('visit_status');
            $table->string('visit_with_other', 255)->nullable()->after('visit_with');
            $table->string('time_in_image_url', 255)->nullable()->after('image3_url');
            $table->string('modem_setup_image_url', 255)->nullable()->after('time_in_image_url');
            $table->string('time_out_image_url', 255)->nullable()->after('modem_setup_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['visit_by', 'visit_with_other', 'time_in_image_url', 'modem_setup_image_url', 'time_out_image_url']);
        });
    }
};
