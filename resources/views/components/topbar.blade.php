@php
    $user = auth()->user();
@endphp

<header class="sticky top-0 z-30 border-b backdrop-blur"
        style="background-color: color-mix(in oklch, var(--color-surface) 85%, transparent); border-color: var(--color-surface-border);">
    <div class="flex items-center gap-3 px-4 md:px-6 py-3">
        <button type="button"
                @click="sidebarOpen = !sidebarOpen"
                class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                aria-label="Toggle sidebar">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <a href="{{ route('dashboard') }}"
           class="hidden md:inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
           style="border-color: var(--color-surface-border); color: var(--color-text);"
           onmouseover="this.style.backgroundColor='var(--color-surface-muted)'"
           onmouseout="this.style.backgroundColor=''"
           title="Kembali ke menu utama">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            <span>Menu utama</span>
        </a>

        <div class="hidden md:flex items-center gap-2 text-sm" style="color: var(--color-text-muted);">
            @hasSection('breadcrumb')
                @yield('breadcrumb')
            @endif
        </div>

        <div class="flex-1 max-w-md hidden md:block ml-auto">
            <label class="relative block">
                <span class="sr-only">Cari</span>
                <input type="search" placeholder="Cari customer, unit, kontrak… (⌘K)"
                       class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-offset-1"
                       style="background-color: var(--color-surface-muted); border-color: var(--color-surface-border); --tw-ring-color: var(--color-brand-500);">
            </label>
        </div>

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

        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-800">
                <div class="w-8 h-8 rounded-full grid place-items-center text-sm font-semibold text-white"
                     style="background-color: var(--color-brand-600);">
                    {{ strtoupper(substr($user?->full_name ?? $user?->name ?? 'J', 0, 1)) }}
                </div>
                <span class="hidden md:block text-sm">{{ $user?->full_name ?? $user?->name }}</span>
            </button>
            <div x-show="open" @click.outside="open = false" x-cloak
                 class="absolute right-0 mt-2 w-56 rounded-lg border shadow-lg overflow-hidden"
                 style="background-color: var(--color-surface); border-color: var(--color-surface-border);">
                <div class="px-3 py-2 text-xs border-b" style="border-color: var(--color-surface-border); color: var(--color-text-muted);">
                    {{ $user?->email }}
                </div>
                <a href="{{ route('akun.password') }}" class="block px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">
                    Ganti Password
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
