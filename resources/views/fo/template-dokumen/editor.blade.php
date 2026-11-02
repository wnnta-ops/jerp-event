{{-- Bingkai editor visual Template Dokumen — disisipkan ke pdf.fo.spr / pdf.fo.sprs bila $editor ada.
     Dokumennya sendiri dirender oleh template cetak; berkas ini hanya menambah bilah atas,
     tampilan lembar A4, dan skrip edit. Lihat TemplateDokumenEditorController. --}}
@php
    $rec = $editor['record'];
    $baru = $rec === null;
    $judulCluster = $editor['lokasi_id'] ? ($editor['lokasi'][$editor['lokasi_id']] ?? '—') : 'Semua cluster';
    $judulTahap = collect($editor['tahap'])->firstWhere('id', $editor['tahap_id'])['label'] ?? 'Semua tahap';
@endphp
<style>
    html { background: #e5e7eb; }
    body { background: #e5e7eb !important; padding: 132px 0 60px !important; counter-reset: hal; }
    .actions { display: none !important; }
    .page { position: relative; width: 210mm; min-height: 297mm; margin: 0 auto 34px; padding: 14mm 14mm 12mm;
        background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15); counter-increment: hal; page-break-after: auto; }
    .page::before { content: 'Halaman ' counter(hal); position: absolute; top: -20px; left: 0; font: 600 11px Arial, sans-serif; color: #6b7280; }
    .page.lebih { outline: 2px solid #dc2626; }
    .page.lebih::after { content: 'Isi melebihi 1 lembar A4 — pindahkan sebagian pasal ke halaman baru'; position: absolute; top: -20px; right: 0; font: 600 11px Arial, sans-serif; color: #dc2626; }
    .ed { outline: 1px dashed #f59e0b; outline-offset: 1px; background: #fffbeb; border-radius: 2px; min-width: 24px; display: inline; cursor: text; }
    .ed:hover { background: #fef3c7; }
    .ed:focus { outline: 2px solid #ea7a1d; background: #fff; }
    .ed:empty::before { content: '+ teks'; color: #9ca3af; font-style: italic; font-size: 10px; }
    .ed-item { position: relative; }
    .ctl { position: absolute; right: 0; top: -17px; display: none; gap: 2px; z-index: 5; white-space: nowrap; }
    .ed-item:hover > .ctl, .ed-item:focus-within > .ctl { display: flex; }
    /* Satu deret tombol saja: yang disorot mouse menang atas yang sedang diketik. */
    body:has(.ed-item:hover) .ed-item:focus-within:not(:hover) > .ctl { display: none; }
    .ctl button, .ed-add { font: 600 10px Arial, sans-serif; padding: 2px 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 4px; cursor: pointer; }
    .ctl button:hover, .ed-add:hover { background: #ea7a1d; color: #fff; border-color: #ea7a1d; }
    .ctl button[data-op=hapus]:hover { background: #dc2626; border-color: #dc2626; }
    #ed-bar { position: fixed; top: 0; left: 0; right: 0; z-index: 50; background: #fff; border-bottom: 1px solid #d1d5db; box-shadow: 0 1px 4px rgba(0,0,0,.08);
        font: 12px Arial, Helvetica, sans-serif; color: #111; padding: 8px 16px; }
    #ed-bar .baris { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; }
    #ed-bar label { display: flex; align-items: center; gap: 5px; font-weight: 600; color: #374151; }
    #ed-bar select, #ed-bar input { font: 12px Arial, sans-serif; padding: 4px 6px; border: 1px solid #d1d5db; border-radius: 5px; background: #fff; }
    #ed-bar select:disabled { background: #f3f4f6; color: #6b7280; }
    #ed-bar .judul { font-weight: 800; font-size: 13px; }
    #ed-bar .kanan { margin-left: auto; display: flex; gap: 6px; }
    #ed-bar .tb { padding: 6px 12px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; text-decoration: none; font-weight: 600; cursor: pointer; font-size: 12px; }
    #ed-bar .tb.utama { background: #ea7a1d; border-color: #ea7a1d; color: #fff; }
    #ed-bar .info { margin-top: 6px; padding: 5px 9px; border: 1px solid #fcd34d; background: #fffbeb; border-radius: 5px; color: #92400e; font-size: 11.5px; }
    #ed-bar .info code { background: #fef3c7; padding: 0 3px; border-radius: 3px; }
    #ed-bar .galat { margin-top: 6px; padding: 5px 9px; border: 1px solid #fca5a5; background: #fef2f2; border-radius: 5px; color: #b91c1c; font-weight: 600; }
    @media print { #ed-bar { display: none; } }
</style>

<form id="ed-bar" method="POST" action="{{ $editor['urlKirim'] }}">
    @csrf
    <input type="hidden" name="isi" id="ed-isi">
    <input type="hidden" name="aksi" id="ed-aksi" value="muat">
    <input type="hidden" name="gulir" id="ed-gulir" value="0">
    <div class="baris">
        <a class="tb" href="{{ $editor['urlKembali'] }}">← Template Dokumen</a>
        <span class="judul">{{ $baru ? 'Versi baru' : 'Ubah '.$rec->dokumen.' v'.$rec->versi }}</span>
        <label>Dokumen
            <select name="dokumen" id="ed-dokumen" @disabled(! $baru)>
                @foreach (\App\Modules\FO\Komunikasi\Support\DokumenBawaan::pilihan() as $k => $l)
                    <option value="{{ $k }}" @selected($editor['dokumen'] === $k)>{{ $k }}</option>
                @endforeach
            </select>
        </label>
        <label>Cluster
            <select name="lokasi_id" id="ed-lokasi" @disabled(! $baru)>
                <option value="">Semua cluster</option>
                @foreach ($editor['lokasi'] as $id => $nama)
                    <option value="{{ $id }}" @selected((int) $editor['lokasi_id'] === (int) $id)>{{ $nama }}</option>
                @endforeach
            </select>
        </label>
        <label>Tahap
            <select name="tahap_id" id="ed-tahap" @disabled(! $baru || ! $editor['lokasi_id'])>
                <option value="">Semua tahap</option>
                @foreach ($editor['tahap'] as $th)
                    @if ((int) $th['lokasi_id'] === (int) $editor['lokasi_id'])
                        <option value="{{ $th['id'] }}" @selected((int) $editor['tahap_id'] === (int) $th['id'])>{{ $th['label'] }}</option>
                    @endif
                @endforeach
            </select>
        </label>
        <label>Berlaku mulai
            <input type="date" name="berlaku_mulai" value="{{ $editor['berlaku_mulai'] }}" min="{{ today()->toDateString() }}" required>
        </label>
        <label style="flex:1; min-width:220px;">Catatan
            <input type="text" name="catatan" value="{{ $editor['catatan'] }}" style="flex:1;" placeholder="mis. Pasal VIII denda jadi 1,5‰ — memo direksi 12/10/2026">
        </label>
        <div class="kanan">
            <a class="tb" href="{{ $editor['urlFormulir'] }}" title="Buka formulir lama (kotak isian)">Mode formulir</a>
            <button type="button" class="tb utama" id="ed-simpan">Simpan</button>
        </div>
    </div>
    @if ($editor['galat'])
        <div class="galat">{{ $editor['galat'] }}</div>
    @endif
    <div class="info">
        Klik teks bergaris kuning untuk mengubah · arahkan kursor ke pasal/butir untuk tombol geser, tambah, hapus, halaman baru ·
        data customer/unit/harga = contoh dari <b>{{ $editor['contoh'] }}</b> ({{ $judulCluster }}, {{ $judulTahap }}) ·
        kata kunci: @foreach (\App\Modules\FO\Komunikasi\Support\DokumenBawaan::kataKunci() as $k => $l)<code title="{{ $l }}">{{ '{'.$k.'}' }}</code> @endforeach
        · Ctrl+B / Ctrl+I / Ctrl+U untuk tebal / miring / garis bawah
    </div>
</form>

<script>
(function () {
    var isi = @json($isi);
    var form = document.getElementById('ed-bar');
    var berubah = false;
    var MM = 96 / 25.4;

    // ── Teks span → nilai isi ──
    function bersih(el) {
        if (el.dataset.polos) return el.textContent.replace(/\s+/g, ' ').trim();
        var h = el.innerHTML
            .replace(/<div[^>]*>/gi, '<br>').replace(/<\/div>/gi, '')
            .replace(/<p[^>]*>/gi, '<br>').replace(/<\/p>/gi, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/<(\/?)(b|i|u|strong|em)\b[^>]*>/gi, '<$1$2>')
            .replace(/<br[^>]*>/gi, '<br>')
            .replace(/<(?!\/?(b|i|u|strong|em|br)>)[^>]*>/gi, '');
        return h.replace(/^(\s|<br>)+|(\s|<br>)+$/g, '');
    }
    function ambil(path) { return path.split('.').reduce(function (o, k) { return o == null ? o : o[k]; }, isi); }
    function setel(path, v) {
        var ks = path.split('.'), o = isi;
        for (var i = 0; i < ks.length - 1; i++) o = o[ks[i]];
        o[ks[ks.length - 1]] = v;
    }

    // Satu nilai yang dipecah ke beberapa span (nomor | sisa judul pasal) → digabung lagi.
    function nilai(el) {
        if (!el.dataset.bagian) return bersih(el);
        var bag = document.querySelectorAll('.ed[data-k="' + el.dataset.k + '"][data-bagian]');
        return Array.prototype.map.call(bag, bersih).filter(function (v) { return v !== ''; }).join(' ');
    }

    document.querySelectorAll('.ed').forEach(function (el) {
        el.addEventListener('input', function () {
            berubah = true;
            setel(el.dataset.k, nilai(el));
            // Teks yang sama tampil di beberapa tempat (mis. label paraf tiap halaman).
            if (!el.dataset.bagian) document.querySelectorAll('.ed[data-k="' + el.dataset.k + '"]').forEach(function (lain) {
                if (lain !== el) lain.textContent = el.textContent;
            });
            cekHalaman();
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && el.dataset.polos) e.preventDefault();
        });
        // Tempel = teks polos (format dari Word/web tidak ikut).
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            var t = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, el.dataset.polos ? t.replace(/\s+/g, ' ') : t);
        });
    });

    // Teks contoh ("Isi pasal …") langsung terpilih saat diklik → tinggal diketik, tak perlu dihapus dulu.
    function pilihSemua(el) {
        var r = document.createRange();
        r.selectNodeContents(el);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
    }
    document.querySelectorAll('.ed').forEach(function (el) {
        el.addEventListener('focus', function () {
            if (/…$/.test(el.textContent.trim())) setTimeout(function () { pilihSemua(el); }, 0);
        });
    });
    function fokusBaru() {
        var k = null;
        try { k = sessionStorage.getItem('ed-fokus'); sessionStorage.removeItem('ed-fokus'); } catch (_) {}
        var el = k && document.querySelector('.ed[data-k="' + k + '"]');
        if (!el) return;
        el.scrollIntoView({ block: 'center' });
        el.focus();
    }

    // ── Tambah / hapus / geser / halaman baru → ubah isi lalu tampilkan ulang ──
    // ── Nomor pasal otomatis (seperti Word): sesudah tambah/hapus/geser, nomor romawi di awal judul
    // diurutkan ulang mulai dari nomor pasal bernomor pertama. Hanya versi yang sedang diedit.
    var RE_NO = /^(\s*(?:<[^>]+>)*\s*)([IVXLCDM]+)(\.|(?=\s*$))/;
    function dariRomawi(s) {
        var v = { I: 1, V: 5, X: 10, L: 50, C: 100, D: 500, M: 1000 }, n = 0;
        for (var i = 0; i < s.length; i++) n += v[s[i]] < (v[s[i + 1]] || 0) ? -v[s[i]] : v[s[i]];
        return n;
    }
    function keRomawi(n) {
        var s = '';
        [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'],
            [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']].forEach(function (p) {
            while (n >= p[0]) { s += p[1]; n -= p[0]; }
        });
        return s;
    }
    function nomorAwal(pasal) {
        for (var i = 0; i < pasal.length; i++) {
            var m = RE_NO.exec(pasal[i].judul || '');
            if (m) return dariRomawi(m[2]);
        }
        return 0;
    }
    function nomoriPasal(pasal, awal) {
        if (!awal) return;
        pasal.forEach(function (p) {
            var m = RE_NO.exec(p.judul || '');
            if (m) p.judul = p.judul.replace(RE_NO, m[1] + keRomawi(awal++) + '.');
        });
    }

    function kirim(aksi) {
        document.getElementById('ed-isi').value = JSON.stringify(isi);
        document.getElementById('ed-aksi').value = aksi;
        document.getElementById('ed-gulir').value = Math.round(window.scrollY);
        berubah = false;
        form.submit();
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-op]');
        if (!b) return;
        e.preventDefault();
        var daftar = ambil(b.dataset.list), i = parseInt(b.dataset.i, 10), op = b.dataset.op;
        if (!Array.isArray(daftar)) { setel(b.dataset.list, []); daftar = ambil(b.dataset.list); }
        var pasal = b.dataset.list === 'pasal', awal = pasal ? nomorAwal(daftar) : 0, fokus = null;
        if (op === 'hapus') {
            daftar.splice(i, 1);
        } else if (op === 'naik' && i > 0) {
            daftar.splice(i - 1, 0, daftar.splice(i, 1)[0]);
        } else if (op === 'turun' && i < daftar.length - 1) {
            daftar.splice(i + 1, 0, daftar.splice(i, 1)[0]);
        } else if (op === 'tambah') {
            // Pasal baru ikut bernomor bila pasal lain bernomor; nomornya dirapikan di bawah.
            daftar.splice(i + 1, 0, pasal
                ? { judul: awal ? 'I. Judul pasal …' : 'Judul pasal …', isi: 'Isi pasal …', butir: [], halaman_baru: false } : 'Teks baru …');
            fokus = b.dataset.list + '.' + (i + 1) + (pasal ? '.isi' : '');
        } else if (op === 'halaman') {
            daftar[i].halaman_baru = !daftar[i].halaman_baru;
        } else if (op === 'butir') {
            daftar[i].butir = daftar[i].butir || [];
            daftar[i].butir.push('Butir baru …');
            fokus = b.dataset.list + '.' + i + '.butir.' + (daftar[i].butir.length - 1);
        } else {
            return;
        }
        if (pasal && op !== 'halaman' && op !== 'butir') nomoriPasal(daftar, awal);
        // Sesudah dimuat ulang, kursor langsung di teks yang baru ditambah.
        try { if (fokus) sessionStorage.setItem('ed-fokus', fokus); } catch (_) {}
        kirim('muat');
    });

    document.getElementById('ed-simpan').addEventListener('click', function () {
        if (!form.reportValidity()) return;
        kirim('simpan');
    });

    // Versi baru: ganti dokumen/cluster/tahap = mulai dari teks yang berlaku untuk pilihan itu.
    ['ed-dokumen', 'ed-lokasi', 'ed-tahap'].forEach(function (id) {
        var s = document.getElementById(id);
        if (!s || s.disabled) return;
        s.addEventListener('change', function () {
            if (berubah && !confirm('Perubahan teks yang belum disimpan akan hilang. Lanjutkan?')) { location.reload(); return; }
            var q = new URLSearchParams();
            q.set('dokumen', document.getElementById('ed-dokumen').value);
            var lok = document.getElementById('ed-lokasi').value;
            if (lok) q.set('lokasi_id', lok);
            if (lok && id === 'ed-tahap' && s.value) q.set('tahap_id', s.value);
            location.href = @json($editor['urlBaru']) + '?' + q.toString();
        });
    });

    window.addEventListener('beforeunload', function (e) { if (berubah) { e.preventDefault(); e.returnValue = ''; } });

    // Tandai lembar yang isinya melebihi tinggi A4 (dihitung dari lembar layar 297 mm).
    function cekHalaman() {
        document.querySelectorAll('.page').forEach(function (p) {
            p.classList.toggle('lebih', p.scrollHeight > 297 * MM + 2);
        });
    }
    cekHalaman();
    window.addEventListener('load', function () { cekHalaman(); window.scrollTo(0, {{ (int) $editor['gulir'] }}); fokusBaru(); });
})();
</script>
