<!DOCTYPE html>
<html lang="id" class="h-full antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name') }}</title>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="theme-color" content="#ff8c00">

    @routes
    @viteReactRefresh
    @vite(['resources/js/app.ts', 'resources/css/app.css'])
    @inertiaHead
</head>
<body class="h-full font-sans">
    @inertia
</body>
</html>
