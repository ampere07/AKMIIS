<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add indexes to dramatically improve query performance for invoices and SOA
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Index for date sorting (most common query)
            $table->index('invoice_date', 'idx_invoices_invoice_date');
            
            // Index for account lookups
            $table->index('account_no', 'idx_invoices_account_no');
            
            // Index for status filtering
            $table->index('status', 'idx_invoices_status');
            
            // Composite index for date range queries with account
            $table->index(['account_no', 'invoice_date'], 'idx_invoices_account_date');
        });

        Schema::table('statement_of_accounts', function (Blueprint $table) {
            // Index for date sorting (most common query)
            $table->index('statement_date', 'idx_soa_statement_date');
            
            // Index for account lookups
            $table->index('account_no', 'idx_soa_account_no');
            
            // Composite index for date range queries with account
            $table->index(['account_no', 'statement_date'], 'idx_soa_account_date');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            // Index for account number lookups (if not already exists)
            if (!Schema::hasColumn('billing_accounts', 'account_no')) {
                $table->index('account_no', 'idx_billing_accounts_account_no');
            }
            
            // Index for customer relationship
            $table->index('customer_id', 'idx_billing_accounts_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_invoice_date');
            $table->dropIndex('idx_invoices_account_no');
            $table->dropIndex('idx_invoices_status');
            $table->dropIndex('idx_invoices_account_date');
        });

        Schema::table('statement_of_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_soa_statement_date');
            $table->dropIndex('idx_soa_account_no');
            $table->dropIndex('idx_soa_account_date');
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_billing_accounts_customer_id');
            // Only drop account_no index if we created it
            if (Schema::hasIndex('billing_accounts', 'idx_billing_accounts_account_no')) {
                $table->dropIndex('idx_billing_accounts_account_no');
            }
        });
    }
};
