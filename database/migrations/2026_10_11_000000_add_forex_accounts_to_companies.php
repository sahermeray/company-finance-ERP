<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('exchange_gain_account_id')
                ->nullable()
                ->after('base_currency')
                ->constrained('financial_accounts')
                ->nullOnDelete();

            $table->foreignId('exchange_loss_account_id')
                ->nullable()
                ->after('exchange_gain_account_id')
                ->constrained('financial_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_gain_account_id');
            $table->dropConstrainedForeignId('exchange_loss_account_id');
        });
    }
};
