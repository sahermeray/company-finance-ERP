<?php

return [
    [
        'key' => 'dashboard',
        'label' => 'ui.navigation.dashboard',
        'items' => [
            [
                'key' => 'dashboard',
                'label' => 'ui.navigation.dashboard',
                'route' => 'dashboard.index',
                'active' => ['dashboard.*'],
                'icon' => 'dashboard',
            ],
        ],
    ],
    [
        'key' => 'operations',
        'label' => 'ui.navigation.operations',
        'items' => [
            [
                'key' => 'sales',
                'label' => 'ui.navigation.sales',
                'route' => 'sales.index',
                'active' => ['sales.*'],
                'icon' => 'sales',
            ],
            [
                'key' => 'purchases',
                'label' => 'ui.navigation.purchases',
                'route' => 'purchases.index',
                'active' => ['purchases.*'],
                'icon' => 'purchases',
            ],
            [
                'key' => 'customers',
                'label' => 'ui.navigation.customers',
                'route' => 'customers.index',
                'active' => ['customers.*'],
                'icon' => 'customers',
            ],
            [
                'key' => 'suppliers',
                'label' => 'ui.navigation.suppliers',
                'route' => 'suppliers.index',
                'active' => ['suppliers.*'],
                'icon' => 'suppliers',
            ],
        ],
    ],
    [
        'key' => 'products-inventory',
        'label' => 'ui.navigation.inventory',
        'items' => [
            [
                'key' => 'inventory-overview',
                'label' => 'ui.inventory.overview',
                'route' => 'inventory.overview',
                'active' => ['inventory.overview'],
                'icon' => 'inventory',
            ],
            [
                'key' => 'products',
                'label' => 'ui.inventory.products',
                'route' => 'inventory.products.index',
                'active' => ['inventory.products.*'],
                'icon' => 'package',
            ],
            [
                'key' => 'warehouses',
                'label' => 'ui.inventory.warehouses',
                'route' => 'inventory.warehouses.index',
                'active' => ['inventory.warehouses.*'],
                'icon' => 'warehouse',
            ],
            [
                'key' => 'categories-units',
                'label' => 'ui.inventory.catalog',
                'route' => 'inventory.catalog.index',
                'active' => ['inventory.catalog.*'],
                'icon' => 'tags',
            ],
            [
                'key' => 'stock-counts',
                'label' => 'ui.inventory.counts',
                'route' => 'inventory.counts.index',
                'active' => ['inventory.counts.*'],
                'icon' => 'clipboard-list',
            ],
            [
                'key' => 'inventory-adjustments',
                'label' => 'ui.inventory.adjustments',
                'route' => 'inventory.adjustments.index',
                'active' => ['inventory.adjustments.*'],
                'icon' => 'sliders-horizontal',
            ],
        ],
    ],
    [
        'key' => 'finance',
        'label' => 'ui.navigation.finance',
        'items' => [
            [
                'key' => 'payments',
                'label' => 'ui.navigation.payments',
                'route' => 'payments.index',
                'active' => ['payments.*'],
                'icon' => 'cash',
            ],
            [
                'key' => 'expenses',
                'label' => 'ui.navigation.expenses',
                'icon' => 'expenses',
            ],
            [
                'key' => 'cash-banks',
                'label' => 'ui.navigation.cash_banks',
                'icon' => 'cash',
            ],
            [
                'key' => 'accounting',
                'label' => 'ui.navigation.accounting',
                'icon' => 'accounting',
            ],
            [
                'key' => 'reports',
                'label' => 'ui.navigation.reports',
                'icon' => 'reports',
            ],
        ],
    ],
    [
        'key' => 'administration',
        'label' => 'ui.navigation.administration',
        'items' => [
            [
                'key' => 'users',
                'label' => 'ui.navigation.users',
                'icon' => 'users',
            ],
            [
                'key' => 'company-settings',
                'label' => 'ui.navigation.settings',
                'icon' => 'settings',
            ],
        ],
    ],
];
