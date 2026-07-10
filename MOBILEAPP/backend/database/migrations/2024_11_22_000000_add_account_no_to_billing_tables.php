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
        // 1. Add account_no to invoices table
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'account_no')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('account_no')->nullable()->after('id');
                $table->index('account_no');
            });
            
            // Populate account_no from billing_accounts if account_id exists
            if (Schema::hasColumn('invoices', 'account_id')) {
                DB::statement('
                    UPDATE invoices i
                    JOIN billing_accounts ba ON i.account_id = ba.id
                    SET i.account_no = ba.account_no
                    WHERE i.account_no IS NULL
                ');
            }
        }

        // 2. Add account_no to statement_of_accounts table
        if (Schema::hasTable('statement_of_accounts') && !Schema::hasColumn('statement_of_accounts', 'account_no')) {
            Schema::table('statement_of_accounts', function (Blueprint $table) {
                $table->string('account_no')->nullable()->after('id');
                $table->index('account_no');
            });
            
            // Populate account_no from billing_accounts if account_id exists
            if (Schema::hasColumn('statement_of_accounts', 'account_id')) {
                DB::statement('
                    UPDATE statement_of_accounts soa
                    JOIN billing_accounts ba ON soa.account_id = ba.id
                    SET soa.account_no = ba.account_no
                    WHERE soa.account_no IS NULL
                ');
            }
        }

        // 3. Add account_no to discounts table
        if (Schema::hasTable('discounts') && !Schema::hasColumn('discounts', 'account_no')) {
            Schema::table('discounts', function (Blueprint $table) {
                $table->string('account_no')->nullable()->after('id');
                $table->index('account_no');
            });
            
            // Populate account_no from billing_accounts if account_id exists
            if (Schema::hasColumn('discounts', 'account_id')) {
                DB::statement('
                    UPDATE discounts d
                    JOIN billing_accounts ba ON d.account_id = ba.id
                    SET d.account_no = ba.account_no
                    WHERE d.account_no IS NULL
                ');
            }
        }

        // 4. Add account_no to installments table
        if (Schema::hasTable('installments') && !Schema::hasColumn('installments', 'account_no')) {
            Schema::table('installments', function (Blueprint $table) {
                $table->string('account_no')->nullable()->after('id');
                $table->index('account_no');
            });
            
            // Populate account_no from billing_accounts if account_id exists
            if (Schema::hasColumn('installments', 'account_id')) {
                DB::statement('
                    UPDATE installments i
                    JOIN billing_accounts ba ON i.account_id = ba.id
                    SET i.account_no = ba.account_no
                    WHERE i.account_no IS NULL
                ');
            }
        }

        // 5. Add account_no to service_charge_logs table (if not already exists)
        if (Schema::hasTable('service_charge_logs') && !Schema::hasColumn('service_charge_logs', 'account_no')) {
            Schema::table('service_charge_logs', function (Blueprint $table) {
                $table->string('account_no')->nullable()->after('id');
                $table->index('account_no');
            });
            
            // Populate account_no from billing_accounts if account_id exists
            if (Schema::hasColumn('service_charge_logs', 'account_id')) {
                DB::statement('
                    UPDATE service_charge_logs scl
                    JOIN billing_accounts ba ON scl.account_id = ba.id
                    SET scl.account_no = ba.account_no
                    WHERE scl.account_no IS NULL
                ');
            }
        }

        // 6. Update advanced_payments to ensure account_no exists and is populated
        if (Schema::hasTable('advanced_payments')) {
            if (!Schema::hasColumn('advanced_payments', 'account_no')) {
                Schema::table('advanced_payments', function (Blueprint $table) {
                    $table->string('account_no')->nullable()->after('id');
                    $table->index('account_no');
                });
            }
            
            // Populate account_no from billing_accounts if account_id exists
            if (Schema::hasColumn('advanced_payments', 'account_id')) {
                DB::statement('
                    UPDATE advanced_payments ap
                    JOIN billing_accounts ba ON ap.account_id = ba.id
                    SET ap.account_no = ba.account_no
                    WHERE ap.account_no IS NULL
                ');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: We don't drop account_no columns in down() to prevent data loss
        // If you need to rollback, manually handle the old account_id relationships
        
        // Just remove the indexes
        if (Schema::hasColumn('invoices', 'account_no')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
            });
        }

        if (Schema::hasColumn('statement_of_accounts', 'account_no')) {
            Schema::table('statement_of_accounts', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
            });
        }

        if (Schema::hasColumn('discounts', 'account_no')) {
            Schema::table('discounts', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
            });
        }

        if (Schema::hasColumn('installments', 'account_no')) {
            Schema::table('installments', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
            });
        }

        if (Schema::hasColumn('service_charge_logs', 'account_no')) {
            Schema::table('service_charge_logs', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
            });
        }

        if (Schema::hasColumn('advanced_payments', 'account_no')) {
            Schema::table('advanced_payments', function (Blueprint $table) {
                $table->dropIndex(['account_no']);
            });
        }
    }
};
