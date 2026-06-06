@props([
    'type' => 'button',
    'variant' => 'primary',  // primary | secondary | danger | ghost
    'size' => 'md',          // sm | md | lg
])

@php
    $base = 'inline-flex items-center justify-center font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none rounded-lg';

    $sizes = [
        'sm' => 'h-8 px-3 text-sm gap-1.5',
        'md' => 'h-10 px-4 text-sm gap-2',
        'lg' => 'h-12 px-6 text-base gap-2.5',
    ];

    $styles = match ($variant) {
        'primary'   => 'background-color: var(--color-brand-600); color: white; --tw-ring-color: var(--color-brand-500);',
        'secondary' => 'background-color: var(--color-surface-muted); color: var(--color-text); border: 1px solid var(--color-surface-border);',
        'danger'    => 'background-color: var(--color-danger); color: white;',
        'ghost'     => 'background-color: transparent; color: var(--color-text);',
    };
@endphp

<button {{ $attributes->merge(['type' => $type, 'class' => "$base {$sizes[$size]}"]) }}
        style="{{ $styles }} border-radius: var(--radius-button);">
    {{ $slot }}
</button>
