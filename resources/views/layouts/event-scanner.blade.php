<!DOCTYPE html>
<html lang="id" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ff8c00">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Scanner Event' }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/logo/logo-daun.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/event-scanner.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased bg-slate-950 text-slate-100">
    {{ $slot }}
    @livewireScripts
</body>
</html>
