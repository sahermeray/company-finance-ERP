<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finding 2 (MEDIUM): Add a plain index on reversal_of_payment_id for fast
     *   lookup of "which reversal points back at this original?" queries.
     *   A plain (non-unique) index is correct here: an original payment may only
     *   ever have one reversal pointing at it (enforced by the separate UNIQUE index
     *   on reversed_by_payment_id), but we cannot make reversal_of_payment_id itself
     *   UNIQUE because multiple NULLs must be allowed for ordinary payments.
     *
     * Finding 1 (HIGH): Tighten the reversed_by_payment_id FK from ON DELETE SET NULL
     *   to ON DELETE RESTRICT so that deleting a reversal payment cannot silently make
     *   the original appear un-reversed.
     *
     *   SQLite limitation:
     *     SQLite does not support ALTER TABLE … DROP CONSTRAINT / ADD CONSTRAINT.
     *     Changing an existing FK's action requires recreating the whole table.
     *     To avoid that risk (existing data, other FKs, triggers, indexes), this
     *     migration applies the RESTRICT change only on MySQL and PostgreSQL — where
     *     ALTER TABLE … DROP FOREIGN KEY / ADD CONSTRAINT is supported.
     *     On SQLite (used in tests) the FK action remains SET NULL; the application-
     *     level guard added in PaymentService::reversePayment() compensates by
     *     verifying that the reversal payment still exists before trusting the
     *     reversed_by_payment_id pointer. See the comment in PaymentService.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // -----------------------------------------------------------------------
        // Finding 2: index on reversal_of_payment_id (all engines)
        // -----------------------------------------------------------------------
        Schema::table('payments', function (Blueprint $table) {
            $table->index('reversal_of_payment_id', 'payments_reversal_of_payment_id_index');
        });

        // -----------------------------------------------------------------------
        // Finding 1: tighten reversed_by_payment_id FK to RESTRICT (MySQL/PgSQL only)
        // -----------------------------------------------------------------------
        if ($driver === 'mysql' || $driver === 'pgsql') {
            // Drop the existing SET NULL FK, then re-add it with RESTRICT.
            Schema::table('payments', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('payments_reversed_by_payment_id_foreign');
                } else {
                    // PostgreSQL names the constraint by convention too.
                    $table->dropForeign(['reversed_by_payment_id']);
                }

                $table->foreign('reversed_by_payment_id', 'payments_reversed_by_payment_id_foreign')
                    ->references('id')
                    ->on('payments')
                    ->restrictOnDelete()
                    ->nullOnUpdate();
            });
        }
        // SQLite: FK action is left as SET NULL.
        // The application-level guard in PaymentService::reversePayment() provides
        // the compensating control — see the docblock above for details.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Reverse the FK change on MySQL/PostgreSQL
        if ($driver === 'mysql' || $driver === 'pgsql') {
            Schema::table('payments', function (Blueprint $table) use ($driver) {
                if ($driver === 'mysql') {
                    $table->dropForeign('payments_reversed_by_payment_id_foreign');
                } else {
                    $table->dropForeign(['reversed_by_payment_id']);
                }

                $table->foreign('reversed_by_payment_id', 'payments_reversed_by_payment_id_foreign')
                    ->references('id')
                    ->on('payments')
                    ->nullOnDelete();
            });
        }

        // Remove the reversal_of_payment_id index
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_reversal_of_payment_id_index');
        });
    }
};
