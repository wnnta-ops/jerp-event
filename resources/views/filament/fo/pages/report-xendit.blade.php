<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $sum = $this->getSummary();
        $rp = fn ($x) => 'Rp '.number_format((float) $x, 0, ',', '.');
        $tdTxt = 'px-2 py-1.5 whitespace-nowrap';
        $tdNum = 'px-2 py-1.5 text-right font-mono whitespace-nowrap';
        $badge = fn (string $cls, string $text) => "<span class=\"rounded-full px-2 py-0.5 text-[11px] font-semibold {$cls}\">{$text}</span>";
    @endphp

    {{-- Grid via CSS sendiri: kelas Tailwind grid-cols-* tidak ter-compile di CSS panel. --}}
    <style>
        .rx-filter { display: grid; gap: .75rem; grid-template-columns: 1fr; }
        @media (min-width: 640px)  { .rx-filter { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media (min-width: 1024px) { .rx-filter { grid-template-columns: repeat(6, minmax(0,1fr)); } .rx-span2 { grid-column: span 2 / span 2; } }
        .rx-stats { display: grid; gap: .75rem; grid-template-columns: repeat(2, minmax(0,1fr)); }
        @media (min-width: 768px)  { .rx-stats { grid-template-columns: repeat(5, minmax(0,1fr)); } }
    </style>

    {{-- Filter --}}
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="rx-filter">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select wire:model.live="status" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Semua</option>
                    <option value="PENDING">Pending (menunggu bayar)</option>
                    <option value="PAID">Paid (lunas)</option>
                    <option value="EXPIRED">Expired</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Daftar Dari</label>
                <input type="date" wire:model.live="from" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Sampai</label>
                <input type="date" wire:model.live="to" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
            </div>
            <div class="rx-span2">
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Cari</label>
                <input type="text" wire:model.live.debounce.500ms="q" placeholder="No NUP / nama customer / ref Xendit"
                       class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
            </div>
            <div class="flex items-end gap-2">
                <x-filament::button wire:click="sinkron" wire:loading.attr="disabled" icon="heroicon-o-arrow-path" color="warning">
                    <span wire:loading.remove wire:target="sinkron">Sinkron Xendit</span>
                    <span wire:loading wire:target="sinkron">Mengecek…</span>
                </x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getExportUrl() }}" target="_blank" color="success" icon="heroicon-o-arrow-down-tray">
                    Export Excel
                </x-filament::button>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Sinkron mengecek invoice <b>PENDING</b> (maks {{ \App\Filament\Fo\Pages\ReportXendit::SYNC_LIMIT }}/klik) langsung ke Xendit —
            yang sudah dibayar otomatis ditandai LUNAS (kwitansi &amp; jurnal ikut terbentuk). Baris "SIMULASI" = invoice era uji coba (bukan Xendit asli).
        </p>
    </div>

    {{-- Ringkasan --}}
    <div class="rx-stats">
        @foreach ([
            ['Total Invoice', number_format($sum['total']), 'text-gray-900 dark:text-white'],
            ['Lunas', number_format($sum['paid']), 'text-emerald-600 dark:text-emerald-400'],
            ['Nominal Lunas', $rp($sum['paid_rp']), 'text-emerald-600 dark:text-emerald-400'],
            ['Pending', number_format($sum['pending']), 'text-amber-600 dark:text-amber-400'],
            ['Expired / Simulasi', number_format($sum['expired']).' / '.number_format($sum['simulasi']), 'text-gray-500 dark:text-gray-400'],
        ] as [$label, $val, $cls])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-lg font-bold {{ $cls }}">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- Tabel pencocokan --}}
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pencocokan Pembayaran Xendit ↔ Customer</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($sum['total']) }} invoice
                @if ($sum['total'] > $rows->count()) · tampil {{ $rows->count() }} terbaru @endif
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-primary-600 text-left text-white">
                        <th class="px-2 py-2">No.</th>
                        <th class="px-2 py-2">Tgl Daftar</th>
                        <th class="px-2 py-2">No NUP</th>
                        <th class="px-2 py-2">Customer</th>
                        <th class="px-2 py-2">Event</th>
                        <th class="px-2 py-2">Ref Xendit</th>
                        <th class="px-2 py-2 text-right">Nilai UTJ</th>
                        <th class="px-2 py-2">Status</th>
                        <th class="px-2 py-2">Tgl Bayar</th>
                        <th class="px-2 py-2">Pencocokan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($rows as $i => $r)
                        @php
                            $fake = str_starts_with((string) $r->xendit_invoice_id, 'fake_');
                            $paid = $r->isUtjPaid();
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="{{ $tdTxt }}">{{ $i + 1 }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="{{ $tdTxt }} font-semibold">{{ $r->no_nup ?: '—' }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->customer?->full_name ?: $r->nama_pendaftar }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->event?->name ?: '—' }}</td>
                            <td class="{{ $tdTxt }} font-mono">{{ $r->xendit_external_id }}</td>
                            <td class="{{ $tdNum }}">{{ $rp($r->utj_amount) }}</td>
                            <td class="{{ $tdTxt }}">
                                @if ($paid) {!! $badge('bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300', 'LUNAS') !!}
                                @elseif ($r->xendit_status === 'EXPIRED') {!! $badge('bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300', 'EXPIRED') !!}
                                @else {!! $badge('bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300', $r->xendit_status ?: 'PENDING') !!}
                                @endif
                            </td>
                            <td class="{{ $tdTxt }}">{{ $r->xendit_paid_at?->format('d/m/Y H:i') ?: '—' }}</td>
                            <td class="{{ $tdTxt }}">
                                @if ($fake) {!! $badge('bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300', 'SIMULASI') !!}
                                @elseif ($paid) {!! $badge('bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300', 'KLOP') !!}
                                @elseif ($r->xendit_status === 'EXPIRED') {!! $badge('bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300', 'TIDAK DIBAYAR') !!}
                                @else {!! $badge('bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300', 'MENUNGGU') !!}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-3 py-8 text-center text-gray-500">Tidak ada invoice pada filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
