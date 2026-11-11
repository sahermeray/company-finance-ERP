<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tighten accounting_journals.reversal_of_journal_id from ON DELETE SET NULL
     * to ON DELETE RESTRICT on MySQL and PostgreSQL.
     *
     * Background:
     *   accounting_journals.reversal_of_journal_id points from a REVERSAL journal
     *   back to the ORIGINAL journal it reverses. The original migration
     *   (2026_10_10_000001) created this FK with nullOnDelete(). Migration
     *   2026_10_13_000001 tightened the companion pointer
     *   (reversed_by_journal_id) to RESTRICT but intentionally left
     *   reversal_of_journal_id as SET NULL at the time.
     *
     *   With RESTRICT on reversal_of_journal_id, deleting the ORIGINAL journal is
     *   blocked at the database level whenever a reversal journal still references
     *   it — preserving the reversal chain's referential integrity. This is the
     *   symmetric counterpart to the reversed_by_journal_id RESTRICT added in
     *   2026_10_13_000001.
     *
     * SQLite limitation:
     *   SQLite does not support ALTER TABLE … DROP CONSTRAINT / ADD CONSTRAINT.
     *   Changing an existing FK's action requires recreating the whole table, which
     *   risks data loss and invalidating other FKs, indexes, and triggers. This
     *   migration therefore applies the RESTRICT change only on MySQL and
     *   PostgreSQL — where ALTER TABLE … DROP FOREIGN KEY / ADD CONSTRAINT is
     *   supported. On SQLite (used in tests) the FK action remains SET NULL.
     *
     * Compensating control on SQLite (and all engines):
     *   AccountingJournal::booted() defines a "deleting" Eloquent hook that throws
     *   a ValidationException whenever a posted journal (status === 'posted' or
     *   posted_at !== null) is deleted. This blocks deletion of any posted journal
     *   — including the original that a reversal points to — at the application
     *   level, regardless of the database engine. The Eloquent hook is the primary
     *   protection; the database-level RESTRICT is a defence-in-depth layer on
     *   MySQL/PostgreSQL.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'pgsql') {
            Schema::table('accounting_journals', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('accounting_journals_reversal_of_journal_id_foreign');
                } else {
                    $table->dropForeign(['reversal_of_journal_id']);
                }

                $table->foreign('reversal_of_journal_id', 'accounting_journals_reversal_of_journal_id_foreign')
                    ->references('id')
                    ->on('accounting_journals')
                    ->restrictOnDelete()
                    ->nullOnUpdate();
            });
        }
        // SQLite: FK action left as SET NULL.
        // The AccountingJournal::booted() deleting hook provides the compensating
        // control on SQLite (and all engines) — see the docblock above.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'pgsql') {
            Schema::table('accounting_journals', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('accounting_journals_reversal_of_journal_id_foreign');
                } else {
                    $table->dropForeign(['reversal_of_journal_id']);
                }

                $table->foreign('reversal_of_journal_id', 'accounting_journals_reversal_of_journal_id_foreign')
                    ->references('id')
                    ->on('accounting_journals')
                    ->nullOnDelete();
            });
        }
    }
};
