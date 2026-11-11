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
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inventory_asset_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('positive_variance_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('negative_variance_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->dateTime('counted_at');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status'], 'stock_counts_company_status_idx');
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity', 18, 4);
            $table->decimal('counted_quantity', 18, 4);
            $table->decimal('variance_quantity', 18, 4);
            $table->decimal('approved_unit_cost', 24, 10)->nullable();
            $table->timestamps();
            $table->unique(
                ['stock_count_id', 'product_id', 'warehouse_id'],
                'stock_count_lines_count_product_warehouse_unique',
            );
            $table->index(['company_id', 'product_id', 'warehouse_id'], 'stock_count_lines_tenant_product_wh_idx');
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inventory_asset_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('offset_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('stock_count_line_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('adjustment_type', 20);
            $table->decimal('quantity', 18, 4);
            $table->decimal('base_unit_cost', 24, 10)->nullable();
            $table->decimal('base_cost_amount', 24, 4)->nullable();
            $table->dateTime('occurred_at');
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_id', 'occurred_at'], 'inventory_adjustments_tenant_product_date_idx');
            $table->index(['company_id', 'status'], 'inventory_adjustments_company_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
