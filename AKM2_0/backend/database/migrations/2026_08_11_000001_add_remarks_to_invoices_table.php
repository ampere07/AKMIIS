<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices.remarks` is already present on deployed databases (the invoice lookup endpoint
 * and the invoice detail screen both read it) but was never captured in a migration.
 *
 * The grace-period charge appends its marker to this column, so a fresh install must have
 * it too. Guarded with hasColumn so the migration is a no-op where the column already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'remarks')) {
                $table->text('remarks')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'remarks')) {
                $table->dropColumn('remarks');
            }
        });
    }
};
