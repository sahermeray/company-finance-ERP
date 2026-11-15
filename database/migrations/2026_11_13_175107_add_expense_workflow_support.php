<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('posted_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->foreignId('reversed_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->foreignId('payment_financial_account_id')->nullable()->after('financial_account_id')
                ->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('payable_account_id')->nullable()->after('payment_financial_account_id')
                ->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->nullable()->after('payable_account_id')
                ->unique('expenses_accounting_journal_unique')
                ->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('reversal_journal_id')->nullable()->after('accounting_journal_id')
                ->unique('expenses_reversal_journal_unique')
                ->constrained('accounting_journals')->restrictOnDelete();
            $table->decimal('tax_total', 19, 4)->default(0)->after('amount');
            $table->foreignId('financial_account_id')->nullable()->change();
        });

        Schema::create('expense_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('category');
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->decimal('base_amount', 24, 4)->nullable();
            $table->timestamps();
            $table->unique(['expense_id', 'line_number'], 'expense_lines_document_line_unique');
            $table->index(['company_id', 'category'], 'expense_lines_company_category_idx');
            $table->index(['company_id', 'expense_account_id'], 'expense_lines_company_account_idx');
        });

        Schema::create('expense_tax_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_line_id')->constrained()->restrictOnDelete();
            $table->string('treatment', 24)->nullable();
            $table->decimal('amount', 19, 4);
            $table->decimal('base_amount', 24, 4)->nullable();
            $table->foreignId('tax_asset_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'expense_line_id'], 'expense_tax_lines_company_line_idx');
        });
    }

    public function down(): void
    {
        if (
            DB::table('expenses')->whereNull('financial_account_id')->exists()
            || DB::table('expenses')->where('status', '!=', 'draft')->exists()
            || DB::table('expenses')->whereNotNull('accounting_journal_id')->exists()
            || DB::table('expense_lines')->exists()
            || DB::table('expense_tax_lines')->exists()
        ) {
            throw new LogicException('Expense workflow data exists; create a forward migration instead of rolling it back.');
        }

        Schema::dropIfExists('expense_tax_lines');
        Schema::dropIfExists('expense_lines');

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropUnique('expenses_accounting_journal_unique');
            $table->dropUnique('expenses_reversal_journal_unique');
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropConstrainedForeignId('accounting_journal_id');
            $table->dropConstrainedForeignId('payable_account_id');
            $table->dropConstrainedForeignId('payment_financial_account_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'posted_at', 'posted_by', 'approved_at', 'approved_by', 'tax_total']);
            $table->foreignId('financial_account_id')->nullable(false)->change();
        });
    }
};
