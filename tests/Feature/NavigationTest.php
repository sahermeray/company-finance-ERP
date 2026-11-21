<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('renders each navigation group once with only implemented destinations linked', function (): void {
    $company = Company::query()->create(['name' => 'Navigation company']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    // Make owner so permission guards pass on dashboard and all nav links.
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $response = $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->getKey(),
            'locale' => 'en',
        ])
        ->get(route('dashboard.index'));

    $response->assertOk()
        ->assertSeeInOrder(['Dashboard', 'Operations', 'Products & inventory', 'Finance', 'Administration'])
        ->assertSee('Payments')
        ->assertDontSee('ui.navigation.', false)
        ->assertDontSee('ui.inventory.', false);

    $expectedItemsByGroup = [
        'dashboard' => ['dashboard'],
        'operations' => ['sales', 'purchases', 'customers', 'suppliers'],
        'products-inventory' => [
            'inventory-overview',
            'products',
            'warehouses',
            'categories-units',
            'stock-counts',
            'inventory-adjustments',
        ],
        'finance' => ['payments', 'expenses', 'cash-banks', 'accounting', 'reports'],
        'administration' => ['users', 'roles', 'company-settings'],
    ];
    $actualItemsByGroup = collect(config('navigation'))
        ->mapWithKeys(fn (array $group): array => [$group['key'] => array_column($group['items'], 'key')])
        ->all();

    expect($actualItemsByGroup)->toBe($expectedItemsByGroup);

    $content = $response->getContent();
    $items = collect(config('navigation'))->flatMap(fn (array $group): array => $group['items']);
    $expectedIcons = [
        'products' => 'package',
        'warehouses' => 'warehouse',
        'categories-units' => 'tags',
        'stock-counts' => 'clipboard-list',
        'inventory-adjustments' => 'sliders-horizontal',
    ];
    $actualIcons = $items
        ->whereIn('key', array_keys($expectedIcons))
        ->mapWithKeys(fn (array $item): array => [$item['key'] => $item['icon'] ?? null])
        ->all();

    expect($actualIcons)->toBe($expectedIcons);

    foreach (array_keys($expectedIcons) as $key) {
        $itemMarkup = [];

        expect(preg_match(
            '/<a\b(?=[^>]*data-navigation-item="'.preg_quote($key, '/').'")[^>]*>.*?<\/a>/s',
            $content,
            $itemMarkup,
        ))->toBe(1);
        expect(substr_count($itemMarkup[0], '<svg'))->toBe(1);
    }

    foreach ($items as $item) {
        expect(substr_count($content, 'data-navigation-item="'.$item['key'].'"'))->toBe(1);
    }

    $implementedItems = $items->filter(fn (array $item): bool => isset($item['route']));
    $comingSoonItems = $items->reject(fn (array $item): bool => isset($item['route']));

    foreach ($implementedItems as $item) {
        expect(Route::has($item['route']))->toBeTrue();
        expect(preg_match(
            '/data-navigation-item="'.preg_quote($item['key'], '/').'"\s+data-navigation-status="available"/',
            $content,
        ))->toBe(1);
    }

    foreach ($comingSoonItems as $item) {
        expect(preg_match(
            '/data-navigation-item="'.preg_quote($item['key'], '/').'"\s+data-navigation-status="coming-soon"/',
            $content,
        ))->toBe(1);
    }

    expect(substr_count($content, 'data-navigation-status="available"'))->toBe(19)
        ->and(substr_count($content, 'data-navigation-status="coming-soon"'))->toBe(0);
});

it('renders every navigation label in Arabic and English', function (): void {
    $company = Company::query()->create(['name' => 'Translation company']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    // Make owner so permission guards pass.
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $expectedTranslations = [
        'en' => [
            'ui.navigation.dashboard' => 'Dashboard',
            'ui.navigation.operations' => 'Operations',
            'ui.navigation.inventory' => 'Products & inventory',
            'ui.navigation.sales' => 'Sales',
            'ui.navigation.purchases' => 'Purchases',
            'ui.navigation.customers' => 'Customers',
            'ui.navigation.suppliers' => 'Suppliers',
            'ui.navigation.finance' => 'Finance',
            'ui.navigation.payments' => 'Payments',
            'ui.navigation.expenses' => 'Expenses',
            'ui.navigation.cash_banks' => 'Cash & banks',
            'ui.navigation.accounting' => 'Accounting',
            'ui.navigation.reports' => 'Reports',
            'ui.navigation.users' => 'Users & permissions',
            'ui.navigation.roles' => 'Roles & permissions',
            'ui.navigation.settings' => 'Company settings',
            'ui.inventory.overview' => 'Inventory overview',
            'ui.inventory.products' => 'Products',
            'ui.inventory.warehouses' => 'Warehouses',
            'ui.inventory.catalog' => 'Categories & units',
            'ui.inventory.counts' => 'Stock counts',
            'ui.inventory.adjustments' => 'Inventory adjustments',
        ],
        'ar' => [
            'ui.navigation.dashboard' => 'لوحة المعلومات',
            'ui.navigation.operations' => 'العمليات',
            'ui.navigation.inventory' => 'المنتجات والمخزون',
            'ui.navigation.sales' => 'المبيعات',
            'ui.navigation.purchases' => 'المشتريات',
            'ui.navigation.customers' => 'العملاء',
            'ui.navigation.suppliers' => 'الموردون',
            'ui.navigation.finance' => 'المالية',
            'ui.navigation.payments' => 'المدفوعات',
            'ui.navigation.expenses' => 'المصروفات',
            'ui.navigation.cash_banks' => 'النقد والبنوك',
            'ui.navigation.accounting' => 'المحاسبة',
            'ui.navigation.reports' => 'التقارير',
            'ui.navigation.users' => 'المستخدمون والصلاحيات',
            'ui.navigation.roles' => 'الأدوار والصلاحيات',
            'ui.navigation.settings' => 'إعدادات الشركة',
            'ui.inventory.overview' => 'نظرة عامة على المخزون',
            'ui.inventory.products' => 'المنتجات',
            'ui.inventory.warehouses' => 'المستودعات',
            'ui.inventory.catalog' => 'الفئات والوحدات',
            'ui.inventory.counts' => 'الجرد',
            'ui.inventory.adjustments' => 'تسويات المخزون',
        ],
    ];

    foreach ($expectedTranslations as $locale => $translations) {
        $response = $this->actingAs($user)
            ->withSession([
                'active_company_id' => $company->getKey(),
                'locale' => $locale,
            ])
            ->get(route('dashboard.index'));

        $response->assertOk()
            ->assertSee(
                '<html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'">',
                false,
            )
            ->assertDontSee('ui.navigation.', false)
            ->assertDontSee('ui.inventory.', false);

        foreach ($translations as $key => $expectedTranslation) {
            expect(__($key, [], $locale))->toBe($expectedTranslation);
            $response->assertSee($expectedTranslation);
        }
    }
});

it('highlights active destinations and expands their navigation groups', function (): void {
    $company = Company::query()->create(['name' => 'Active navigation company']);
    $user = User::factory()->create();
    $company->users()->attach($user);
    // Make owner so permission guards pass.
    \Illuminate\Support\Facades\DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);
    $session = [
        'active_company_id' => $company->getKey(),
        'locale' => 'en',
    ];

    $salesResponse = $this->actingAs($user)
        ->withSession($session)
        ->get(route('sales.index'));

    $salesResponse->assertOk()
        ->assertSee('data-navigation-group="operations" open', false);
    expect(preg_match(
        '/<a\b(?=[^>]*data-navigation-item="sales")(?=[^>]*class="sidebar-link is-active")(?=[^>]*aria-current="page")[^>]*>/s',
        $salesResponse->getContent(),
    ))->toBe(1);

    $inventoryResponse = $this->actingAs($user)
        ->withSession($session)
        ->get(route('inventory.overview'));

    $inventoryResponse->assertOk()
        ->assertSee('data-navigation-group="products-inventory" open', false);
    expect(preg_match(
        '/<a\b(?=[^>]*data-navigation-item="inventory-overview")(?=[^>]*class="sidebar-link is-active")(?=[^>]*aria-current="page")[^>]*>/s',
        $inventoryResponse->getContent(),
    ))->toBe(1);
});

it('keeps company-scoped navigation destinations behind authentication and membership checks', function (): void {
    $company = Company::query()->create(['name' => 'Protected navigation company']);
    $outsider = User::factory()->create();

    $this->get(route('sales.index'))
        ->assertRedirect(route('login'));

    $this->actingAs($outsider)
        ->withSession([
            'active_company_id' => $company->getKey(),
            'locale' => 'en',
        ])
        ->get(route('sales.index'))
        ->assertForbidden();
});
