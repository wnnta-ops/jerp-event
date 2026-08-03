<script setup lang="ts">
import { reactive, ref, computed, onMounted, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Printer, Plus, Trash2, RotateCcw, Save } from 'lucide-vue-next';
import PrintLayout from '@/Layouts/PrintLayout.vue';

defineOptions({ layout: PrintLayout });

const props = defineProps<{ spk: any; company: Record<string, any>; jenisOpts: Record<string, string>; draft?: any; saved?: any }>();

// ── Helpers ──
function fmtRpNum(v: any) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtRp(v: any) { return 'Rp ' + fmtRpNum(v); }
function fmtTgl(d: string | null) {
    if (!d) return '';
    const hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const bln = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const t = new Date(d);
    return `${hari[t.getDay()]}, ${String(t.getDate()).padStart(2, '0')} ${bln[t.getMonth()]} ${t.getFullYear()}`;
}
function daysBetween(a: string | null, b: string | null): number {
    if (!a || !b) return 0;
    return Math.max(0, Math.round((new Date(b).getTime() - new Date(a).getTime()) / 86400000) + 1);
}
function terbilang(num: any): string {
    let n = Math.floor(Math.abs(Number(num) || 0));
    const s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    function w(x: number): string {
        if (x < 12) return s[x];
        if (x < 20) return w(x - 10) + ' belas';
        if (x < 100) return w(Math.floor(x / 10)) + ' puluh' + (x % 10 ? ' ' + s[x % 10] : '');
        if (x < 200) return 'seratus' + (x - 100 ? ' ' + w(x - 100) : '');
        if (x < 1000) return w(Math.floor(x / 100)) + ' ratus' + (x % 100 ? ' ' + w(x % 100) : '');
        if (x < 2000) return 'seribu' + (x - 1000 ? ' ' + w(x - 1000) : '');
        if (x < 1e6) return w(Math.floor(x / 1000)) + ' ribu' + (x % 1000 ? ' ' + w(x % 1000) : '');
        if (x < 1e9) return w(Math.floor(x / 1e6)) + ' juta' + (x % 1e6 ? ' ' + w(x % 1e6) : '');
        if (x < 1e12) return w(Math.floor(x / 1e9)) + ' miliar' + (x % 1e9 ? ' ' + w(x % 1e9) : '');
        return w(Math.floor(x / 1e12)) + ' triliun' + (x % 1e12 ? ' ' + w(x % 1e12) : '');
    }
    if (n === 0) return 'nol';
    return w(n).replace(/\s+/g, ' ').trim();
}
function capit(str: string) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : str; }

const s = props.spk;
const jenisLabel = props.jenisOpts?.[s.jenis] ?? s.jenis ?? '—';
const nilai = Number(s.contract_value) || 0;
const durasi = daysBetween(s.est_start, s.est_end);
const kota = props.company?.city || 'Sidoarjo';
// Label scope SPK — "Cluster" tidak diulang bila label sudah diawali kata itu.
const clusterLabel = s.cluster ? (/^cluster/i.test(String(s.cluster)) ? String(s.cluster) : `Cluster ${s.cluster}`) : '';
const tahapLabel = s.tahap ? `Tahap ${s.tahap}` : '';

// ── Substitusi placeholder untuk draft "Set Up SPK" ──
const subMap: Record<string, string> = {
    '{pekerjaan}': s.title || '—',
    '{jenis}': jenisLabel,
    '{proyek}': s.project?.name || '—',
    '{nilai_rp}': fmtRp(nilai),
    '{nilai_terbilang}': (capit(terbilang(nilai)) + ' rupiah').trim(),
    '{ppn_note}': s.is_pkp ? `sudah termasuk PPN ${Number(s.ppn_pct)}%` : 'bersifat Non-PKP',
    '{durasi}': durasi ? String(durasi) : '……',
    '{durasi_terbilang}': durasi ? capit(terbilang(durasi)) : '……',
    '{periode_note}': (s.est_start && s.est_end) ? `terhitung sejak ${fmtTgl(s.est_start)} sampai dengan ${fmtTgl(s.est_end)}` : 'sesuai jadwal pelaksanaan',
    '{dp}': String(Number(s.advance_pct) || 0),
    '{retensi}': String(Number(s.retention_pct) || 5),
    '{profit}': String(Number(s.profit_pct) || 0),
    '{kota}': kota,
    '{tanggal}': fmtTgl(s.spk_date) || '……',
    '{cluster}': clusterLabel,
    '{tahap}': tahapLabel,
    '{no_spk}': s.no_manual || s.spk_number || '',
    '{kontraktor}': s.supplier?.name || '',
    '{perusahaan}': props.company?.name || '',
    '{ppn}': String(Number(s.ppn_pct) || 0),
    '{mulai}': fmtTgl(s.est_start) || '……',
    '{selesai}': fmtTgl(s.est_end) || '……',
};
function subst(t: any): string { return String(t ?? '').replace(/\{[a-z_]+\}/g, (m) => subMap[m] ?? m); }
// Untuk baris kepala dokumen: placeholder kosong (mis. SPK tanpa Tahap) menyisakan
// spasi ganda. TIDAK dipakai untuk badan pasal — indentasi daftar bertingkat harus utuh.
function substTight(t: any): string {
    return subst(t).replace(/[ \t]{2,}/g, ' ').replace(/ +([,.”)])/g, '$1').trim();
}

// ── Default pasal (bisa diedit / dihapus sebagian) ──
function defaultPasals() {
    const d = props.draft;
    if (d?.pasals?.length) {
        // `table` = tabel yang disisipkan di bawah pasal (diatur di Set Up SPK).
        return d.pasals.map((p: any) => ({ title: subst(p.title), body: subst(p.body), table: p.table ?? '' }));
    }
    return [
        { title: 'PASAL 1 — LINGKUP PEKERJAAN', body:
            `PIHAK PERTAMA memberikan pekerjaan kepada PIHAK KEDUA, dan PIHAK KEDUA menerima pekerjaan berupa: ${s.title || '—'}`
            + (jenisLabel ? ` (jenis: ${jenisLabel})` : '')
            + (s.project?.name ? `, berlokasi pada proyek ${s.project.name}` : '')
            + `. Rincian lingkup pekerjaan tercantum pada Rincian Anggaran Biaya (RAB) yang menjadi bagian tidak terpisahkan dari Surat Perintah Kerja ini.` },
        { title: 'PASAL 2 — NILAI PEKERJAAN', table: 'rincian', body:
            `Nilai pekerjaan disepakati sebesar ${fmtRp(nilai)} (${capit(terbilang(nilai))} rupiah)`
            + (s.is_pkp ? `, sudah termasuk PPN ${Number(s.ppn_pct)}%` : ` (Non-PKP)`)
            + `. Nilai tersebut bersifat lumpsum fixed price, sudah termasuk keuntungan/jasa dan pajak, dengan rincian sebagai berikut:` },
        { title: 'PASAL 3 — JANGKA WAKTU PELAKSANAAN', body:
            `Pekerjaan dilaksanakan dalam jangka waktu ${durasi || '……'} (${durasi ? capit(terbilang(durasi)) : '……'}) hari kalender`
            + (s.est_start && s.est_end ? `, terhitung sejak ${fmtTgl(s.est_start)} sampai dengan ${fmtTgl(s.est_end)}` : '')
            + `. Keterlambatan yang disebabkan PIHAK KEDUA menjadi tanggung jawab PIHAK KEDUA.` },
        { title: 'PASAL 4 — CARA PEMBAYARAN', body:
            `Pembayaran dilakukan secara bertahap sesuai prestasi pekerjaan (progress termin)`
            + (Number(s.advance_pct) > 0 ? ` dengan Uang Muka (DP) sebesar ${Number(s.advance_pct)}% dari nilai kontrak` : '')
            + (Number(s.retention_pct) > 0 ? `, dan retensi sebesar ${Number(s.retention_pct)}% ditahan pada setiap termin` : '')
            + `. Jadwal pembayaran termin sebagaimana tabel terlampir. Setiap pembayaran dilakukan setelah Berita Acara Pemeriksaan/Pembayaran (BAP) disetujui kedua belah pihak.` },
        { title: 'PASAL 5 — RETENSI & MASA PEMELIHARAAN', body:
            `Retensi sebesar ${Number(s.retention_pct) || 5}% dari setiap termin ditahan sebagai jaminan pemeliharaan selama 90 (sembilan puluh) hari kalender sejak Serah Terima Pertama (PHO). Retensi dicairkan setelah Serah Terima Kedua (FHO) dan seluruh kewajiban pemeliharaan diselesaikan.` },
        { title: 'PASAL 6 — DENDA KETERLAMBATAN', body:
            `Apabila PIHAK KEDUA terlambat menyelesaikan pekerjaan, dikenakan denda sebesar 1‰ (satu permil) per hari keterlambatan dari nilai kontrak, dengan denda maksimum 5% (lima persen) dari nilai kontrak.` },
        { title: 'PASAL 7 — MUTU & KESELAMATAN KERJA (K3)', body:
            `PIHAK KEDUA wajib melaksanakan pekerjaan sesuai spesifikasi teknis dan standar mutu yang disepakati, serta menerapkan Keselamatan dan Kesehatan Kerja (K3). Segala kerugian/kecelakaan akibat kelalaian PIHAK KEDUA menjadi tanggung jawab PIHAK KEDUA.` },
        { title: 'PASAL 8 — FORCE MAJEURE', body:
            `Hal-hal di luar kemampuan kedua belah pihak (bencana alam, kebijakan pemerintah, dan sejenisnya) yang mengakibatkan pekerjaan tidak dapat dilaksanakan akan diselesaikan secara musyawarah.` },
        { title: 'PASAL 9 — PENYELESAIAN PERSELISIHAN', body:
            `Segala perselisihan diselesaikan secara musyawarah untuk mufakat. Apabila tidak tercapai kesepakatan, kedua belah pihak sepakat menyelesaikannya melalui Pengadilan Negeri ${props.company?.city || '……'}.` },
        { title: 'PASAL 10 — PENUTUP', body:
            `Surat Perintah Kerja ini dibuat rangkap 2 (dua) bermaterai cukup, masing-masing mempunyai kekuatan hukum yang sama, dan berlaku sejak tanggal ditandatangani oleh kedua belah pihak.` },
    ];
}

function makeState() {
    const d = props.draft;
    return {
        judul: d?.judul || 'SURAT PERINTAH KERJA',
        subjudul: d?.subjudul || '(SPK)',
        // Kepala dokumen mengikuti draft SPK asli: baris proyek + judul pekerjaan + "Antara/Dengan".
        proyek_line: substTight(d?.proyek_line || ''),
        judul_pekerjaan: substTight(d?.judul_pekerjaan || [s.title, clusterLabel, tahapLabel].filter(Boolean).join(' ')),
        nomor: s.no_manual || s.spk_number || '',
        tanggal: s.spk_date ?? '',
        tempat: d?.tempat || kota,
        pembukaan: d?.pembukaan
            ? subst(d.pembukaan)
            : `Pada hari ini ${fmtTgl(s.spk_date) || '……'}, bertempat di ${kota}, yang bertanda tangan di bawah ini:`,
        mukadimah: substTight(d?.mukadimah || 'Kedua belah pihak sepakat mengikatkan diri dalam Surat Perjanjian Kerja dengan ketentuan sebagai berikut:'),
        pihak1: {
            nama: props.company?.signer_name || '', jabatan: props.company?.signer_title || 'Direktur',
            perusahaan: props.company?.name || '', alamat: props.company?.address || '',
            teks: substTight(d?.pihak1_teks || ''),
        },
        pihak2: {
            nama: s.supplier?.contact_person || s.supplier?.name || '', jabatan: 'Direktur',
            perusahaan: s.supplier?.name || '', alamat: [s.supplier?.address, s.supplier?.city].filter(Boolean).join(', '),
            npwp: s.supplier?.npwp || '',
            teks: substTight(d?.pihak2_teks || ''),
        },
        saksi1: ['', ''],
        saksi2: ['', ''],
        pasals: defaultPasals(),
        rincian: (s.items?.length ? s.items : [{}]).map((it: any) => ({
            uraian: it.description ?? '', volume: Number(it.qty) || 0, satuan: it.unit ?? '', harga: Number(it.unit_price) || 0,
        })),
        termin: (s.terms?.length ? s.terms : []).map((t: any) => ({
            uraian: t.description ?? '', persen: Number(t.percentage) || 0, nilai: Number(t.amount) || 0, jatuh_tempo: t.due_date ?? '',
        })),
        // Baris unit rumah (alokasi porsi kontrak SPK) — Lampiran "Rincian per Unit Rumah".
        unitRows: (s.units ?? []).map((u: any) => ({
            kode: u.unit_code || u.unit?.unit_code || '',
            tipe: u.unit?.unit_type?.name || u.unit?.unit_type?.code || '',
            harga: Number(u.unit_price) || 0,
        })),
        // Judul grup pada tabel Rincian Harga, mis. "Cluster 3 Tahap 3".
        grup: [s.cluster ? (/^cluster/i.test(String(s.cluster)) ? s.cluster : `Cluster ${s.cluster}`) : '', s.tahap ? `Tahap ${s.tahap}` : ''].filter(Boolean).join(' ') || (s.project?.name ?? ''),
        profit_pct: Number(s.profit_pct) || 0,
        showRincian: (s.items?.length ?? 0) > 0,
        showTermin: (s.terms?.length ?? 0) > 0,
        showUnit: (s.units?.length ?? 0) > 0,
    };
}

// Seed dari dokumen tersimpan (print_payload per-SPK) bila ada; jika tidak, dari draft Set Up SPK.
const hasSaved = props.saved && typeof props.saved === 'object' && Object.keys(props.saved).length > 0;
// Merge dangkal per-objek agar dokumen yang disimpan sebelum ada field baru
// (mis. pihak*.teks) tetap mendapat nilai default, bukan undefined.
function seedState() {
    const base = makeState();
    if (!hasSaved) return base;
    const sv: any = props.saved;

    return {
        ...base, ...sv,
        pihak1: { ...base.pihak1, ...(sv.pihak1 || {}) },
        pihak2: { ...base.pihak2, ...(sv.pihak2 || {}) },
    };
}
const f = reactive<any>(seedState());
function resetAll() { if (confirm('Kembalikan seluruh isi SPK ke default (draft Set Up SPK)? Semua editan akan hilang.')) { Object.assign(f, makeState()); nextTick(autosizeAll); } }

const rincianTotal = computed(() => f.rincian.reduce((sum: number, r: any) => sum + (Number(r.volume) || 0) * (Number(r.harga) || 0), 0));
function rowJumlah(r: any) { return (Number(r.volume) || 0) * (Number(r.harga) || 0); }

// ── Rekap tabel Rincian Harga (mengikuti format SPK: Sub Total 1 → Profit → Sub Total 2 → PPN → Total) ──
// Basis profit = MARKUP dari Sub Total 1, sama dengan ContractorService::profitAmount di backend.
const totalVolume = computed(() => f.rincian.reduce((n: number, r: any) => n + (Number(r.volume) || 0), 0));
const profitAmt = computed(() => Math.round(rincianTotal.value * (Number(f.profit_pct) || 0) / 100));
const subTotal2 = computed(() => rincianTotal.value + profitAmt.value);
const ppnAmt = computed(() => (s.is_pkp ? Math.round(subTotal2.value * (Number(s.ppn_pct) || 0) / 100) : 0));
const totalHarga = computed(() => subTotal2.value + ppnAmt.value);
const unitTotal = computed(() => f.unitRows.reduce((n: number, u: any) => n + (Number(u.harga) || 0), 0));

// Tabel yang sudah tampil di dalam pasal tidak diulang lagi sebagai lampiran.
const inPasal = (kind: string) => computed(() => f.pasals.some((p: any) => p.table === kind));
const rincianInPasal = inPasal('rincian');
const terminInPasal = inPasal('termin');
const unitInPasal = inPasal('unit');

function addUnitRow() { f.unitRows.push({ kode: '', tipe: '', harga: 0 }); f.showUnit = true; }
function delUnitRow(i: number) { f.unitRows.splice(i, 1); }

// Nomor lampiran mengikuti yang benar-benar tercetak (tabel yang sudah masuk pasal tidak dihitung).
const lampVisible = computed(() => {
    const arr: string[] = [];
    if (f.showRincian && !rincianInPasal.value) arr.push('rincian');
    if (f.showUnit && f.unitRows.length && !unitInPasal.value) arr.push('unit');
    if (f.showTermin && f.termin.length && !terminInPasal.value) arr.push('termin');
    return arr;
});
function lampNo(kind: string) { return lampVisible.value.indexOf(kind) + 1; }
function addRincian() { f.rincian.push({ uraian: '', volume: 0, satuan: '', harga: 0 }); }
function delRincian(i: number) { f.rincian.splice(i, 1); }
function addTermin() { f.termin.push({ uraian: '', persen: 0, nilai: 0, jatuh_tempo: '' }); }
function delTermin(i: number) { f.termin.splice(i, 1); }
function addPasal() { f.pasals.push({ title: `PASAL ${f.pasals.length + 1} — `, body: '' }); nextTick(autosizeAll); }
function delPasal(i: number) { f.pasals.splice(i, 1); }

// Auto-grow textareas so full text prints.
function autosize(e: Event) { const el = e.target as HTMLTextAreaElement; el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
function autosizeAll() { document.querySelectorAll<HTMLTextAreaElement>('.spk-doc textarea').forEach((el) => { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }); }
onMounted(() => nextTick(autosizeAll));

function cetak() { window.print(); }

// Simpan dokumen (editan) per-SPK → dipakai kembali saat membuka Cetak SPK ini lagi.
const saving = ref(false);
const justSaved = ref(false);
let savedTimer: any;
function simpan() {
    saving.value = true;
    justSaved.value = false;
    router.put(route('bs.spk.print.save', s.id), { payload: JSON.parse(JSON.stringify(f)) }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { justSaved.value = true; clearTimeout(savedTimer); savedTimer = setTimeout(() => { justSaved.value = false; }, 2500); },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <div class="spk-wrap">
        <!-- Toolbar (tidak ikut tercetak) -->
        <div class="no-print sticky top-0 z-10 mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-neutral-200 bg-white/90 p-3 backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/90">
            <Link :href="route('bs.spk.show', s.id)" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali ke SPK</Link>
            <div class="flex items-center gap-2">
                <span class="hidden text-xs text-neutral-400 sm:inline">Klik teks mana pun untuk mengedit — hanya pasal yang perlu.</span>
                <button @click="resetAll" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-4 w-4" /> Reset</button>
                <button @click="simpan" :disabled="saving" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-success-500 px-3 text-sm font-semibold text-success-600 hover:bg-success-500/10 disabled:opacity-50 dark:hover:bg-success-500/20"><Save class="h-4 w-4" /> {{ saving ? 'Menyimpan…' : (justSaved ? 'Tersimpan ✓' : 'Simpan') }}</button>
                <button @click="cetak" class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary-600 px-5 text-base font-bold text-white shadow-sm hover:bg-primary-700"><Printer class="h-5 w-5" /> Cetak</button>
            </div>
        </div>

        <!-- Dokumen SPK (A4) -->
        <div class="spk-doc mx-auto bg-white text-neutral-900 shadow-lg">
            <!-- Judul -->
            <div class="text-center">
                <input v-model="f.proyek_line" class="ed t-proyek" placeholder="PROYEK …" />
                <input v-model="f.judul" class="ed t-title" />
                <input v-model="f.subjudul" class="ed t-sub" />
                <input v-model="f.judul_pekerjaan" class="ed t-pekerjaan" placeholder="PEMBANGUNAN …" />
                <div class="mt-1 text-[13px]">Antara :</div>
                <div class="text-[13px] font-semibold">{{ f.pihak1.perusahaan }}</div>
                <div class="text-[13px]">Dengan :</div>
                <div class="text-[13px] font-semibold">{{ f.pihak2.perusahaan }}</div>
                <div class="mx-auto mt-1 h-[2px] w-40 bg-neutral-800"></div>
                <div class="mt-2 flex items-center justify-center gap-2 text-[13px]">
                    <span>Nomor:</span><input v-model="f.nomor" class="ed t-nomor" />
                </div>
            </div>

            <!-- Pembukaan -->
            <textarea v-model="f.pembukaan" @input="autosize" class="ed block mt-5" rows="1"></textarea>

            <!-- Para Pihak -->
            <div class="mt-2 space-y-3 text-[13px]">
                <div class="pihak">
                    <table class="pihak-t">
                        <tr><td class="w-8 align-top">1.</td><td class="w-40 align-top">Nama</td><td class="w-3 align-top">:</td><td><input v-model="f.pihak1.nama" class="ed w-full" /></td></tr>
                        <tr><td></td><td class="align-top">Jabatan</td><td>:</td><td><input v-model="f.pihak1.jabatan" class="ed w-full" /> — <span class="font-semibold">{{ f.pihak1.perusahaan }}</span></td></tr>
                        <tr><td></td><td class="align-top">Alamat</td><td>:</td><td><input v-model="f.pihak1.alamat" class="ed w-full" /></td></tr>
                    </table>
                    <textarea v-model="f.pihak1.teks" @input="autosize" class="ed block pl-8 text-justify" rows="1" placeholder="Paragraf kedudukan PIHAK PERTAMA (akta, dsb.)"></textarea>
                    <div class="mt-1 pl-8 italic">Selanjutnya disebut sebagai <b>PIHAK PERTAMA</b> (Pemberi Kerja).</div>
                </div>
                <div class="pihak">
                    <table class="pihak-t">
                        <tr><td class="w-8 align-top">2.</td><td class="w-40 align-top">Nama</td><td class="w-3 align-top">:</td><td><input v-model="f.pihak2.nama" class="ed w-full" /></td></tr>
                        <tr><td></td><td class="align-top">Jabatan</td><td>:</td><td><input v-model="f.pihak2.jabatan" class="ed w-full" /> — <span class="font-semibold">{{ f.pihak2.perusahaan }}</span></td></tr>
                        <tr><td></td><td class="align-top">Alamat</td><td>:</td><td><input v-model="f.pihak2.alamat" class="ed w-full" /></td></tr>
                        <tr><td></td><td class="align-top">NPWP</td><td>:</td><td><input v-model="f.pihak2.npwp" class="ed w-full" /></td></tr>
                    </table>
                    <textarea v-model="f.pihak2.teks" @input="autosize" class="ed block pl-8 text-justify" rows="1" placeholder="Paragraf kedudukan PIHAK KEDUA (akta, dsb.)"></textarea>
                    <div class="mt-1 pl-8 italic">Selanjutnya disebut sebagai <b>PIHAK KEDUA</b> (Kontraktor Pelaksana).</div>
                </div>
                <textarea v-model="f.mukadimah" @input="autosize" class="ed block text-justify" rows="1"></textarea>
            </div>

            <!-- Pasal-pasal -->
            <div class="mt-3 space-y-3">
                <div v-for="(p, i) in f.pasals" :key="i" class="group" :class="{ pasal: !p.table }">
                    <div class="flex items-start gap-1">
                        <input v-model="p.title" class="ed p-title flex-1" />
                        <button @click="delPasal(i)" class="no-print mt-0.5 text-neutral-300 hover:text-danger-500" title="Hapus pasal"><Trash2 class="h-4 w-4" /></button>
                    </div>
                    <textarea v-model="p.body" @input="autosize" class="ed block text-justify" rows="1"></textarea>

                    <!-- Tabel Rincian Harga di dalam pasal (mis. Pasal Nilai Pekerjaan) -->
                    <div v-if="p.table === 'rincian'" class="mt-2">
                        <table class="doc-table">
                            <thead><tr><th>PEKERJAAN</th><th class="w-14">SAT.</th><th class="w-14">VOL.</th><th class="w-32">HARGA SATUAN</th><th class="w-36">JUMLAH HARGA</th><th class="no-print w-6"></th></tr></thead>
                            <tbody>
                                <tr><td colspan="5"><input v-model="f.grup" class="ed w-full font-bold" placeholder="mis. Cluster 3 Tahap 3" /></td><td class="no-print"></td></tr>
                                <tr v-for="(r, ri) in f.rincian" :key="ri">
                                    <td><input v-model="r.uraian" class="ed w-full" /></td>
                                    <td><input v-model="r.satuan" class="ed w-full text-center" /></td>
                                    <td><input v-model.number="r.volume" type="number" class="ed w-full text-right" /></td>
                                    <td><input v-model.number="r.harga" type="number" class="ed w-full text-right" /></td>
                                    <td class="text-right tabular-nums">Rp. {{ fmtRpNum(rowJumlah(r)) }}</td>
                                    <td class="no-print text-center"><button @click="delRincian(ri)" class="text-neutral-300 hover:text-danger-500"><Trash2 class="h-3.5 w-3.5" /></button></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="font-bold"><td>TOTAL UNIT</td><td class="text-center">unit</td><td class="text-right tabular-nums">{{ totalVolume }}</td><td class="text-right">SUB TOTAL 1</td><td class="text-right tabular-nums">Rp. {{ fmtRpNum(rincianTotal) }}</td><td class="no-print"></td></tr>
                                <tr><td colspan="4" class="text-right">PROFIT <input v-model.number="f.profit_pct" type="number" step="0.01" class="ed w-16 text-right" />%</td><td class="text-right tabular-nums">Rp. {{ fmtRpNum(profitAmt) }}</td><td class="no-print"></td></tr>
                                <tr><td colspan="4" class="text-right">SUB TOTAL 2</td><td class="text-right tabular-nums">Rp. {{ fmtRpNum(subTotal2) }}</td><td class="no-print"></td></tr>
                                <tr><td colspan="4" class="text-right">PPN {{ Number(s.ppn_pct) || 0 }}%</td><td class="text-right tabular-nums">Rp. {{ fmtRpNum(ppnAmt) }}</td><td class="no-print"></td></tr>
                                <tr class="font-bold"><td colspan="4" class="text-right">TOTAL HARGA</td><td class="text-right tabular-nums">Rp. {{ fmtRpNum(totalHarga) }}</td><td class="no-print"></td></tr>
                            </tfoot>
                        </table>
                        <button @click="addRincian" class="no-print mt-1 inline-flex items-center gap-1 text-xs text-primary-600"><Plus class="h-3.5 w-3.5" /> Baris</button>
                    </div>

                    <!-- Tabel Rincian per Unit Rumah di dalam pasal -->
                    <div v-else-if="p.table === 'unit'" class="mt-2">
                        <table class="doc-table">
                            <thead><tr><th class="w-8">No</th><th>Kode Unit</th><th>Tipe</th><th class="w-40">Harga Borongan</th><th class="no-print w-6"></th></tr></thead>
                            <tbody>
                                <tr v-for="(u, ui) in f.unitRows" :key="ui">
                                    <td class="text-center">{{ ui + 1 }}</td>
                                    <td><input v-model="u.kode" class="ed w-full" /></td>
                                    <td><input v-model="u.tipe" class="ed w-full" /></td>
                                    <td><input v-model.number="u.harga" type="number" class="ed w-full text-right" /></td>
                                    <td class="no-print text-center"><button @click="delUnitRow(ui)" class="text-neutral-300 hover:text-danger-500"><Trash2 class="h-3.5 w-3.5" /></button></td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="font-bold"><td colspan="3" class="text-right">TOTAL {{ f.unitRows.length }} UNIT</td><td class="text-right tabular-nums">Rp. {{ fmtRpNum(unitTotal) }}</td><td class="no-print"></td></tr></tfoot>
                        </table>
                        <button @click="addUnitRow" class="no-print mt-1 inline-flex items-center gap-1 text-xs text-primary-600"><Plus class="h-3.5 w-3.5" /> Baris</button>
                    </div>

                    <!-- Tabel Jadwal Termin di dalam pasal -->
                    <div v-else-if="p.table === 'termin'" class="mt-2">
                        <table class="doc-table">
                            <thead><tr><th class="w-8">No</th><th>Uraian Termin</th><th class="w-16">%</th><th class="w-32">Nilai</th><th class="w-28">Jatuh Tempo</th><th class="no-print w-6"></th></tr></thead>
                            <tbody>
                                <tr v-for="(t, ti) in f.termin" :key="ti">
                                    <td class="text-center">{{ ti + 1 }}</td>
                                    <td><input v-model="t.uraian" class="ed w-full" /></td>
                                    <td><input v-model.number="t.persen" type="number" class="ed w-full text-right" /></td>
                                    <td><input v-model.number="t.nilai" type="number" class="ed w-full text-right" /></td>
                                    <td><input v-model="t.jatuh_tempo" type="date" class="ed w-full" /></td>
                                    <td class="no-print text-center"><button @click="delTermin(ti)" class="text-neutral-300 hover:text-danger-500"><Trash2 class="h-3.5 w-3.5" /></button></td>
                                </tr>
                            </tbody>
                        </table>
                        <button @click="addTermin" class="no-print mt-1 inline-flex items-center gap-1 text-xs text-primary-600"><Plus class="h-3.5 w-3.5" /> Baris</button>
                    </div>
                </div>
                <button @click="addPasal" class="no-print inline-flex items-center gap-1 rounded-md border border-dashed border-neutral-300 px-3 py-1 text-xs text-neutral-500 hover:border-primary-400 hover:text-primary-600 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Tambah Pasal</button>
            </div>

            <!-- Lampiran: Rincian Anggaran Biaya (RAB) -->
            <div v-if="f.showRincian && !rincianInPasal" class="mt-5">
                <div class="flex items-center justify-between">
                    <div class="lamp-title">LAMPIRAN {{ lampNo('rincian') }} — RINCIAN ANGGARAN BIAYA (RAB)</div>
                    <button @click="addRincian" class="no-print inline-flex items-center gap-1 text-xs text-primary-600"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <table class="doc-table mt-1">
                    <thead><tr><th class="w-8">No</th><th>Uraian Pekerjaan</th><th class="w-16">Volume</th><th class="w-16">Satuan</th><th class="w-32">Harga Satuan</th><th class="w-32">Jumlah</th><th class="no-print w-6"></th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in f.rincian" :key="i">
                            <td class="text-center">{{ i + 1 }}</td>
                            <td><input v-model="r.uraian" class="ed w-full" /></td>
                            <td><input v-model.number="r.volume" type="number" class="ed w-full text-right" /></td>
                            <td><input v-model="r.satuan" class="ed w-full text-center" /></td>
                            <td><input v-model.number="r.harga" type="number" class="ed w-full text-right" /></td>
                            <td class="text-right tabular-nums">{{ fmtRpNum(rowJumlah(r)) }}</td>
                            <td class="no-print text-center"><button @click="delRincian(i)" class="text-neutral-300 hover:text-danger-500"><Trash2 class="h-3.5 w-3.5" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="font-bold"><td colspan="5" class="text-right">TOTAL</td><td class="text-right tabular-nums">{{ fmtRpNum(rincianTotal) }}</td><td class="no-print"></td></tr></tfoot>
                </table>
            </div>

            <!-- Lampiran: Rincian per Unit Rumah (alokasi porsi kontrak per unit) -->
            <div v-if="f.showUnit && f.unitRows.length && !unitInPasal" class="mt-4">
                <div class="flex items-center justify-between">
                    <div class="lamp-title">LAMPIRAN {{ lampNo('unit') }} — RINCIAN PER UNIT RUMAH</div>
                    <button @click="addUnitRow" class="no-print inline-flex items-center gap-1 text-xs text-primary-600"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <div class="mt-1 text-[11px] text-neutral-500">
                    SPK {{ f.nomor }} · {{ f.grup }}<span v-if="s.project?.name"> · {{ s.project.name }}</span><span v-if="s.supplier?.name"> · {{ s.supplier.name }}</span>
                </div>
                <table class="doc-table mt-1">
                    <thead><tr><th class="w-8">No</th><th>Kode Unit</th><th>Tipe</th><th class="w-40">Harga Borongan</th><th class="no-print w-6"></th></tr></thead>
                    <tbody>
                        <tr v-for="(u, i) in f.unitRows" :key="i">
                            <td class="text-center">{{ i + 1 }}</td>
                            <td><input v-model="u.kode" class="ed w-full" /></td>
                            <td><input v-model="u.tipe" class="ed w-full" /></td>
                            <td><input v-model.number="u.harga" type="number" class="ed w-full text-right" /></td>
                            <td class="no-print text-center"><button @click="delUnitRow(i)" class="text-neutral-300 hover:text-danger-500"><Trash2 class="h-3.5 w-3.5" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="font-bold"><td colspan="3" class="text-right">TOTAL {{ f.unitRows.length }} UNIT</td><td class="text-right tabular-nums">{{ fmtRpNum(unitTotal) }}</td><td class="no-print"></td></tr></tfoot>
                </table>
            </div>

            <!-- Lampiran: Jadwal Pembayaran Termin -->
            <div v-if="f.showTermin && f.termin.length && !terminInPasal" class="mt-4">
                <div class="flex items-center justify-between">
                    <div class="lamp-title">LAMPIRAN {{ lampNo('termin') }} — JADWAL PEMBAYARAN (TERMIN)</div>
                    <button @click="addTermin" class="no-print inline-flex items-center gap-1 text-xs text-primary-600"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <table class="doc-table mt-1">
                    <thead><tr><th class="w-8">No</th><th>Uraian Termin</th><th class="w-16">%</th><th class="w-32">Nilai</th><th class="w-28">Jatuh Tempo</th><th class="no-print w-6"></th></tr></thead>
                    <tbody>
                        <tr v-for="(t, i) in f.termin" :key="i">
                            <td class="text-center">{{ i + 1 }}</td>
                            <td><input v-model="t.uraian" class="ed w-full" /></td>
                            <td><input v-model.number="t.persen" type="number" class="ed w-full text-right" /></td>
                            <td><input v-model.number="t.nilai" type="number" class="ed w-full text-right" /></td>
                            <td><input v-model="t.jatuh_tempo" type="date" class="ed w-full" /></td>
                            <td class="no-print text-center"><button @click="delTermin(i)" class="text-neutral-300 hover:text-danger-500"><Trash2 class="h-3.5 w-3.5" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button v-if="!terminInPasal && (!f.showTermin || !f.termin.length)" @click="f.showTermin = true; addTermin()" class="no-print mt-2 inline-flex items-center gap-1 rounded-md border border-dashed border-neutral-300 px-3 py-1 text-xs text-neutral-500 hover:text-primary-600 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Tambah Jadwal Termin</button>
            <button v-if="!unitInPasal && !f.unitRows.length" @click="addUnitRow()" class="no-print mt-2 ml-2 inline-flex items-center gap-1 rounded-md border border-dashed border-neutral-300 px-3 py-1 text-xs text-neutral-500 hover:text-primary-600 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Tambah Rincian per Unit</button>

            <!-- Tanda tangan -->
            <div class="ttd">
                <div class="ttd-col">
                    <div>PIHAK KEDUA,</div>
                    <div class="font-semibold">{{ f.pihak2.perusahaan }}</div>
                    <div class="ttd-space"></div>
                    <input v-model="f.pihak2.nama" class="ed ttd-name" />
                    <div>{{ f.pihak2.jabatan }}</div>
                </div>
                <div class="ttd-col">
                    <div>{{ f.tempat }}, {{ fmtTgl(f.tanggal) }}</div>
                    <div>PIHAK PERTAMA,</div>
                    <div class="font-semibold">{{ f.pihak1.perusahaan }}</div>
                    <div class="ttd-space"></div>
                    <input v-model="f.pihak1.nama" class="ed ttd-name" />
                    <div>{{ f.pihak1.jabatan }}</div>
                </div>
            </div>

            <!-- Saksi (mengikuti draft SPK asli: 2 saksi di tiap sisi) -->
            <div class="ttd mt-6 text-left">
                <div>
                    <div class="mb-1">Saksi:</div>
                    <div v-for="(_, i) in f.saksi2" :key="'s2'+i" class="flex items-center gap-2">
                        <span>{{ i + 1 }}.</span><input v-model="f.saksi2[i]" class="ed flex-1" />
                    </div>
                </div>
                <div>
                    <div class="mb-1">Saksi:</div>
                    <div v-for="(_, i) in f.saksi1" :key="'s1'+i" class="flex items-center gap-2">
                        <span>{{ i + 1 }}.</span><input v-model="f.saksi1[i]" class="ed flex-1" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.spk-wrap { padding: 0 12px 40px; }
.spk-doc {
    max-width: 210mm; padding: 20mm 22mm; border-radius: 6px;
    font-family: 'Times New Roman', Georgia, serif; font-size: 13px; line-height: 1.55; color: #111;
}
.spk-doc .ed { border: none; border-bottom: 1px dashed #cbd5e1; background: #fbfdff; color: inherit; font: inherit; padding: 0 2px; outline: none; }
.spk-doc .ed:focus { border-bottom-color: #ff8c00; background: #fff7ed; }
.spk-doc textarea.ed { width: 100%; resize: none; overflow: hidden; border-bottom: none; background: #fbfdff; border-radius: 3px; padding: 2px 4px; }
.spk-doc textarea.ed:focus { background: #fff7ed; box-shadow: 0 0 0 1px #ffd8a8; }
.spk-doc .t-proyek { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: .5px; width: 90%; }
.spk-doc .t-title { text-align: center; font-size: 20px; font-weight: bold; letter-spacing: 1px; width: 90%; }
.spk-doc .t-sub { text-align: center; font-size: 13px; width: 40%; }
.spk-doc .t-pekerjaan { text-align: center; font-size: 14px; font-weight: bold; width: 95%; }
.spk-doc .t-nomor { text-align: center; font-weight: 600; width: 260px; }
.spk-doc .p-title { font-weight: bold; font-size: 13.5px; letter-spacing: .3px; }
.spk-doc .pihak-t td { padding: 1px 3px; vertical-align: top; }
.spk-doc .lamp-title { font-weight: bold; font-size: 12.5px; }
.spk-doc .doc-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.spk-doc .doc-table th, .spk-doc .doc-table td { border: 1px solid #94a3b8; padding: 3px 5px; }
.spk-doc .doc-table th { background: #f1f5f9; text-align: center; font-weight: bold; }
.spk-doc .doc-table td .ed { border-bottom: none; background: transparent; }
.spk-doc .ttd { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 40px; text-align: center; font-size: 13px; }
.spk-doc .ttd-space { height: 64px; }
.spk-doc .ttd-name { text-align: center; font-weight: bold; text-decoration: underline; width: 80%; margin: 0 auto; display: block; }

@page { size: A4; margin: 14mm; }
@media print {
    body { background: #fff !important; }
    .no-print { display: none !important; }
    .spk-wrap { padding: 0; }
    .spk-doc { box-shadow: none !important; max-width: 100%; padding: 0; border-radius: 0; }
    .spk-doc .ed, .spk-doc textarea.ed { border: none !important; background: transparent !important; box-shadow: none !important; padding: 0 2px; }
    .spk-doc .doc-table th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .spk-doc .pasal { break-inside: avoid; }
}
</style>
