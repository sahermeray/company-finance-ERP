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
        Schema::create('company_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 40);
            $table->timestamps();
            $table->unique(['company_id', 'purpose'], 'company_account_mappings_company_purpose_unique');
            $table->index(['company_id', 'financial_account_id'], 'company_account_mappings_company_account_idx');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->foreignId('payable_account_id')->nullable()->after('party_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('inventory_asset_account_id')->nullable()->after('payable_account_id')->constrained('financial_accounts')->restrictOnDelete();
        });

        Schema::table('revenues', function (Blueprint $table): void {
            $table->foreignId('receivable_account_id')->nullable()->after('party_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->after('receivable_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('inventory_asset_account_id')->nullable()->after('cash_account_id')->constrained('financial_accounts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revenues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_asset_account_id');
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropConstrainedForeignId('receivable_account_id');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_asset_account_id');
            $table->dropConstrainedForeignId('payable_account_id');
        });

        Schema::dropIfExists('company_account_mappings');
    }
};
