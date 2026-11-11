<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('revenue_item_id')->nullable()->after('sourceable_id')->constrained('revenue_items')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->after('revenue_item_id')->constrained('purchase_items')->restrictOnDelete();
            $table->index(['revenue_item_id', 'direction'], 'stock_movements_revenue_item_direction_idx');
            $table->index(['purchase_item_id', 'direction'], 'stock_movements_purchase_item_direction_idx');
        });

        Schema::create('customer_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_revenue_id')->constrained('revenues')->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('refund_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('revenue_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('inventory_asset_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->string('return_number')->unique();
            $table->date('return_date');
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10);
            $table->char('base_currency', 3);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('base_subtotal', 19, 4)->default(0);
            $table->decimal('base_tax_total', 19, 4)->default(0);
            $table->decimal('base_total', 19, 4)->default(0);
            $table->text('reason');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'original_revenue_id', 'status'], 'customer_returns_original_status_idx');
        });

        Schema::create('customer_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('revenue_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('native_amount', 19, 4)->default(0);
            $table->decimal('base_amount', 19, 4)->default(0);
            $table->decimal('inventory_cost_amount', 19, 4)->nullable();
            $table->timestamps();
            $table->unique(['customer_return_id', 'revenue_item_id'], 'customer_return_line_item_unique');
        });

        Schema::create('supplier_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payable_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('inventory_asset_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('purchase_return_variance_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->string('return_number')->unique();
            $table->date('return_date');
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10);
            $table->char('base_currency', 3);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('base_total', 19, 4)->default(0);
            $table->decimal('inventory_cost_total', 19, 4)->default(0);
            $table->decimal('variance_total', 19, 4)->default(0);
            $table->text('reason');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'original_purchase_id', 'status'], 'supplier_returns_original_status_idx');
        });

        Schema::create('supplier_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('native_amount', 19, 4)->default(0);
            $table->decimal('base_amount', 19, 4)->default(0);
            $table->decimal('inventory_cost_amount', 19, 4)->default(0);
            $table->decimal('variance_amount', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['supplier_return_id', 'purchase_item_id'], 'supplier_return_line_item_unique');
        });

        Schema::create('supplier_return_line_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_return_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_tax_line_id')->constrained()->restrictOnDelete();
            $table->decimal('native_amount', 19, 4)->default(0);
            $table->decimal('base_amount', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['supplier_return_line_id', 'purchase_tax_line_id'], 'supplier_return_line_tax_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_line_taxes');
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('customer_return_lines');
        Schema::dropIfExists('customer_returns');

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_revenue_item_direction_idx');
            $table->dropIndex('stock_movements_purchase_item_direction_idx');
            $table->dropConstrainedForeignId('revenue_item_id');
            $table->dropConstrainedForeignId('purchase_item_id');
        });
    }
};
