<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users table foreign keys
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('set null');
            $table->foreign('group_id')->references('id')->on('group_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Customers table foreign keys
        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('group_id')->references('id')->on('group_list')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Billing accounts table foreign keys
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plan_list')->onDelete('set null');
            $table->foreign('billing_status_id')->references('id')->on('billing_status')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Technical details table foreign keys
        Schema::table('technical_details', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('usage_type_id')->references('id')->on('usage_type')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Transactions table foreign keys
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('processed_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Invoices table foreign keys
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Statement of accounts table foreign keys
        Schema::table('statement_of_accounts', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Payment portal logs table foreign keys
        Schema::table('payment_portal_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
        });

        // Discounts table foreign keys
        Schema::table('discounts', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('invoice_used_id')->references('id')->on('invoices')->onDelete('set null');
            $table->foreign('processed_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Service charge logs table foreign keys
        Schema::table('service_charge_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('service_order_id')->references('id')->on('service_orders')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Expenses log table foreign keys
        Schema::table('expenses_log', function (Blueprint $table) {
            $table->foreign('group_id')->references('id')->on('group_list')->onDelete('set null');
            $table->foreign('processed_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('category_id')->references('id')->on('expenses_category')->onDelete('set null');
            $table->foreign('supplier_id')->references('id')->on('item_supplier_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Installments table foreign keys
        Schema::table('installments', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Installment schedules table foreign keys
        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->foreign('installment_id')->references('id')->on('installments')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Applications table foreign keys
        Schema::table('applications', function (Blueprint $table) {
            $table->foreign('desired_plan_id')->references('id')->on('plan_list')->onDelete('set null');
            $table->foreign('promo_id')->references('id')->on('promo_list')->onDelete('set null');
            $table->foreign('referrer_account_id')->references('id')->on('billing_accounts')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Application visits table foreign keys
        Schema::table('application_visits', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
            $table->foreign('visit_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('status_remarks_id')->references('id')->on('status_remarks_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Job orders table foreign keys
        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('applications')->onDelete('set null');
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('set null');
            $table->foreign('billing_status_id')->references('id')->on('billing_status')->onDelete('set null');
            $table->foreign('group_id')->references('id')->on('group_list')->onDelete('set null');
            $table->foreign('usage_type_id')->references('id')->on('usage_type')->onDelete('set null');
            $table->foreign('visit_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('status_remarks_id')->references('id')->on('status_remarks_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Job order items table foreign keys
        Schema::table('job_order_items', function (Blueprint $table) {
            $table->foreign('job_order_id')->references('id')->on('job_orders')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('inventory_items')->onDelete('cascade');
        });

        // Service orders table foreign keys
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('concern_id')->references('id')->on('support_concern')->onDelete('set null');
            $table->foreign('visit_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('repair_category_id')->references('id')->on('repair_category')->onDelete('set null');
            $table->foreign('new_lcpnap_id')->references('id')->on('lcpnap')->onDelete('set null');
            $table->foreign('new_plan_id')->references('id')->on('plan_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Service order items table foreign keys
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->foreign('service_order_id')->references('id')->on('service_orders')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('inventory_items')->onDelete('cascade');
        });

        // Plan change logs table foreign keys
        Schema::table('plan_change_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('old_plan_id')->references('id')->on('plan_list')->onDelete('set null');
            $table->foreign('new_plan_id')->references('id')->on('plan_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Change due logs table foreign keys
        Schema::table('change_due_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Details update logs table foreign keys
        Schema::table('details_update_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Online status table foreign keys
        Schema::table('online_status', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Reconnection logs table foreign keys
        Schema::table('reconnection_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plan_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Disconnected logs table foreign keys
        Schema::table('disconnected_logs', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Inventory movements table foreign keys
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('inventory_items')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('set null');
            $table->foreign('requested_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Inventory items table foreign keys
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('inventory_category')->onDelete('set null');
            $table->foreign('supplier_id')->references('id')->on('item_supplier_list')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Item supplier list table foreign keys
        Schema::table('item_supplier_list', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('inventory_category')->onDelete('set null');
        });

        // Inventory category table foreign keys
        Schema::table('inventory_category', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Expenses category table foreign keys
        Schema::table('expenses_category', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // SMS blast table foreign keys
        Schema::table('sms_blast', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // SMS blast logs table foreign keys
        Schema::table('sms_blast_logs', function (Blueprint $table) {
            $table->foreign('lcpnap_id')->references('id')->on('lcpnap')->onDelete('set null');
            $table->foreign('lcp_id')->references('id')->on('lcp')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // DC notice table foreign keys
        Schema::table('dc_notice', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Overdue table foreign keys
        Schema::table('overdue', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Attachments table foreign keys
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('billing_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Plan list table foreign keys
        Schema::table('plan_list', function (Blueprint $table) {
            $table->foreign('group_id')->references('id')->on('group_list')->onDelete('set null');
            $table->foreign('modified_by_user')->references('id')->on('users')->onDelete('set null');
        });

        // Group list table foreign keys
        Schema::table('group_list', function (Blueprint $table) {
            $table->foreign('modified_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Router models table foreign keys
        Schema::table('router_models', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // LCPNAP table foreign keys
        Schema::table('lcpnap', function (Blueprint $table) {
            $table->foreign('lcp_id')->references('id')->on('lcp')->onDelete('cascade');
            $table->foreign('nap_id')->references('id')->on('nap')->onDelete('cascade');
        });

        // LCP table foreign keys
        Schema::table('lcp', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // NAP table foreign keys
        Schema::table('nap', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Port table foreign keys
        Schema::table('port', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // VLAN table foreign keys
        Schema::table('vlan', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Usage type table foreign keys
        Schema::table('usage_type', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Billing status table foreign keys
        Schema::table('billing_status', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Repair category table foreign keys
        Schema::table('repair_category', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Support concern table foreign keys
        Schema::table('support_concern', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Status remarks list table foreign keys
        Schema::table('status_remarks_list', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Payment methods table foreign keys
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Promo list table foreign keys
        Schema::table('promo_list', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Organizations table foreign keys
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // Roles table foreign keys
        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // City table foreign keys
        Schema::table('city', function (Blueprint $table) {
            $table->foreign('region_id')->references('id')->on('region')->onDelete('cascade');
        });

        // Barangay table foreign keys
        Schema::table('barangay', function (Blueprint $table) {
            $table->foreign('city_id')->references('id')->on('city')->onDelete('cascade');
        });

        // Village table foreign keys
        Schema::table('village', function (Blueprint $table) {
            $table->foreign('barangay_id')->references('id')->on('barangay')->onDelete('cascade');
        });

        // Activity logs table foreign keys
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        // Drop foreign keys in reverse order
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['target_user_id']);
        });

        Schema::table('village', function (Blueprint $table) {
            $table->dropForeign(['barangay_id']);
        });

        Schema::table('barangay', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
        });

        Schema::table('city', function (Blueprint $table) {
            $table->dropForeign(['region_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('promo_list', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('status_remarks_list', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('support_concern', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('repair_category', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('billing_status', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('usage_type', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('vlan', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('port', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('nap', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('lcp', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('lcpnap', function (Blueprint $table) {
            $table->dropForeign(['lcp_id']);
            $table->dropForeign(['nap_id']);
        });

        Schema::table('router_models', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('group_list', function (Blueprint $table) {
            $table->dropForeign(['modified_by_user_id']);
        });

        Schema::table('plan_list', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropForeign(['modified_by_user']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('overdue', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('dc_notice', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('sms_blast_logs', function (Blueprint $table) {
            $table->dropForeign(['lcpnap_id']);
            $table->dropForeign(['lcp_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('sms_blast', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('expenses_category', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('inventory_category', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('item_supplier_list', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropForeign(['account_id']);
            $table->dropForeign(['requested_by_user_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('disconnected_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('reconnection_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('online_status', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('details_update_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('change_due_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('plan_change_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['old_plan_id']);
            $table->dropForeign(['new_plan_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('service_order_items', function (Blueprint $table) {
            $table->dropForeign(['service_order_id']);
            $table->dropForeign(['item_id']);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['concern_id']);
            $table->dropForeign(['visit_by_user_id']);
            $table->dropForeign(['repair_category_id']);
            $table->dropForeign(['new_lcpnap_id']);
            $table->dropForeign(['new_plan_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('job_order_items', function (Blueprint $table) {
            $table->dropForeign(['job_order_id']);
            $table->dropForeign(['item_id']);
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['account_id']);
            $table->dropForeign(['billing_status_id']);
            $table->dropForeign(['group_id']);
            $table->dropForeign(['usage_type_id']);
            $table->dropForeign(['visit_by_user_id']);
            $table->dropForeign(['status_remarks_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('application_visits', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['visit_by_user_id']);
            $table->dropForeign(['status_remarks_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['desired_plan_id']);
            $table->dropForeign(['promo_id']);
            $table->dropForeign(['referrer_account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->dropForeign(['installment_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('expenses_log', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropForeign(['processed_by_user_id']);
            $table->dropForeign(['category_id']);
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('service_charge_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['service_order_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['invoice_used_id']);
            $table->dropForeign(['processed_by_user_id']);
            $table->dropForeign(['approved_by_user_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('payment_portal_logs', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });

        Schema::table('statement_of_accounts', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['transaction_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['processed_by_user_id']);
            $table->dropForeign(['payment_method_id']);
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('technical_details', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['usage_type_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['billing_status_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['group_id']);
            $table->dropForeign(['created_by_user_id']);
        });
    }
};
