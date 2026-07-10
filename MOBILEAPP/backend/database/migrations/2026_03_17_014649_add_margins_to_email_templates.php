<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('Page_Margin')->nullable()->default('1in')->after('email_body');
            $table->string('Image_Margin')->nullable()->default('0px')->after('Page_Margin');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['Page_Margin', 'Image_Margin']);
        });
    }
};
