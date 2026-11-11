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
        Schema::create('inventory_cutovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inventory_asset_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('opening_equity_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->nullable()->unique()->constrained('accounting_journals')->restrictOnDelete();
            $table->dateTime('effective_at');
            $table->unsignedBigInteger('movement_id_boundary');
            $table->string('status', 20)->default('draft');
            $table->dateTime('posted_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique('company_id', 'inventory_cutovers_company_unique');
            $table->index(['company_id', 'status'], 'inventory_cutovers_company_status_idx');
        });

        Schema::create('inventory_cutover_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_cutover_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_quantity', 18, 4);
            $table->decimal('opening_value', 24, 4);
            $table->decimal('base_unit_cost', 24, 10)->nullable();
            $table->string('valuation_reference');
            $table->timestamps();
            $table->unique(
                ['inventory_cutover_id', 'product_id', 'warehouse_id'],
                'inventory_cutover_lines_product_warehouse_unique'
            );
            $table->index(['company_id', 'product_id', 'warehouse_id'], 'inventory_cutover_lines_tenant_product_wh_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('inventory_cutover_line_id')
                ->nullable()
                ->unique()
                ->constrained('inventory_cutover_lines')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_cutover_line_id');
        });

        Schema::dropIfExists('inventory_cutover_lines');
        Schema::dropIfExists('inventory_cutovers');
    }
};
