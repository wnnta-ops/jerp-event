@php
    $segment = request()->segment(1) ?? '';
    $module = config("modules.modules.{$segment}");
    $menu = $module['menu'] ?? [];
    $moduleName = $module['name'] ?? config('app.name');
    $moduleColor = $module['color'] ?? 'var(--color-brand-600)';

    $svgs = [
        'home'      => 'M3 12l9-9 9 9M5 10v10h14V10',
        'briefcase' => 'M3 7h18v13H3zM8 7V4h8v3',
        'banknotes' => 'M3 7h18v10H3zM3 11h18M7 14h2M15 14h2',
        'inbox'     => 'M3 14l3-7h12l3 7M3 14v7h18v-7M3 14h6l2 3h2l2-3h6',
        'shield'    => 'M12 3l8 4v5c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V7l8-4z',
        'rocket'    => 'M5 19l3-3m6-6l3-3m-9 9c2-5 7-10 12-9 1 5-4 10-9 12l-3-3z',
        'eye'       => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7zM12 9a3 3 0 100 6 3 3 0 000-6z',
        'gift'      => 'M3 12h18v9H3zM3 8h18v4H3zM12 8V4M8 8c-2 0-3-2-2-3s3-1 4 2M16 8c2 0 3-2 2-3s-3-1-4 2',
        'check'     => 'M4 12l5 5L20 6',
        'cog'       => 'M10.3 4.3a2 2 0 013.4 0l.4.8a2 2 0 002 1l.9-.1a2 2 0 011.7 3l-.5.7a2 2 0 000 2.2l.5.7a2 2 0 01-1.7 3l-.9-.1a2 2 0 00-2 1l-.4.8a2 2 0 01-3.4 0l-.4-.8a2 2 0 00-2-1l-.9.1a2 2 0 01-1.7-3l.5-.7a2 2 0 000-2.2l-.5-.7a2 2 0 011.7-3l.9.1a2 2 0 002-1zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'adjust'    => 'M4 6h16M4 12h10M4 18h6M14 10v4M18 6v12',
        'document'  => 'M6 2h9l5 5v15H6zM15 2v5h5',
        'bank'      => 'M3 21h18M3 10l9-7 9 7M5 10v11M19 10v11M9 10v11M15 10v11',
        'magnifier' => 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3',
        'lock'      => 'M5 11h14v10H5zM8 11V7a4 4 0 018 0v4',
        'refresh'   => 'M4 4v6h6M20 20v-6h-6M5 14a8 8 0 0014 4M19 10A8 8 0 005 6',
        'note'      => 'M6 2h12v20H6zM9 6h6M9 10h6M9 14h4',
        'bell'      => 'M6 16V11a6 6 0 1112 0v5l2 2H4zM10 21a2 2 0 004 0',
    ];

@endphp

@if (! empty($menu))
<aside class="border-r shrink-0 transition-all"
       :class="sidebarOpen ? 'w-64' : 'w-16'"
       style="background-color: var(--color-surface); border-color: var(--color-surface-border);">

    <div class="h-14 flex items-center gap-2 px-4 border-b" style="border-color: var(--color-surface-border);">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold tracking-tight"
           title="Kembali ke menu utama">
            <span class="inline-grid place-items-center w-8 h-8 rounded text-white"
                  style="background-color: {{ $moduleColor }};">J</span>
            <span x-show="sidebarOpen" x-cloak class="text-sm leading-tight">
                <span class="block" style="color: var(--color-text);">{{ $moduleName }}</span>
                <span class="block text-xs font-normal" style="color: var(--color-text-muted);">Java Core</span>
            </span>
        </a>
    </div>

    <a href="{{ route('dashboard') }}"
       class="flex items-center gap-3 mx-2 mt-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
       style="color: var(--color-text-muted);"
       onmouseover="this.style.backgroundColor='var(--color-surface-muted)'; this.style.color='var(--color-text)';"
       onmouseout="this.style.backgroundColor=''; this.style.color='var(--color-text-muted)';">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 19l-7-7 7-7"/>
        </svg>
        <span x-show="sidebarOpen" x-cloak>Menu utama</span>
    </a>

    <div class="mx-3 my-2 border-t" style="border-color: var(--color-surface-border);"></div>

    <nav class="p-2 flex flex-col gap-1">
        @foreach ($menu as $item)
            @php
                $href = $item['href'];
                $exact = request()->is(ltrim($href, '/'));
                $active = $exact || ($href !== '/'.$segment && request()->is(trim($href, '/').'/*'));
                $iconPath = $svgs[$item['icon'] ?? 'cog'] ?? $svgs['cog'];
            @endphp
            <a href="{{ $href }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
               @style([
                  'background-color: var(--color-brand-500); color: white;' => $active,
                  'color: var(--color-text);' => ! $active,
               ])
               @if (! $active) onmouseover="this.style.backgroundColor='var(--color-surface-muted)'" onmouseout="this.style.backgroundColor=''" @endif>
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="{{ $iconPath }}"/>
                </svg>
                <span x-show="sidebarOpen" x-cloak>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
@endif
