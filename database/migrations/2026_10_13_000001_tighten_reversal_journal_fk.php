<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tighten accounting_journals reversal FKs from SET NULL to RESTRICT
     * on MySQL and PostgreSQL.
     *
     * accounting_journals.reversed_by_journal_id points from the ORIGINAL journal
     * to its REVERSAL journal. If the reversal journal were deleted, this pointer
     * would silently become NULL, making the original appear un-reversed. Using
     * RESTRICT prevents that deletion.
     *
     * payments.reversal_of_payment_id points from the REVERSAL payment back to
     * the ORIGINAL payment. The Eloquent deleting hook (Fix 2) blocks Eloquent-based
     * deletion of reversed originals. On MySQL/PostgreSQL we additionally enforce
     * RESTRICT at the DB level.
     *
     * SQLite limitation: SQLite does not support ALTER TABLE DROP/ADD CONSTRAINT.
     * The Eloquent-level guards provide the compensating control on SQLite:
     *   - Payment::booted() deleting hook blocks deletion of posted and reversed payments.
     *   - PaymentService::reversePayment() re-verifies reversal state inside the transaction.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'pgsql') {
            // Tighten accounting_journals.reversed_by_journal_id
            Schema::table('accounting_journals', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('accounting_journals_reversed_by_journal_id_foreign');
                } else {
                    $table->dropForeign(['reversed_by_journal_id']);
                }
                $table->foreign('reversed_by_journal_id', 'accounting_journals_reversed_by_journal_id_foreign')
                    ->references('id')
                    ->on('accounting_journals')
                    ->restrictOnDelete()
                    ->nullOnUpdate();
            });

            // Tighten payments.reversal_of_payment_id
            Schema::table('payments', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('payments_reversal_of_payment_id_foreign');
                } else {
                    $table->dropForeign(['reversal_of_payment_id']);
                }
                $table->foreign('reversal_of_payment_id', 'payments_reversal_of_payment_id_foreign')
                    ->references('id')
                    ->on('payments')
                    ->restrictOnDelete()
                    ->nullOnUpdate();
            });
        }
        // SQLite: FK actions left as SET NULL.
        // Eloquent-level guards in Payment::booted() and PaymentService::reversePayment()
        // provide the compensating controls on SQLite.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'pgsql') {
            Schema::table('accounting_journals', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('accounting_journals_reversed_by_journal_id_foreign');
                } else {
                    $table->dropForeign(['reversed_by_journal_id']);
                }
                $table->foreign('reversed_by_journal_id', 'accounting_journals_reversed_by_journal_id_foreign')
                    ->references('id')
                    ->on('accounting_journals')
                    ->nullOnDelete();
            });

            Schema::table('payments', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('payments_reversal_of_payment_id_foreign');
                } else {
                    $table->dropForeign(['reversal_of_payment_id']);
                }
                $table->foreign('reversal_of_payment_id', 'payments_reversal_of_payment_id_foreign')
                    ->references('id')
                    ->on('payments')
                    ->nullOnDelete();
            });
        }
    }
};
