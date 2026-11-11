<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journals', function (Blueprint $table) {
            $table->foreignId('reversal_of_journal_id')->nullable()->constrained('accounting_journals')->nullOnDelete();
            $table->foreignId('reversed_by_journal_id')->nullable()->constrained('accounting_journals')->nullOnDelete();
            $table->index('reversal_of_journal_id');
            $table->index('reversed_by_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_journals', function (Blueprint $table) {
            $table->dropIndex(['reversal_of_journal_id']);
            $table->dropIndex(['reversed_by_journal_id']);
            $table->dropColumn(['reversal_of_journal_id', 'reversed_by_journal_id']);
        });
    }
};
