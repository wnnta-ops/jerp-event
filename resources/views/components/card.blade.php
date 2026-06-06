@props(['title' => null, 'href' => null])

@php
    $isLink = filled($href);
    $tag = $isLink ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($isLink) href="{{ $href }}" @endif
    {{ $attributes->class([
        'block rounded-xl border p-5 shadow-sm transition-all',
        'hover:shadow-md hover:-translate-y-0.5 cursor-pointer' => $isLink,
    ]) }}
    style="background-color: var(--color-surface-muted); border-color: var(--color-surface-border); border-radius: var(--radius-card);">

    @if ($title)
        <h3 class="font-semibold mb-2" style="color: var(--color-text);">{{ $title }}</h3>
    @endif

    {{ $slot }}
</{{ $tag }}>
