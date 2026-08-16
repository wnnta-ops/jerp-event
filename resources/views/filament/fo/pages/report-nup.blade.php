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
        .rn-filter { display: grid; gap: .75rem; grid-template-columns: 1fr; }
        @media (min-width: 640px)  { .rn-filter { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media (min-width: 1024px) { .rn-filter { grid-template-columns: repeat(6, minmax(0,1fr)); } .rn-span2 { grid-column: span 2 / span 2; } }
        .rn-stats { display: grid; gap: .75rem; grid-template-columns: repeat(2, minmax(0,1fr)); }
        @media (min-width: 768px)  { .rn-stats { grid-template-columns: repeat(3, minmax(0,1fr)); } }
        @media (min-width: 1280px) { .rn-stats { grid-template-columns: repeat(6, minmax(0,1fr)); } }
    </style>

    {{-- Filter --}}
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="rn-filter">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Status Verifikasi</label>
                <select wire:model.live="status" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Semua</option>
                    <option value="MENUNGGU_BAYAR">Menunggu Bayar</option>
                    <option value="PAID">Sudah Bayar</option>
                    <option value="APPROVED">Approved</option>
                    <option value="PENDING">Pending</option>
                    <option value="REJECTED">Rejected</option>
                    <option value="REFUNDED">Refunded</option>
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
            <div class="rn-span2">
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Cari</label>
                <input type="text" wire:model.live.debounce.500ms="q" placeholder="No NUP / nama customer / sales / ref Xendit"
                       class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
            </div>
            <div class="flex items-end">
                <x-filament::button tag="a" href="{{ $this->getExportUrl() }}" target="_blank" color="success" icon="heroicon-o-arrow-down-tray">
                    Export Excel
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- Ringkasan --}}
    <div class="rn-stats">
        @foreach ([
            ['Total Pendaftaran', number_format($sum['total']), 'text-gray-900 dark:text-white'],
            ['Sudah Bayar', number_format($sum['lunas']), 'text-emerald-600 dark:text-emerald-400'],
            ['Nominal Masuk', $rp($sum['lunas_rp']), 'text-emerald-600 dark:text-emerald-400'],
            ['Menunggu Bayar', number_format($sum['menunggu']), 'text-amber-600 dark:text-amber-400'],
            ['Approved', number_format($sum['approved']), 'text-blue-600 dark:text-blue-400'],
            ['Tanpa Invoice', number_format($sum['tanpa_invoice']), $sum['tanpa_invoice'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'],
        ] as [$label, $val, $cls])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-lg font-bold {{ $cls }}">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    @if ($sum['tanpa_invoice'] > 0)
        {{-- Peringatan yang berdiri sendiri: pendaftaran tanpa invoice berarti customer
             TIDAK punya tautan bayar, dan itu tidak terlihat di layar mana pun. --}}
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
            <b>{{ number_format($sum['tanpa_invoice']) }} pendaftaran tanpa invoice Xendit.</b>
            Customer tersebut tidak menerima tautan pembayaran — biasanya karena alamat email yang diketik tidak sah.
            Perbaiki emailnya lewat <b>Verifikasi Data → Edit SPRS</b>, lalu terbitkan tagihannya dengan <b>Payment Xendit</b>.
        </div>
    @endif

    {{-- Tabel --}}
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Rekap Pendaftaran NUP</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($sum['total']) }} pendaftaran
                @if ($sum['total'] > $rows->count()) · tampil {{ $rows->count() }} terbaru (export memuat semuanya) @endif
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50 text-left text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                        <th class="px-2 py-2">No.</th>
                        <th class="px-2 py-2">Tgl Daftar</th>
                        <th class="px-2 py-2">No NUP</th>
                        <th class="px-2 py-2">Customer</th>
                        <th class="px-2 py-2">No. Telp</th>
                        <th class="px-2 py-2">Sales</th>
                        <th class="px-2 py-2">Agent</th>
                        <th class="px-2 py-2">Cara Bayar</th>
                        <th class="px-2 py-2">Bank</th>
                        <th class="px-2 py-2">Pilihan Unit</th>
                        <th class="px-2 py-2">Ref Xendit</th>
                        <th class="px-2 py-2 text-right">Nilai UTJ</th>
                        <th class="px-2 py-2 text-right">Transfer Xendit</th>
                        <th class="px-2 py-2 text-right">Selisih</th>
                        <th class="px-2 py-2">Status</th>
                        <th class="px-2 py-2">Tgl Bayar</th>
                        <th class="px-2 py-2">Pencocokan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($rows as $i => $r)
                        @php
                            $lunas = $r->isUtjPaid();
                            $recon = $r->reconStatus();
                            $selisih = $r->xenditSelisih();
                            $reconCls = match ($recon) {
                                'SESUAI' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
                                'SELISIH' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
                                'SIMULASI' => 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-400',
                                'TIDAK DIBAYAR' => 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300',
                                default => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="{{ $tdTxt }} text-gray-400">{{ $i + 1 }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="{{ $tdTxt }} font-semibold">{{ $r->no_nup ?: '-' }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->customer?->full_name ?: $r->nama_pendaftar }}</td>
                            {{-- Telepon pendaftaran didahulukan atas master customer: itu nomor
                                 yang benar-benar dipakai mengirim WA untuk pendaftaran ini. --}}
                            <td class="{{ $tdTxt }} font-mono">{{ $r->phone ?: ($r->customer?->mobile ?: '-') }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->ref_sales_name ?: '-' }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->agent?->name ?: '-' }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->cara_bayar ?: '-' }}</td>
                            <td class="{{ $tdTxt }}">{{ $r->bank?->nama ?: ($r->bank_nama ?: '-') }}</td>
                            <td class="px-2 py-1.5">{{ \App\Filament\Fo\Pages\ReportNup::pilihanUnit($r) }}</td>
                            <td class="{{ $tdTxt }} font-mono">{{ $r->xendit_external_id ?: '-' }}</td>
                            <td class="{{ $tdNum }}">{{ number_format((float) $r->utj_amount, 0, ',', '.') }}</td>
                            <td class="{{ $tdNum }}">{{ $r->xendit_paid_amount !== null ? number_format((float) $r->xendit_paid_amount, 0, ',', '.') : '-' }}</td>
                            <td class="{{ $tdNum }} {{ $selisih !== null && abs($selisih) >= 0.01 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ $selisih === null ? '-' : number_format($selisih, 0, ',', '.') }}
                            </td>
                            <td class="{{ $tdTxt }}">
                                {!! $lunas
                                    ? $badge('bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400', 'LUNAS')
                                    : $badge('bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300', $r->verif_status ?: '-') !!}
                            </td>
                            <td class="{{ $tdTxt }}">{{ $r->xendit_paid_at?->format('d/m/Y H:i') ?: '-' }}</td>
                            <td class="{{ $tdTxt }}">{!! $badge($reconCls, $recon) !!}</td>
                        </tr>
                    @empty
                        <tr><td colspan="17" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                            Tidak ada pendaftaran pada rentang ini.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
