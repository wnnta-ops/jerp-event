<!DOCTYPE html>
<html lang="id" dir="ltr" class="nup-public">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Pendaftaran NUP' }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">

    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    @if (config('services.recaptcha.enabled'))
        <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
    @endif

    <style>
        /* Force light theme on the public NUP form regardless of user OS pref */
        html.nup-public, html.nup-public body { background: #f5f6fa !important; color: #1f2937 !important; }
        html.nup-public.dark { color-scheme: light; }
        .nup-input {
            width: 100%; border: 1px solid #d1d5db; border-radius: 6px;
            padding: 0.5rem 0.75rem; font-size: 0.875rem; background: #fff; color: #111827;
            transition: border-color .15s, box-shadow .15s;
        }
        .nup-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
        .nup-input[disabled] { background: #f3f4f6; color: #6b7280; cursor: not-allowed; }
        .nup-label { display: block; font-size: 0.8125rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem; }
        .nup-required { color: #dc2626; margin-left: 2px; }
        .nup-section-dot {
            width: 8px; height: 8px; border-radius: 50%; background: #16a34a;
            display: inline-block; margin-right: 8px; vertical-align: middle;
        }
        .nup-pill {
            display: inline-flex; align-items: center; padding: 0.375rem 0.875rem;
            border: 1px solid #d1d5db; border-radius: 999px; font-size: 0.8125rem;
            background: #fff; color: #374151; cursor: pointer; user-select: none;
            transition: all .15s;
        }
        .nup-pill:hover { border-color: #2563eb; color: #2563eb; }
        .nup-pill.active,
        .nup-pill:has(input:checked) { background: #2563eb; border-color: #2563eb; color: #fff; }
        .nup-error { color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem; display: block; }
        .nup-card {
            background: #fff; border-radius: 8px; border: 1px solid #e5e7eb;
            padding: 1.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .nup-section-title { font-weight: 600; font-size: 0.9375rem; color: #111827; }
        .nup-section-subtitle { font-size: 0.8125rem; color: #6b7280; margin-top: 0.25rem; }
        .nup-disclaimer { font-size: 0.75rem; color: #6b7280; font-style: italic; line-height: 1.5; }
        .nup-toggle-row { display: inline-flex; align-items: center; gap: 0.5rem; }
        /* Custom toggle switch */
        .nup-switch { position: relative; display: inline-block; width: 32px; height: 18px; }
        .nup-switch input { opacity: 0; width: 0; height: 0; }
        .nup-switch-slider {
            position: absolute; cursor: pointer; inset: 0; background: #d1d5db; border-radius: 18px;
            transition: .2s;
        }
        .nup-switch-slider:before {
            position: absolute; content: ""; height: 14px; width: 14px; left: 2px; bottom: 2px;
            background: #fff; border-radius: 50%; transition: .2s;
        }
        .nup-switch input:checked + .nup-switch-slider { background: #2563eb; }
        .nup-switch input:checked + .nup-switch-slider:before { transform: translateX(14px); }
    </style>
</head>
<body class="min-h-screen antialiased" style="background: #f5f6fa;">

<header style="background: #0f172a; border-bottom: 1px solid #1e293b;">
    <div class="flex items-center gap-3 px-4 md:px-8 py-3 max-w-screen-xl mx-auto">
        <span class="inline-grid place-items-center w-7 h-7 rounded text-white font-bold text-sm"
              style="background-color: #2563eb;">N</span>
        <span class="text-sm font-semibold tracking-wide" style="color: #f1f5f9;">NUP SYSTEM</span>
    </div>
</header>

<main class="p-4 md:p-8 max-w-screen-xl mx-auto">
    {{ $slot ?? '' }}
    @yield('content')
</main>

<footer class="border-t mt-8 py-6 text-center text-xs" style="border-color: #e5e7eb; color: #6b7280;">
    &copy; {{ now()->year }} {{ config('app.name') }} — Pendaftaran NUP tunduk pada syarat dan ketentuan kami.
</footer>

@livewireScripts
</body>
</html>
