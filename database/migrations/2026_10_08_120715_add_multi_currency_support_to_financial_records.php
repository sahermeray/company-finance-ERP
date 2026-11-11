<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('base_currency', 3)->default('SYP')->change();
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->char('currency', 3)->default('SYP')->change();
        });

        foreach (['purchases', 'revenues'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->char('currency', 3)->default('SYP')->change();
                $table->decimal('exchange_rate', 24, 10)->nullable();
                $table->char('base_currency', 3)->nullable();
                $table->decimal('base_subtotal', 24, 4)->nullable();
                $table->decimal('base_tax_total', 24, 4)->nullable();
                $table->decimal('base_total', 24, 4)->nullable();
                $table->index(['company_id', 'currency']);
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->char('currency', 3)->default('SYP')->change();
            $table->decimal('exchange_rate', 24, 10)->nullable();
            $table->char('base_currency', 3)->nullable();
            $table->decimal('base_amount', 24, 4)->nullable();
            $table->index(['company_id', 'currency']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->default('SYP')->change();
            $table->decimal('exchange_rate', 24, 10)->nullable();
            $table->char('base_currency', 3)->nullable();
            $table->decimal('base_amount', 24, 4)->nullable();
            $table->decimal('applied_amount', 19, 4)->nullable();
            $table->char('applied_currency', 3)->nullable();
            $table->decimal('applied_exchange_rate', 24, 10)->nullable();
            $table->decimal('applied_base_amount', 24, 4)->nullable();
            $table->index(['company_id', 'currency']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 24, 10)->nullable();
            $table->char('base_currency', 3)->nullable();
            $table->decimal('base_debit', 24, 4)->nullable();
            $table->decimal('base_credit', 24, 4)->nullable();
            $table->index(['currency', 'party_id']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->decimal('rate', 24, 10);
            $table->dateTime('effective_at');
            $table->string('source')->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(
                ['company_id', 'currency', 'base_currency', 'effective_at'],
                'exchange_rates_lookup_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $currencyDataColumns = [
            'purchases' => ['exchange_rate', 'base_currency', 'base_subtotal', 'base_tax_total', 'base_total'],
            'revenues' => ['exchange_rate', 'base_currency', 'base_subtotal', 'base_tax_total', 'base_total'],
            'expenses' => ['exchange_rate', 'base_currency', 'base_amount'],
            'payments' => [
                'exchange_rate',
                'base_currency',
                'base_amount',
                'applied_amount',
                'applied_currency',
                'applied_exchange_rate',
                'applied_base_amount',
            ],
            'journal_lines' => ['currency', 'exchange_rate', 'base_currency', 'base_debit', 'base_credit'],
        ];

        foreach ($currencyDataColumns as $tableName => $columns) {
            if (DB::table($tableName)->where(function (QueryBuilder $query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })->exists()) {
                throw new LogicException('Multi-currency data exists; create a forward migration instead of rolling back.');
            }
        }

        if (DB::table('exchange_rates')->exists()) {
            throw new LogicException('Exchange-rate history exists; create a forward migration instead of rolling back.');
        }

        Schema::dropIfExists('exchange_rates');

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['currency', 'party_id']);
            $table->dropColumn([
                'currency',
                'exchange_rate',
                'base_currency',
                'base_debit',
                'base_credit',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'currency']);
            $table->dropColumn([
                'exchange_rate',
                'base_currency',
                'base_amount',
                'applied_amount',
                'applied_currency',
                'applied_exchange_rate',
                'applied_base_amount',
            ]);
            $table->char('currency', 3)->default('USD')->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'currency']);
            $table->dropColumn(['exchange_rate', 'base_currency', 'base_amount']);
            $table->char('currency', 3)->default('USD')->change();
        });

        foreach (['purchases', 'revenues'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['company_id', 'currency']);
                $table->dropColumn([
                    'exchange_rate',
                    'base_currency',
                    'base_subtotal',
                    'base_tax_total',
                    'base_total',
                ]);
                $table->char('currency', 3)->default('USD')->change();
            });
        }

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->char('currency', 3)->default('USD')->change();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->char('base_currency', 3)->default('USD')->change();
        });
    }
};
