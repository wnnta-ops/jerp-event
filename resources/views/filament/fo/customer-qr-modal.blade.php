{{-- Modal QR Customer. Tombol "Cetak" mencetak LANGSUNG via iframe tersembunyi
     (tanpa membuka tab/jendela baru). Logika cetak ditaruh di x-data Alpine —
     hindari literal tag di dalam <script> (jebakan MultipleRootElements). --}}
<div class="text-center space-y-3"
     x-data="{
        cetak() {
            const area = this.$refs.printArea;
            if (! area) return;
            const f = document.createElement('iframe');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
            document.body.appendChild(f);
            const d = f.contentWindow.document;
            d.body.style.textAlign = 'center';
            d.body.style.fontFamily = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';
            d.body.innerHTML = area.innerHTML;
            setTimeout(() => { f.contentWindow.focus(); f.contentWindow.print(); }, 150);
            setTimeout(() => f.remove(), 1500);
        }
     }">
    <div x-ref="printArea" style="text-align:center;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;">
        <div style="width:240px;height:240px;margin:0 auto;display:flex;align-items:center;justify-content:center;">{!! $svg !!}</div>
        <div style="font-family:ui-monospace,monospace;font-weight:800;font-size:20px;letter-spacing:1px;color:#1e40af;margin-top:8px;">{{ $code }}</div>
        {{-- Kelas adaptif utk layar (terlihat di dark-mode); di iframe cetak kelas
             Tailwind tak aktif → mewarisi warna gelap wrapper = terbaca di kertas. --}}
        <div class="text-gray-900 dark:text-white" style="font-size:16px;font-weight:700;margin-top:6px;">{{ $customer->full_name }}</div>
        @if ($noNup)
            <div style="display:inline-block;margin-top:8px;background:#dbeafe;color:#1e40af;font-weight:700;padding:4px 12px;border-radius:999px;font-size:13px;">No. NUP: {{ $noNup }}</div>
        @endif
    </div>

    <p class="text-xs text-gray-400">Isi QR = kode customer. Saat di-scan di Pilih Unit, sistem menarik data terbaru dari database.</p>

    <button type="button" x-on:click="cetak()"
            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
        Cetak
    </button>
</div>
