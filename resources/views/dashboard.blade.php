<!DOCTYPE html>
<html lang="id" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e40af">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Dashboard · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen antialiased" style="background-color: var(--color-surface);">

@php
    $user = auth()->user();

    $modules = [
        [
            'href'  => '/fo',
            'label' => 'Front Office',
            'desc'  => 'Marketing, Finance AR, Collection, Legal, Launching, Komisi.',
            'bg'    => 'var(--color-brand-600)',
            'path'  => 'M3 21h18M3 7l9-4 9 4M5 21V11h14v10',
        ],
        [
            'href'  => '/bo',
            'label' => 'Back Office',
            'desc'  => 'Akuntansi (GL, AR, AP, Cash & Bank, Inventory, HR).',
            'bg'    => 'oklch(0.55 0.18 250)',
            'path'  => 'M4 4h16v16H4zM4 9h16M9 4v16',
        ],
        [
            'href'  => '/bankloan',
            'label' => 'Bank Loan',
            'desc'  => 'Proses KPR: pengajuan, BI checking, akad, realisasi, retensi.',
            'bg'    => 'oklch(0.55 0.18 200)',
            'path'  => 'M3 21h18M3 10l9-7 9 7M5 10v11M19 10v11M9 10v11M15 10v11',
        ],
        [
            'href'  => '/backup',
            'label' => 'Backup',
            'desc'  => 'Manajemen backup database (pengganti TuxArch).',
            'bg'    => 'oklch(0.55 0.18 180)',
            'path'  => 'M21 8H3v13h18zM3 8V3h18v5M10 12h4',
        ],
    ];
@endphp

<header class="sticky top-0 z-30 border-b backdrop-blur"
        style="background-color: color-mix(in oklch, var(--color-surface) 85%, transparent); border-color: var(--color-surface-border);">
    <div class="flex items-center gap-3 px-4 md:px-6 py-3 max-w-screen-2xl mx-auto">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold tracking-tight">
            <span class="inline-grid place-items-center w-8 h-8 rounded text-white"
                  style="background-color: var(--color-brand-600);">J</span>
            <span class="text-lg" style="color: var(--color-text);">Java Core</span>
        </a>

        <div class="ml-auto flex items-center gap-2">
            <button type="button"
                    @click="$store.theme.toggle()"
                    class="inline-flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                    aria-label="Toggle dark mode">
                <svg x-show="$store.theme.current === 'light'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
                <svg x-show="$store.theme.current === 'dark'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-cloak>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </button>

            <a href="/system" title="System"
               class="inline-flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>

            <div class="hidden md:flex items-center gap-2 px-2">
                <div class="w-8 h-8 rounded-full grid place-items-center text-sm font-semibold text-white"
                     style="background-color: var(--color-brand-600);">
                    {{ strtoupper(substr($user?->full_name ?? $user?->name ?? 'J', 0, 1)) }}
                </div>
                <div class="text-sm leading-tight">
                    <div style="color: var(--color-text);">{{ $user?->full_name ?? $user?->name }}</div>
                    <div class="text-xs" style="color: var(--color-text-muted);">{{ $user?->email }}</div>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800"
                        style="border-color: var(--color-surface-border); color: var(--color-text);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span>Keluar</span>
                </button>
            </form>
        </div>
    </div>
</header>

<main class="p-4 md:p-8 max-w-screen-2xl w-full mx-auto">
    <div class="mb-6 md:mb-8">
        <h1 class="text-2xl md:text-3xl font-bold tracking-tight" style="color: var(--color-text);">
            Selamat datang, {{ $user?->full_name ?? $user?->name }}
        </h1>
        <p class="text-sm mt-1" style="color: var(--color-text-muted);">
            Pilih modul untuk memulai.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 md:gap-5">
        @foreach ($modules as $m)
            <a href="{{ $m['href'] }}"
               class="block rounded-xl border p-5 shadow-sm transition-all hover:shadow-md hover:-translate-y-0.5"
               style="background-color: var(--color-surface-muted); border-color: var(--color-surface-border); border-radius: var(--radius-card);">
                <div class="flex items-center gap-3 mb-3">
                    <span class="inline-flex items-center justify-center rounded-lg text-white"
                          style="width:2.5rem; height:2.5rem; background-color: {{ $m['bg'] }};">
                        <svg style="width:1.25rem; height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $m['path'] }}"/>
                        </svg>
                    </span>
                    <span class="font-semibold text-lg" style="color: var(--color-text);">{{ $m['label'] }}</span>
                </div>
                <p class="text-sm" style="color: var(--color-text-muted);">{{ $m['desc'] }}</p>
            </a>
        @endforeach
    </div>
</main>

@livewireScripts
</body>
</html>
