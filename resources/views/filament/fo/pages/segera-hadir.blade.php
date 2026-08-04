<x-filament-panels::page>
    <div class="mx-auto max-w-xl py-10 text-center">
        <div class="mx-auto mb-5 grid h-16 w-16 place-items-center rounded-2xl"
             style="background:#ff8c00;">
            <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085" />
            </svg>
        </div>

        @if ($modul)
            <div class="text-xs font-semibold uppercase tracking-wider" style="color:#ff8c00;">{{ $modul }}</div>
        @endif
        <h2 class="mt-1 text-2xl font-bold">{{ $fitur ?: 'Fitur' }}</h2>

        <p class="mx-auto mt-3 max-w-md text-sm text-gray-500 dark:text-gray-400">
            Menu ini sudah terpasang di struktur, tapi <strong>alur (flow)</strong> dan logikanya
            masih menunggu detail dari Anda. Begitu flow-nya diberikan, halaman ini akan diisi
            (form, tabel, perhitungan, dsb.).
        </p>

        <div class="mt-6 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm"
             style="background:rgba(255,140,0,.10); color:#c2410c;">
            🛠️ Sedang disiapkan — kirim detail flow untuk dilanjutkan
        </div>
    </div>
</x-filament-panels::page>
