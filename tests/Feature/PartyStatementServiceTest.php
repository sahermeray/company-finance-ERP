<?php

use App\Models\Company;
use App\Models\Party;
use App\Models\Revenue;
use App\Services\CompanyContextService;
use App\Services\PartyStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps customer statement balances separated by transaction currency', function (): void {
    $company = Company::create(['name' => 'Statement shop', 'base_currency' => 'SYP']);
    $party = Party::create(['company_id' => $company->getKey(), 'name' => 'Statement customer']);

    app(CompanyContextService::class)->runForCompany($company, function () use ($company, $party): void {
        foreach ([['SYP', '150'], ['USD', '12']] as [$currency, $amount]) {
            Revenue::create([
                'company_id' => $company->getKey(),
                'party_id' => $party->getKey(),
                'revenue_number' => 'REV-'.uniqid(),
                'revenue_date' => '2026-11-01',
                'status' => 'posted',
                'currency' => $currency,
                'exchange_rate' => $currency === 'USD' ? '12000' : '1',
                'base_currency' => 'SYP',
                'subtotal' => $amount,
                'tax_total' => '0',
                'total' => $amount,
                'base_subtotal' => $currency === 'USD' ? bcmul($amount, '12000', 4) : $amount,
                'base_tax_total' => '0',
                'base_total' => $currency === 'USD' ? bcmul($amount, '12000', 4) : $amount,
            ]);
        }

        $statement = app(PartyStatementService::class)->statement($party, 'customer');

        expect($statement['balances'])->toHaveKeys(['SYP', 'USD'])
            ->and(bccomp($statement['balances']['SYP'], '150', 4))->toBe(0)
            ->and(bccomp($statement['balances']['USD'], '12', 4))->toBe(0)
            ->and($statement['opening_balance_available'])->toBeFalse();
    });
});
