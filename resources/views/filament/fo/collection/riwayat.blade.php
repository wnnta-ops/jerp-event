<div class="space-y-3">
    @forelse ($notices as $n)
        <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 text-sm">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold">
                    {{ $n->jenis_label }}
                    @if ($n->no_surat) <span class="text-gray-400">· {{ $n->no_surat }}</span> @endif
                </span>
                <span class="text-xs px-2 py-0.5 rounded-full
                    {{ $n->status === 'TERKIRIM' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300' }}">
                    {{ $n->channel }} · {{ $n->status }}
                </span>
            </div>
            <div class="text-xs text-gray-500 mt-1">
                {{ optional($n->dikirim_pada)->format('d M Y H:i') }}
                · ke: {{ $n->penerima ?: '—' }}
                @if ($n->hari_telat) · telat {{ $n->hari_telat }} hari @endif
            </div>
            @if ($n->subjek)
                <div class="mt-2 font-medium">{{ $n->subjek }}</div>
            @endif
            @if ($n->isi)
                <div class="mt-1 text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($n->isi, 400) }}</div>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Belum ada surat penagihan yang dikirim untuk kontrak ini.</p>
    @endforelse
</div>
