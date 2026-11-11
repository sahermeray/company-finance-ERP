<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->after('warehouse_id')->constrained('financial_accounts')->restrictOnDelete();
        });

        Schema::create('purchase_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->string('treatment', 24);
            $table->decimal('amount', 19, 4);
            $table->decimal('base_amount', 24, 4)->nullable();
            $table->foreignId('tax_asset_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->timestamps();
            $table->index(['purchase_id', 'purchase_item_id'], 'purchase_tax_lines_document_item_idx');
        });

        Schema::create('purchase_landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->string('cost_type', 32);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10);
            $table->char('base_currency', 3);
            $table->decimal('base_amount', 24, 4);
            $table->foreignId('expense_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('payable_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['purchase_id', 'cost_type'], 'purchase_landed_costs_document_type_idx');
        });

        Schema::create('purchase_landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_landed_cost_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_amount', 19, 4);
            $table->decimal('base_amount', 24, 4);
            $table->timestamps();
            $table->unique(
                ['purchase_landed_cost_id', 'purchase_item_id'],
                'purchase_landed_cost_allocations_cost_item_unique',
            );
            $table->index(['company_id', 'purchase_item_id'], 'purchase_landed_cost_allocations_tenant_item_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_landed_cost_allocations');
        Schema::dropIfExists('purchase_landed_costs');
        Schema::dropIfExists('purchase_tax_lines');

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
