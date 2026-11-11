<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Links this payment back to the original payment it reverses.
            // Nullable on ordinary payments; unique ensures one reversal per original (DB guard).
            $table->foreignId('reversal_of_payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete()
                ->after('posted_at');

            // Links the original payment to its single reversal payment.
            // Unique constraint prevents two payments from pointing to the same original.
            $table->foreignId('reversed_by_payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete()
                ->after('reversal_of_payment_id');

            // Unique index on reversed_by_payment_id: one original → at most one reversal.
            $table->unique('reversed_by_payment_id', 'payments_reversed_by_payment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_reversed_by_payment_id_unique');
            $table->dropForeign(['reversed_by_payment_id']);
            $table->dropForeign(['reversal_of_payment_id']);
            $table->dropColumn(['reversal_of_payment_id', 'reversed_by_payment_id']);
        });
    }
};
