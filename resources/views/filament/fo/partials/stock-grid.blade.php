{{-- Tabel stok visual. Var: $grid (dari stockGridData). Klik sel TERSEDIA → selectGridUnit($id). --}}
<div>
    @if (empty($grid['rows']))
        <div class="rounded-lg border p-6 text-center text-sm text-gray-500" style="border-color: rgb(148 163 184 / .3);">
            Pilih <strong>cluster</strong> (dan tahap) untuk menampilkan tabel stok.
        </div>
    @else
        <div class="mb-3 flex flex-wrap items-center gap-4 text-xs">
            @foreach (['#16a34a' => 'Tersedia (klik)', '#eab308' => 'Booking', '#dc2626' => 'Terjual', '#2563eb' => 'Unit ini'] as $c => $lbl)
                <span class="inline-flex items-center gap-1">
                    <span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:{{ $c }};"></span>{{ $lbl }}
                </span>
            @endforeach
            <span class="text-gray-500">· Total: {{ $grid['total'] }} unit</span>
        </div>

        <div class="overflow-x-auto">
            <table style="border-collapse:separate;border-spacing:3px;">
                <thead>
                    <tr>
                        <th style="background:#475569;color:#fff;padding:6px 10px;font-size:11px;border-radius:3px;">Blok/No.</th>
                        @foreach ($grid['cols'] as $c)
                            <th style="background:#475569;color:#fff;padding:6px 8px;font-size:11px;border-radius:3px;min-width:36px;">{{ $c }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid['rows'] as $r)
                        <tr>
                            <th style="background:#475569;color:#fff;padding:6px 10px;font-size:11px;border-radius:3px;text-align:center;">{{ $r }}</th>
                            @foreach ($grid['cols'] as $c)
                                @php $cell = $grid['cells'][$r.'|'.$c] ?? null; @endphp
                                <td style="padding:0;">
                                    @if ($cell)
                                        @php
                                            [$bg, $click] = match ($cell['status']) {
                                                'TERSEDIA' => ['#16a34a', true],
                                                'BOOKING' => ['#eab308', false],
                                                'TERJUAL' => ['#dc2626', false],
                                                'INI' => ['#2563eb', false],
                                                default => ['#9ca3af', false],
                                            };
                                        @endphp
                                        <button type="button"
                                            @if ($click) wire:click="selectGridUnit({{ $cell['id'] }})" @else disabled @endif
                                            title="No. {{ $cell['no'] }} — {{ $cell['status'] }}{{ $cell['harga'] ? ' — Rp '.number_format($cell['harga'], 0, ',', '.') : '' }}"
                                            style="width:100%;min-width:36px;padding:7px 4px;color:#fff;font-size:11px;font-weight:700;border:none;border-radius:3px;background:{{ $bg }};cursor:{{ $click ? 'pointer' : 'not-allowed' }};{{ $click ? '' : 'opacity:.92;' }}">
                                            {{ $cell['no'] }}
                                        </button>
                                    @else
                                        <div style="min-width:36px;height:30px;"></div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
