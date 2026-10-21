<x-filament-panels::page>
    @php
        $aktif = $this->getAktif();
        $hitung = $this->getHitung();
    @endphp

    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-center gap-3">
            @if ($aktif)
                <x-filament::badge color="success">Aktif</x-filament::badge>
                <span class="text-sm text-gray-600 dark:text-gray-300">Perubahan SPR dikirim ke <span class="font-mono">{{ $this->getTujuan() }}</span></span>
            @else
                <x-filament::badge color="gray">Mati</x-filament::badge>
                <span class="text-sm text-gray-600 dark:text-gray-300">Isi <span class="font-mono">SURVEI_WEBHOOK_URL</span> &amp; <span class="font-mono">SURVEI_WEBHOOK_SECRET</span> di <span class="font-mono">.env</span> server untuk mengaktifkan.</span>
            @endif
        </div>
        <div class="mt-3 flex flex-wrap gap-4 text-sm">
            @foreach (\App\Modules\FO\Survei\Models\WebhookKeluar::STATUS as $k => $label)
                <span>{{ $label }}: <b>{{ number_format($hitung[$k] ?? 0, 0, ',', '.') }}</b></span>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-500">
            Dikirim tiap SPR dibuat, diubah (nama customer / unit / status), dibatalkan, atau dihapus — termasuk yang masuk dari sinkron eProperty.
            Gagal sementara diulang otomatis (1 m, 5 m, 15 m, 1 j, 3 j, 6 j). Isinya hanya No. SPR, nama, unit, dan status.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
