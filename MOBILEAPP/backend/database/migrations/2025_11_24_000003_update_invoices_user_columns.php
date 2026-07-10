<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if columns exist before trying to modify them
        if (Schema::hasColumn('invoices', 'created_by')) {
            Schema::table('invoices', function (Blueprint $table) {
                // If created_by is not varchar, change it
                $table->string('created_by', 255)->nullable()->change();
            });
        }
        
        if (Schema::hasColumn('invoices', 'updated_by')) {
            Schema::table('invoices', function (Blueprint $table) {
                // If updated_by is not varchar, change it
                $table->string('updated_by', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Revert back if needed
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->unsignedBigInteger('updated_by')->nullable()->change();
        });
    }
};
