<!DOCTYPE html>
<html lang="id" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e40af">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Java Core' }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/logo/logo-daun.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('images/logo/logo-daun.png') }}">

    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen antialiased" style="background-color: var(--color-surface);">

<div class="flex min-h-screen" x-data="{ sidebarOpen: $persist(true).as('jerp-sidebar') }">
    <x-sidebar />

    <div class="flex flex-col flex-1 min-w-0">
        <x-topbar />

        <main class="flex-1 p-4 md:p-6 lg:p-8 max-w-screen-2xl w-full mx-auto">
            {{ $slot ?? '' }}
            @yield('content')
        </main>

        <footer class="border-t px-6 py-4 text-xs text-center"
                style="border-color: var(--color-surface-border); color: var(--color-text-muted);">
            Java Core · Property Developer ERP — clone modern dari eProperty legacy
        </footer>
    </div>
</div>

@livewireScripts
</body>
</html>
