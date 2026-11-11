<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('sourceable');
            $table->string('journal_number');
            $table->date('entry_date');
            $table->string('status', 30)->default('draft');
            $table->string('description')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'journal_number'], 'journals_company_number_unique');
            $table->index(['company_id', 'entry_date'], 'journals_company_date_idx');
            $table->index(['company_id', 'status'], 'journals_company_status_idx');
            $table->index('project_id');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description')->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->timestamps();
            $table->index(['accounting_journal_id', 'line_number'], 'journal_lines_entry_line_idx');
            $table->index(['financial_account_id', 'party_id'], 'journal_lines_account_party_idx');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('accounting_journals');
    }
};
