<div class="text-center space-y-3">
    <div class="mx-auto flex items-center justify-center" style="width:240px;height:240px;">
        <div style="width:240px;height:240px;">{!! $svg !!}</div>
    </div>
    <div class="font-mono text-lg font-bold text-primary-600">{{ $code }}</div>
    <div class="text-sm text-gray-600 dark:text-gray-300">{{ $customer->full_name }}</div>
    @if ($noNup)
        <div class="inline-block rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary-700">No. NUP: {{ $noNup }}</div>
    @endif
    <p class="text-xs text-gray-400">Isi QR = kode customer. Saat di-scan di Pilih Unit, sistem menarik data terbaru dari database.</p>
    <a href="{{ route('fo.customer.qr', $customer) }}" target="_blank"
       class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
        Buka kartu cetak
    </a>
</div>
