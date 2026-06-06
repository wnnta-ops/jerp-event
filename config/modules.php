<?php

/**
 * J-ERP module registry. ModuleServiceProvider iterates this to mount routes.
 * `panel` modules have their UI served by Filament (BO/Backup/System) — their
 * Blade routes file is NOT mounted to avoid prefix collision.
 *
 * `menu` is consumed by resources/views/components/sidebar.blade.php to render
 * the per-module sub-navigation. Only required for Blade-based modules.
 */
return [
    'modules' => [
        'fo' => [
            'name' => 'Front Office',
            'prefix' => 'fo',
            'panel' => 'fo',
            'enabled' => true,
            'color' => 'var(--color-brand-600)',
        ],
        'bo' => [
            'name' => 'Back Office',
            'prefix' => 'bo',
            'panel' => 'bo',
            'enabled' => true,
            'color' => 'oklch(0.55 0.18 250)',
        ],
        'bankloan' => [
            'name' => 'Bank Loan (KPR)',
            'prefix' => 'bankloan',
            'panel' => null,
            'enabled' => true,
            'color' => 'oklch(0.55 0.18 200)',
            'menu' => [
                ['label' => 'Dashboard',     'href' => '/bankloan',            'icon' => 'home'],
                ['label' => 'Contracts',     'href' => '/bankloan/contracts',  'icon' => 'document'],
                ['label' => 'Contract Bank', 'href' => '/bankloan/banks',      'icon' => 'bank'],
                ['label' => 'BI Checking',   'href' => '/bankloan/bi-checking','icon' => 'magnifier'],
                ['label' => 'Realisasi',     'href' => '/bankloan/realisasi',  'icon' => 'check'],
                ['label' => 'Retensi',       'href' => '/bankloan/retensi',    'icon' => 'lock'],
                ['label' => 'Buyback',       'href' => '/bankloan/buyback',    'icon' => 'refresh'],
                ['label' => 'MoM',           'href' => '/bankloan/mom',        'icon' => 'note'],
                ['label' => 'Reminders',     'href' => '/bankloan/reminders',  'icon' => 'bell'],
            ],
        ],
        'backup' => [
            'name' => 'Backup',
            'prefix' => 'backup',
            'panel' => 'backup',
            'enabled' => true,
            'color' => 'oklch(0.55 0.18 180)',
        ],
        'system' => [
            'name' => 'System',
            'prefix' => 'system',
            'panel' => 'system',
            'enabled' => true,
            'color' => 'oklch(0.55 0.18 290)',
        ],
    ],
];
