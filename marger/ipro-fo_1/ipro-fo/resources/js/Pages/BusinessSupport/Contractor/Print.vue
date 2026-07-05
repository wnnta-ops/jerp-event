<script setup lang="ts">
import { reactive, ref, computed, onMounted, nextTick } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer, Plus, Trash2, RotateCcw } from 'lucide-vue-next';

const props = defineProps<{ spk: any; company: Record<string, any>; jenisOpts: Record<string, string> }>();

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

// ── Default pasal (bisa diedit / dihapus sebagian) ──
function defaultPasals() {
    return [
        { title: 'PASAL 1 — LINGKUP PEKERJAAN', body:
            `PIHAK PERTAMA memberikan pekerjaan kepada PIHAK KEDUA, dan PIHAK KEDUA menerima pekerjaan berupa: ${s.title || '—'}`
            + (jenisLabel ? ` (jenis: ${jenisLabel})` : '')
            + (s.project?.name ? `, berlokasi pada proyek ${s.project.name}` : '')
            + `. Rincian lingkup pekerjaan tercantum pada Rincian Anggaran Biaya (RAB) yang menjadi bagian tidak terpisahkan dari Surat Perintah Kerja ini.` },
        { title: 'PASAL 2 — NILAI PEKERJAAN', body:
            `Nilai pekerjaan disepakati sebesar ${fmtRp(nilai)} (${capit(terbilang(nilai))} rupiah)`
            + (s.is_pkp ? `, sudah termasuk PPN ${Number(s.ppn_pct)}%` : ` (Non-PKP)`)
            + `. Nilai tersebut bersifat lumpsum fixed price sesuai rincian pada RAB terlampir.` },
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
    return {
        judul: 'SURAT PERINTAH KERJA',
        subjudul: '(SPK)',
        nomor: s.no_manual || s.spk_number || '',
        tanggal: s.spk_date ?? '',
        tempat: props.company?.city || 'Sidoarjo',
        pembukaan: `Pada hari ini ${fmtTgl(s.spk_date) || '……'}, bertempat di ${props.company?.city || '……'}, yang bertanda tangan di bawah ini:`,
        pihak1: {
            nama: props.company?.signer_name || '', jabatan: props.company?.signer_title || 'Direktur',
            perusahaan: props.company?.name || '', alamat: props.company?.address || '',
        },
        pihak2: {
            nama: s.supplier?.contact_person || s.supplier?.name || '', jabatan: 'Kontraktor Pelaksana',
            perusahaan: s.supplier?.name || '', alamat: [s.supplier?.address, s.supplier?.city].filter(Boolean).join(', '),
            npwp: s.supplier?.npwp || '',
        },
        pasals: defaultPasals(),
        rincian: (s.items?.length ? s.items : [{}]).map((it: any) => ({
            uraian: it.description ?? '', volume: Number(it.qty) || 0, satuan: it.unit ?? '', harga: Number(it.unit_price) || 0,
        })),
        termin: (s.terms?.length ? s.terms : []).map((t: any) => ({
            uraian: t.description ?? '', persen: Number(t.percentage) || 0, nilai: Number(t.amount) || 0, jatuh_tempo: t.due_date ?? '',
        })),
        showRincian: (s.items?.length ?? 0) > 0,
        showTermin: (s.terms?.length ?? 0) > 0,
    };
}

const f = reactive<any>(makeState());
function resetAll() { if (confirm('Kembalikan seluruh isi SPK ke default? Semua editan akan hilang.')) Object.assign(f, makeState()); nextTick(autosizeAll); }

const rincianTotal = computed(() => f.rincian.reduce((sum: number, r: any) => sum + (Number(r.volume) || 0) * (Number(r.harga) || 0), 0));
function rowJumlah(r: any) { return (Number(r.volume) || 0) * (Number(r.harga) || 0); }
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
</script>

<template>
    <div class="spk-wrap">
        <!-- Toolbar (tidak ikut tercetak) -->
        <div class="no-print sticky top-0 z-10 mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-neutral-200 bg-white/90 p-3 backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/90">
            <Link :href="route('bs.spk.show', s.id)" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali ke SPK</Link>
            <div class="flex items-center gap-2">
                <span class="hidden text-xs text-neutral-400 sm:inline">Klik teks mana pun untuk mengedit — hanya pasal yang perlu.</span>
                <button @click="resetAll" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-4 w-4" /> Reset</button>
                <button @click="cetak" class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary-600 px-5 text-base font-bold text-white shadow-sm hover:bg-primary-700"><Printer class="h-5 w-5" /> Cetak</button>
            </div>
        </div>

        <!-- Dokumen SPK (A4) -->
        <div class="spk-doc mx-auto bg-white text-neutral-900 shadow-lg">
            <!-- Judul -->
            <div class="text-center">
                <input v-model="f.judul" class="ed t-title" />
                <input v-model="f.subjudul" class="ed t-sub" />
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
                    <div class="mt-1 pl-8 italic">Selanjutnya disebut sebagai <b>PIHAK PERTAMA</b> (Pemberi Kerja).</div>
                </div>
                <div class="pihak">
                    <table class="pihak-t">
                        <tr><td class="w-8 align-top">2.</td><td class="w-40 align-top">Nama</td><td class="w-3 align-top">:</td><td><input v-model="f.pihak2.nama" class="ed w-full" /></td></tr>
                        <tr><td></td><td class="align-top">Jabatan</td><td>:</td><td><input v-model="f.pihak2.jabatan" class="ed w-full" /> — <span class="font-semibold">{{ f.pihak2.perusahaan }}</span></td></tr>
                        <tr><td></td><td class="align-top">Alamat</td><td>:</td><td><input v-model="f.pihak2.alamat" class="ed w-full" /></td></tr>
                        <tr><td></td><td class="align-top">NPWP</td><td>:</td><td><input v-model="f.pihak2.npwp" class="ed w-full" /></td></tr>
                    </table>
                    <div class="mt-1 pl-8 italic">Selanjutnya disebut sebagai <b>PIHAK KEDUA</b> (Kontraktor Pelaksana).</div>
                </div>
                <p class="text-justify">Kedua belah pihak sepakat mengikatkan diri dalam Surat Perintah Kerja dengan ketentuan sebagai berikut:</p>
            </div>

            <!-- Pasal-pasal -->
            <div class="mt-3 space-y-3">
                <div v-for="(p, i) in f.pasals" :key="i" class="pasal group">
                    <div class="flex items-start gap-1">
                        <input v-model="p.title" class="ed p-title flex-1" />
                        <button @click="delPasal(i)" class="no-print mt-0.5 text-neutral-300 hover:text-danger-500" title="Hapus pasal"><Trash2 class="h-4 w-4" /></button>
                    </div>
                    <textarea v-model="p.body" @input="autosize" class="ed block text-justify" rows="1"></textarea>
                </div>
                <button @click="addPasal" class="no-print inline-flex items-center gap-1 rounded-md border border-dashed border-neutral-300 px-3 py-1 text-xs text-neutral-500 hover:border-primary-400 hover:text-primary-600 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Tambah Pasal</button>
            </div>

            <!-- Lampiran: Rincian Anggaran Biaya (RAB) -->
            <div v-if="f.showRincian" class="mt-5">
                <div class="flex items-center justify-between">
                    <div class="lamp-title">LAMPIRAN 1 — RINCIAN ANGGARAN BIAYA (RAB)</div>
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

            <!-- Lampiran: Jadwal Pembayaran Termin -->
            <div v-if="f.showTermin && f.termin.length" class="mt-4">
                <div class="flex items-center justify-between">
                    <div class="lamp-title">LAMPIRAN 2 — JADWAL PEMBAYARAN (TERMIN)</div>
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
            <button v-if="!f.showTermin || !f.termin.length" @click="f.showTermin = true; addTermin()" class="no-print mt-2 inline-flex items-center gap-1 rounded-md border border-dashed border-neutral-300 px-3 py-1 text-xs text-neutral-500 hover:text-primary-600 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Tambah Jadwal Termin</button>

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
.spk-doc .t-title { text-align: center; font-size: 20px; font-weight: bold; letter-spacing: 1px; width: 90%; }
.spk-doc .t-sub { text-align: center; font-size: 13px; width: 40%; }
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
