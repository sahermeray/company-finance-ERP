<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journals', function (Blueprint $table) {
            // The existing index is a plain (non-unique) index — replace it with a
            // unique index to enforce the one-original-one-reversal invariant at DB level.
            $table->dropIndex('accounting_journals_reversed_by_journal_id_index');
            $table->unique('reversed_by_journal_id', 'journals_reversed_by_journal_id_unique');

            // Note on reversal_of_journal_id using nullOnDelete (pre-existing from
            // 2026_10_10_000001_add_reversal_support_to_accounting_journals.php):
            //
            // The reversal_of_journal_id FK (pointing from a reversal journal back to
            // the original journal it reverses) intentionally uses nullOnDelete rather
            // than restrictOnDelete. Rationale:
            //
            //   - The forward pointer matters most for auditability: if original journal J
            //     is deleted, J's reversed_by_journal_id already prevents that deletion from
            //     succeeding in practice, because the Eloquent "deleting" hook on
            //     AccountingJournal blocks deletion of any posted journal.
            //
            //   - The back pointer on the reversal journal (reversal_of_journal_id) is
            //     supplementary — it is informational and allows tracing "this journal
            //     reverses journal X". If the original were somehow removed (e.g. in a
            //     multi-step soft-delete scenario), nulling the back-pointer is acceptable:
            //     the reversal journal is still a valid, balanced, posted journal on its own.
            //
            //   - SQLite does not support ALTER TABLE … DROP CONSTRAINT / ADD CONSTRAINT,
            //     so this constraint cannot be changed here without full table recreation.
            //     The application-level Eloquent hook provides the primary protection.
        });
    }

    public function down(): void
    {
        Schema::table('accounting_journals', function (Blueprint $table) {
            $table->dropUnique('journals_reversed_by_journal_id_unique');
            $table->index('reversed_by_journal_id', 'accounting_journals_reversed_by_journal_id_index');
        });
    }
};
