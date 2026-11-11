<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purchase_number');
            $table->date('purchase_date');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->char('currency', 3)->default('USD');
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'purchase_number'], 'purchases_company_number_unique');
            $table->index(['company_id', 'purchase_date'], 'purchases_company_date_idx');
            $table->index(['company_id', 'status'], 'purchases_company_status_idx');
            $table->index('party_id');
            $table->index('project_id');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_of_measure_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();
            $table->index(['purchase_id', 'line_number'], 'purchase_items_document_line_idx');
            $table->index('product_id');
        });

        Schema::create('revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revenue_number');
            $table->date('revenue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->char('currency', 3)->default('USD');
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'revenue_number'], 'revenues_company_number_unique');
            $table->index(['company_id', 'revenue_date'], 'revenues_company_date_idx');
            $table->index(['company_id', 'status'], 'revenues_company_status_idx');
            $table->index('party_id');
            $table->index('project_id');
        });

        Schema::create('revenue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revenue_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_of_measure_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();
            $table->index(['revenue_id', 'line_number'], 'revenue_items_document_line_idx');
            $table->index('product_id');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('expense_number');
            $table->date('expense_date');
            $table->string('status', 30)->default('draft');
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('USD');
            $table->string('description');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'expense_number'], 'expenses_company_number_unique');
            $table->index(['company_id', 'expense_date'], 'expenses_company_date_idx');
            $table->index(['company_id', 'status'], 'expenses_company_status_idx');
            $table->index('party_id');
            $table->index('project_id');
            $table->index('financial_account_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('payable');
            $table->string('payment_number')->nullable();
            $table->string('direction', 20);
            $table->string('method', 30)->nullable();
            $table->dateTime('paid_at');
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('USD');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_number'], 'payments_company_number_unique');
            $table->index(['company_id', 'paid_at'], 'payments_company_date_idx');
            $table->index('party_id');
            $table->index('project_id');
            $table->index('financial_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('revenue_items');
        Schema::dropIfExists('revenues');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
