<div class="space-y-3">
    {{-- Surat Peringatan tercetak (bisa dicetak ulang) --}}
    @foreach ($spLetters ?? [] as $sp)
        <div class="rounded-lg border border-amber-300 dark:border-amber-500/30 bg-amber-50/50 dark:bg-amber-900/10 p-3 text-sm">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold">
                    {{ $sp->level_label }}
                    @if ($sp->no_surat) <span class="text-gray-400">· {{ $sp->no_surat }}</span> @endif
                </span>
                <a href="{{ route('fo.cetak.sp', $sp) }}" target="_blank"
                   class="text-xs px-2 py-0.5 rounded-full bg-primary-600 text-white hover:bg-primary-500">Cetak</a>
            </div>
            <div class="text-xs text-gray-500 mt-1">
                {{ optional($sp->tgl_surat)->format('d M Y') }}
                · {{ count($sp->items ?? []) }} item
                · kekurangan Rp {{ number_format((float) $sp->total_kekurangan, 0, ',', '.') }}
                @if ((float) $sp->total_denda > 0) · denda Rp {{ number_format((float) $sp->total_denda, 0, ',', '.') }} @endif
            </div>
        </div>
    @endforeach

    {{-- Pengiriman Email / WhatsApp (reminder & SP otomatis) --}}
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
        @if (empty($spLetters) || count($spLetters) === 0)
            <p class="text-sm text-gray-500">Belum ada surat untuk kontrak ini.</p>
        @endif
    @endforelse
</div>
