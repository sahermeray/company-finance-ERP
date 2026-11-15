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
        Schema::table('accounting_journals', function (Blueprint $table) {
            $table->dropIndex('accounting_journals_reversal_of_journal_id_index');
            $table->unique('reversal_of_journal_id', 'journals_reversal_of_original_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_reversal_of_payment_id_index');
            $table->unique('reversal_of_payment_id', 'payments_reversal_of_original_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_reversal_of_original_unique');
            $table->index('reversal_of_payment_id', 'payments_reversal_of_payment_id_index');
        });

        Schema::table('accounting_journals', function (Blueprint $table) {
            $table->dropUnique('journals_reversal_of_original_unique');
            $table->index('reversal_of_journal_id', 'accounting_journals_reversal_of_journal_id_index');
        });
    }
};
