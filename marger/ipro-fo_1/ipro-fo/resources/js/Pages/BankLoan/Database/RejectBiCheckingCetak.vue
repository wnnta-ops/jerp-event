<script setup lang="ts">
/**
 * Lembar cetak A4 "Berita Acara Reject BI Checking".
 *
 * KENAPA HALAMAN TERSENDIRI, bukan @media print pada layar detail: tombol Cetak sebelumnya
 * memanggil window.print() di halaman detail, yang berarti sidebar & topbar aplikasi ikut
 * tercetak dan seluruh warna panel merahnya HILANG — peramban membuang warna latar saat
 * mencetak kecuali diminta sebaliknya. Yang keluar dari printer adalah tangkapan layar pucat,
 * bukan dokumen. Mengikuti pola dokumen cetak yang sudah ada di repo ini (RekomBatal, SPK, PO):
 * halaman sendiri berlayout PrintLayout, CSS mentah berawalan .rbi, dan print-color-adjust.
 *
 * SATU-SATUNYA VISUALISASI di lembar ini adalah SKALA KOLEKTIBILITAS, dan bentuknya ditentukan
 * oleh aturan bisnisnya sendiri, bukan selera: gerbang reject di KprInitiationController hanya
 * mengenal DUA ember — bersih = [clear, kol1], bermasalah = [kol2..kol5]. Jadi skalanya diwarnai
 * dua status saja (hijau #059669 / merah #dc2626, pasangan warna yang lolos pemeriksaan
 * keterbedaan buta warna), sedangkan TINGKAT keparahan dibawa oleh POSISI dan NAMA tiap langkah.
 * Enam warna berbeda untuk enam kolektibilitas justru membuat Kol 2 dan Kol 3 nyaris tak
 * terbedakan bahkan oleh mata normal, dan di atas kertas hitam-putih hilang sama sekali.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import PrintLayout from '@/Layouts/PrintLayout.vue';

defineOptions({ layout: PrintLayout });

type Dok = { label: string; ok: boolean; at: string | null };

const props = defineProps<{
    init: Record<string, any>;
    sprs: { marital_status: string | null; perlu_pasangan: boolean; at: string | null; dokumen: Dok[]; ocr: string | null };
    bi: Record<string, any>;
    company: { name: string; address: string };
    cetak: { nomor: string; oleh: string | null; pada: string; kota: string; hari: string };
    signers: { left: { label: string; name: string; title: string }; right: { label: string; name: string; title: string } };
}>();

function fmtRp(v: number | null | undefined) {
    if (v === null || v === undefined) return '—';
    return 'Rp ' + Number(v).toLocaleString('id-ID');
}
function fmtTgl(iso: string | null | undefined) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
}
function fmtJam(iso: string | null | undefined) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const biLabelMap: Record<string, string> = { clear: 'Clear', kol1: 'Kol 1', kol2: 'Kol 2', kol3: 'Kol 3', kol4: 'Kol 4', kol5: 'Kol 5', caution: 'Perlu Perhatian', reject: 'Ditolak' };
const permintaanMap: Record<string, string> = { lanjut_spr: 'Lanjut Pemberkasan SPR', mundur: 'Mundur / Reject' };
const maritalMap: Record<string, string> = { belum_menikah: 'Belum Menikah', sudah_menikah: 'Sudah Menikah' };

/** Urutan resmi kolektibilitas + ember "bersih" persis seperti gerbang reject di server. */
const LANGKAH = [
    { key: 'clear', label: 'Clear', bersih: true },
    { key: 'kol1', label: 'Kol 1', bersih: true },
    { key: 'kol2', label: 'Kol 2', bersih: false },
    { key: 'kol3', label: 'Kol 3', bersih: false },
    { key: 'kol4', label: 'Kol 4', bersih: false },
    { key: 'kol5', label: 'Kol 5', bersih: false },
];

const batalSepihak = computed(() => props.bi.jenis === 'batal_sepihak');

/**
 * Hasil BI yang nilainya DI LUAR keenam langkah (mis. 'caution' dari data lama) tidak boleh
 * diam-diam hilang dari skala — ia dilaporkan terpisah di bawahnya, bukan dianggap tidak ada.
 */
function diSkala(v: string | null | undefined) {
    return !!v && LANGKAH.some((l) => l.key === v);
}

const skala = computed(() => LANGKAH.map((l) => ({
    ...l,
    pemohon: props.bi.result === l.key,
    pasangan: props.bi.result_pasangan === l.key,
    aktif: props.bi.result === l.key || props.bi.result_pasangan === l.key,
})));

const luarSkala = computed(() => {
    const out: string[] = [];
    if (props.bi.result && !diSkala(props.bi.result)) out.push('Pemohon: ' + (biLabelMap[props.bi.result] ?? props.bi.result));
    if (props.bi.result_pasangan && !diSkala(props.bi.result_pasangan)) out.push('Pasangan: ' + (biLabelMap[props.bi.result_pasangan] ?? props.bi.result_pasangan));
    return out;
});

/** Hasil yang tidak tercatat sama sekali — disebutkan, bukan dibiarkan kosong tanpa penjelasan. */
const tanpaHasil = computed(() => {
    const out: string[] = [];
    if (!props.bi.result) out.push('pemohon');
    if (props.sprs.perlu_pasangan && !props.bi.result_pasangan) out.push('pasangan');
    return out;
});

const dokLengkap = computed(() => props.sprs.dokumen.every((d) => d.ok));

// Namanya doCetak, bukan cetak: prop halaman ini juga bernama `cetak`, dan fungsi di <script setup>
// MENUTUPI prop bernama sama di dalam template. Akibatnya nomor dokumen, kota, dan tanggal
// tanda tangan ter-render kosong tanpa satu pun galat.
function doCetak() { window.print(); }
</script>

<template>
    <div class="rbi-wrap">
        <!-- Bilah aksi — tidak ikut tercetak. -->
        <div class="rbi-bar noprint">
            <Link :href="route('kpr.database.reject-bi.detail', init.id)" class="rbi-back"><ArrowLeft class="rbi-ico" /> Kembali ke detail</Link>
            <button type="button" class="rbi-btn" @click="doCetak"><Printer class="rbi-ico" /> Cetak</button>
        </div>

        <div class="rbi-sheet rbi">
            <!-- ── Kop ── -->
            <div class="rbi-kop">
                <img src="/images/mup-logo.jpeg" alt="" class="rbi-logo" />
                <div class="rbi-kop-id">
                    <div class="rbi-kop-nama">{{ company.name }}</div>
                    <div class="rbi-kop-alamat">{{ company.address }}</div>
                </div>
                <div class="rbi-kop-doc">
                    <div class="rbi-kop-doc-l">Dokumen Internal</div>
                    <div class="rbi-kop-doc-n">{{ cetak.nomor }}</div>
                </div>
            </div>
            <div class="rbi-rule"></div>

            <h1 class="rbi-title">BERITA ACARA REJECT BI CHECKING</h1>
            <p class="rbi-sub">Inisiasi KPR {{ init.number }} &middot; {{ init.customer || '—' }}</p>

            <!-- ── Vonis: satu kalimat, diwarnai menurut JENIS rejectnya ── -->
            <div class="rbi-vonis" :class="batalSepihak ? 'v-amber' : 'v-red'">
                <div class="rbi-vonis-cap">{{ batalSepihak ? 'REJECT BATAL SEPIHAK' : 'REJECT BI CHECKING' }}</div>
                <p class="rbi-vonis-txt" v-if="batalSepihak">
                    Hasil BI Checking pemohon <strong>BERSIH</strong> (Clear / Kol 1). Inisiasi dihentikan karena
                    <strong>customer sendiri yang mengundurkan diri</strong> — bukan karena riwayat kreditnya.
                </p>
                <p class="rbi-vonis-txt" v-else>
                    Inisiasi dihentikan karena hasil BI Checking berada pada <strong>Kol 2 sampai Kol 5</strong>,
                    di luar batas yang diterima developer.
                </p>
                <div class="rbi-vonis-meta">Ditolak {{ fmtJam(bi.rejected_at) }} oleh {{ bi.rejected_by || '—' }}</div>
            </div>

            <!-- ── 1. Identitas ── -->
            <h2 class="rbi-h2">1. Identitas Inisiasi</h2>
            <table class="rbi-id">
                <tbody>
                    <tr>
                        <td class="l">Nama Customer</td><td class="v">{{ init.customer || '—' }}</td>
                        <td class="l">No. Inisiasi</td><td class="v">{{ init.number }}</td>
                    </tr>
                    <tr>
                        <td class="l">Telepon</td><td class="v">{{ init.phone || '—' }}</td>
                        <td class="l">Unit</td><td class="v">{{ init.unit || '—' }}</td>
                    </tr>
                    <tr>
                        <td class="l">Status Pernikahan</td><td class="v">{{ maritalMap[sprs.marital_status || ''] || '—' }}</td>
                        <td class="l">No. Booking</td><td class="v">{{ init.booking || '—' }}</td>
                    </tr>
                    <tr>
                        <td class="l">Plafon Diajukan</td><td class="v strong">{{ fmtRp(init.plafon) }}</td>
                        <td class="l">Inisiasi Dibuat</td><td class="v">{{ fmtTgl(init.created_at) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- ── 2. Perjalanan proses ── -->
            <h2 class="rbi-h2">2. Perjalanan Proses</h2>
            <div class="rbi-alur">
                <div class="rbi-tahap">
                    <div class="rbi-disc d-ok">1</div>
                    <div class="rbi-tahap-isi">
                        <div class="rbi-tahap-jd">TTS — Sumber Inisiasi<span class="rbi-tag t-ok">Selesai</span></div>
                        <div class="rbi-tahap-ket">
                            No. TTS {{ init.tts || '—' }}<template v-if="init.tts_amount !== null"> &middot; {{ fmtRp(init.tts_amount) }}</template>
                            <template v-if="init.tts_date"> &middot; diterima {{ fmtTgl(init.tts_date) }}</template>
                        </div>
                    </div>
                </div>

                <div class="rbi-tahap">
                    <div class="rbi-disc" :class="dokLengkap ? 'd-ok' : 'd-warn'">2</div>
                    <div class="rbi-tahap-isi">
                        <div class="rbi-tahap-jd">
                            SPRS — Penerimaan Dokumen
                            <span class="rbi-tag" :class="dokLengkap ? 't-ok' : 't-warn'">{{ dokLengkap ? 'Lengkap' : 'Belum lengkap' }}</span>
                        </div>
                        <table class="rbi-dok">
                            <tbody>
                                <tr v-for="d in sprs.dokumen" :key="d.label">
                                    <td class="m"><span class="rbi-mk" :class="d.ok ? 'm-ok' : 'm-no'">{{ d.ok ? '✓' : '✕' }}</span></td>
                                    <td class="n">{{ d.label }}</td>
                                    <td class="t">{{ d.ok ? fmtJam(d.at) : 'belum diterima' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="rbi-tahap-ket" v-if="!sprs.perlu_pasangan">
                            Pemohon berstatus belum menikah — dokumen pasangan tidak dipersyaratkan.
                        </div>
                        <div class="rbi-tahap-ket" v-if="sprs.ocr">
                            Pencocokan nama hasil scan KTP &amp; NPWP:
                            <strong>{{ sprs.ocr === 'match' ? 'sesuai' : 'TIDAK sesuai' }}</strong>
                        </div>
                        <div class="rbi-tahap-ket" v-if="sprs.at">Tahap ditutup {{ fmtJam(sprs.at) }}</div>
                    </div>
                </div>

                <div class="rbi-tahap rbi-tahap-akhir">
                    <div class="rbi-disc" :class="batalSepihak ? 'd-amber' : 'd-red'">3</div>
                    <div class="rbi-tahap-isi">
                        <div class="rbi-tahap-jd">
                            BI Checking — Proses Berhenti
                            <span class="rbi-tag" :class="batalSepihak ? 't-amber' : 't-red'">{{ batalSepihak ? 'Batal Sepihak' : 'Ditolak' }}</span>
                        </div>
                        <div class="rbi-tahap-ket">Permintaan customer: <strong>{{ permintaanMap[bi.permintaan] || bi.permintaan || '—' }}</strong></div>
                        <div class="rbi-tahap-ket" v-if="bi.pernah_lanjut">
                            Tahap ini sempat ditutup untuk <strong>lanjut pemberkasan SPR</strong><template v-if="bi.lanjut_at"> pada {{ fmtJam(bi.lanjut_at) }}</template>,
                            lalu dibatalkan oleh penolakan ini.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 3. Skala kolektibilitas ── -->
            <h2 class="rbi-h2">3. Hasil BI Checking</h2>
            <div class="rbi-skala">
                <div class="rbi-zona">
                    <div class="rbi-zona-ok">Diterima developer</div>
                    <div class="rbi-zona-bad">Tidak diterima — dasar reject</div>
                </div>
                <div class="rbi-langkah">
                    <div v-for="s in skala" :key="s.key" class="rbi-lk" :class="[s.bersih ? 'lk-ok' : 'lk-bad', s.aktif ? 'lk-on' : '']">
                        <div class="rbi-lk-chip">{{ s.label }}</div>
                        <div class="rbi-lk-mark">
                            <div v-if="s.pemohon" class="rbi-pin">▲ Pemohon</div>
                            <div v-if="s.pasangan" class="rbi-pin">▲ Pasangan</div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="rbi-note" v-if="luarSkala.length">
                Hasil di luar skala kolektibilitas baku (data lama): {{ luarSkala.join(' · ') }}.
            </p>
            <p class="rbi-note" v-if="tanpaHasil.length">
                Hasil BI Checking {{ tanpaHasil.join(' dan ') }} tidak tercatat pada sistem, sehingga tidak ditandai pada skala di atas.
            </p>
            <p class="rbi-note" v-if="!sprs.perlu_pasangan">
                Skala hanya menandai pemohon — pemohon belum menikah, sehingga BI Checking pasangan tidak dipersyaratkan.
            </p>

            <!-- ── 4. Alasan ── -->
            <h2 class="rbi-h2">4. Alasan Penolakan</h2>
            <div class="rbi-alasan" :class="batalSepihak ? 'a-amber' : 'a-red'">
                {{ bi.reason || '— tidak dituliskan —' }}
            </div>
            <div class="rbi-catatan" v-if="bi.notes">
                <div class="rbi-catatan-l">Catatan petugas BI Checking</div>
                <div class="rbi-catatan-v">{{ bi.notes }}</div>
            </div>

            <!-- ── Tanda tangan ── -->
            <div class="rbi-ttd-kota">{{ cetak.kota }}, {{ cetak.hari }}</div>
            <div class="rbi-ttd">
                <div class="rbi-ttd-k">
                    <div>{{ signers.left.label }}</div>
                    <div class="rbi-ttd-sp"></div>
                    <div class="rbi-ttd-n">{{ signers.left.name || '(...........................)' }}</div>
                    <div class="rbi-ttd-j">{{ signers.left.title }}</div>
                </div>
                <div class="rbi-ttd-k">
                    <div>{{ signers.right.label }}</div>
                    <div class="rbi-ttd-sp"></div>
                    <div class="rbi-ttd-n">{{ signers.right.name || '(...........................)' }}</div>
                    <div class="rbi-ttd-j">{{ signers.right.title }}</div>
                </div>
            </div>

            <div class="rbi-foot">
                Dicetak {{ cetak.pada }}<template v-if="cetak.oleh"> oleh {{ cetak.oleh }}</template>.
                Dokumen dihasilkan otomatis oleh sistem dari arsip inisiasi {{ init.number }}.
            </div>
        </div>
    </div>
</template>

<style>
/* CSS mentah berawalan .rbi (bukan scoped, mengikuti RekomBatal/SPK/PO): aturan cetak harus
   bisa menjangkau elemen halaman, dan warnanya ditulis heksadesimal eksplisit karena lembar
   cetak tidak boleh bergantung pada kelas Tailwind yang bisa ikut berubah. */
.rbi-wrap { background: #e2e8f0; padding: 16px; min-height: 100vh; }
.rbi-bar { max-width: 182mm; margin: 0 auto 12px; display: flex; align-items: center; justify-content: space-between; }
.rbi-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #475569; text-decoration: none; }
.rbi-back:hover { color: #0f172a; }
.rbi-btn { display: inline-flex; align-items: center; gap: 6px; background: #ff8c00; color: #fff; border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
.rbi-btn:hover { background: #cc7000; }
.rbi-ico { width: 15px; height: 15px; }

.rbi-sheet {
    width: 182mm;                 /* 210mm A4 dikurangi margin cetak 2 x 14mm */
    /* Ditulis eksplisit, tidak menumpang preflight Tailwind: tanpa ini padding 12mm menambah
       lebar sehingga lembar di layar menjadi 206mm — tidak lagi sepadan dengan hasil cetaknya. */
    box-sizing: border-box;
    /* ANGGARAN TINGGI — lembar ini dirancang SATU HALAMAN. Tinggi cetak terukur (padding
       dinolkan di @media print) 249,5mm pada isi normal dan 265,4mm saat alasan DAN catatan
       sama-sama di batas 500 karakter yang diizinkan server, dari 269mm yang tersedia
       (A4 297mm - 2 x 14mm). Sisanya tinggal ~4mm: menambah bagian baru di sini berarti
       dokumennya tumpah ke halaman kedua yang hampir kosong. */
    margin: 0 auto;
    background: #fff;
    color: #0f172a;
    padding: 10mm 12mm 12mm;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .16);
    font-family: Inter, 'Segoe UI', Arial, sans-serif;
    font-size: 10.5px;
    line-height: 1.45;
}

/* ── Kop ── */
.rbi .rbi-kop { display: flex; align-items: center; gap: 10px; }
.rbi .rbi-logo { height: 15mm; width: auto; object-fit: contain; }
.rbi .rbi-kop-id { flex: 1; }
.rbi .rbi-kop-nama { font-size: 14px; font-weight: 700; letter-spacing: .3px; }
.rbi .rbi-kop-alamat { font-size: 9.5px; color: #64748b; }
.rbi .rbi-kop-doc { text-align: right; }
.rbi .rbi-kop-doc-l { font-size: 8.5px; letter-spacing: 1px; text-transform: uppercase; color: #94a3b8; }
.rbi .rbi-kop-doc-n { font-size: 10px; font-weight: 600; font-family: 'JetBrains Mono', Consolas, monospace; }
.rbi .rbi-rule { height: 3px; background: #ff8c00; margin: 6px 0 12px; }

.rbi .rbi-title { text-align: center; font-size: 15px; font-weight: 700; letter-spacing: .8px; margin: 0; }
.rbi .rbi-sub { text-align: center; font-size: 10px; color: #64748b; margin: 2px 0 12px; }

/* ── Vonis ── */
.rbi .rbi-vonis { border: 1px solid; border-left-width: 5px; border-radius: 6px; padding: 9px 12px; margin-bottom: 14px; }
.rbi .v-red { border-color: #fecaca; border-left-color: #dc2626; background: #fef2f2; }
.rbi .v-amber { border-color: #fde68a; border-left-color: #d97706; background: #fffbeb; }
.rbi .rbi-vonis-cap { font-size: 12px; font-weight: 700; letter-spacing: .6px; }
.rbi .v-red .rbi-vonis-cap { color: #b91c1c; }
.rbi .v-amber .rbi-vonis-cap { color: #b45309; }
.rbi .rbi-vonis-txt { margin: 3px 0 0; font-size: 10.5px; color: #334155; }
.rbi .rbi-vonis-meta { margin-top: 5px; font-size: 9px; color: #64748b; }

.rbi .rbi-h2 { font-size: 11px; font-weight: 700; letter-spacing: .4px; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e1; }

/* ── Identitas ── */
.rbi .rbi-id { width: 100%; border-collapse: collapse; }
.rbi .rbi-id td { padding: 3px 6px; vertical-align: top; border-bottom: 1px solid #e2e8f0; }
.rbi .rbi-id td.l { width: 20%; color: #64748b; }
.rbi .rbi-id td.v { width: 30%; font-weight: 500; }
.rbi .rbi-id td.v.strong { font-weight: 700; }

/* ── Alur tahap ── */
.rbi .rbi-tahap { display: flex; gap: 9px; padding-bottom: 9px; position: relative; }
/* Garis penghubung antar tahap — memakai ::before pada disc supaya tidak menambah elemen kosong. */
.rbi .rbi-tahap::before { content: ''; position: absolute; left: 8px; top: 18px; bottom: 0; width: 2px; background: #e2e8f0; }
.rbi .rbi-tahap-akhir { padding-bottom: 0; }
.rbi .rbi-tahap-akhir::before { display: none; }
.rbi .rbi-disc { flex: 0 0 18px; width: 18px; height: 18px; border-radius: 50%; color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; position: relative; z-index: 1; }
.rbi .d-ok { background: #059669; }
.rbi .d-warn { background: #d97706; }
.rbi .d-amber { background: #d97706; }
.rbi .d-red { background: #dc2626; }
.rbi .rbi-tahap-isi { flex: 1; padding-top: 1px; }
.rbi .rbi-tahap-jd { font-weight: 600; font-size: 10.5px; }
.rbi .rbi-tahap-ket { color: #475569; font-size: 9.5px; margin-top: 1px; }
.rbi .rbi-tag { display: inline-block; margin-left: 6px; border-radius: 9px; padding: 0 6px; font-size: 8.5px; font-weight: 700; letter-spacing: .3px; color: #fff; vertical-align: 1px; }
.rbi .t-ok { background: #059669; }
.rbi .t-warn { background: #d97706; }
.rbi .t-amber { background: #d97706; }
.rbi .t-red { background: #dc2626; }

.rbi .rbi-dok { border-collapse: collapse; margin-top: 3px; }
.rbi .rbi-dok td { padding: 1px 8px 1px 0; font-size: 9.5px; }
.rbi .rbi-dok td.m { width: 14px; }
.rbi .rbi-dok td.n { min-width: 34mm; }
.rbi .rbi-dok td.t { color: #64748b; }
.rbi .rbi-mk { display: inline-block; width: 12px; height: 12px; border-radius: 50%; color: #fff; font-size: 8px; line-height: 12px; text-align: center; }
.rbi .m-ok { background: #059669; }
.rbi .m-no { background: #94a3b8; }

/* ── Skala kolektibilitas ── */
.rbi .rbi-skala { margin-top: 2px; }
/* Grid enam kolom yang SAMA dengan baris langkah, lalu dibentang 2 dan 4 — bukan 2fr/4fr.
   Dengan 2fr/4fr, sela kolomnya tidak ikut terhitung dan batas kedua zona meleset beberapa
   piksel dari sela antara Kol 1 dan Kol 2; padahal justru di situ letak batas terima developer. */
.rbi .rbi-zona { display: grid; grid-template-columns: repeat(6, 1fr); gap: 4px; }
.rbi .rbi-zona-ok { grid-column: span 2; }
.rbi .rbi-zona-bad { grid-column: span 4; }
.rbi .rbi-zona-ok, .rbi .rbi-zona-bad { font-size: 8.5px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; text-align: center; border-radius: 4px 4px 0 0; padding: 2px 0; }
.rbi .rbi-zona-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; border-bottom: 0; }
.rbi .rbi-zona-bad { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-bottom: 0; }
.rbi .rbi-langkah { display: grid; grid-template-columns: repeat(6, 1fr); gap: 4px; }
.rbi .rbi-lk-chip { border-radius: 4px; text-align: center; padding: 5px 0; font-size: 10px; font-weight: 600; border: 1px solid; }
.rbi .lk-ok .rbi-lk-chip { border-color: #a7f3d0; background: #ecfdf5; color: #047857; }
.rbi .lk-bad .rbi-lk-chip { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
/* Langkah yang benar-benar menjadi hasil BI: isian PENUH (warna yang lolos uji keterbedaan
   buta warna), bukan sekadar tint — tint hijau & merah muda praktis tak terbedakan di atas
   kertas maupun oleh mata deutan. */
.rbi .lk-ok.lk-on .rbi-lk-chip { background: #059669; border-color: #047857; color: #fff; }
.rbi .lk-bad.lk-on .rbi-lk-chip { background: #dc2626; border-color: #b91c1c; color: #fff; }
.rbi .rbi-lk-mark { text-align: center; min-height: 11px; }
.rbi .rbi-pin { font-size: 8.5px; font-weight: 700; color: #0f172a; white-space: nowrap; }

.rbi .rbi-note { font-size: 9px; color: #64748b; margin: 5px 0 0; }

/* ── Alasan ── */
.rbi .rbi-alasan { border: 1px solid; border-radius: 6px; padding: 8px 11px; font-size: 11px; font-weight: 600; }
.rbi .a-red { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
.rbi .a-amber { border-color: #fde68a; background: #fffbeb; color: #b45309; }
.rbi .rbi-catatan { margin-top: 7px; border-left: 3px solid #cbd5e1; padding-left: 9px; }
.rbi .rbi-catatan-l { font-size: 8.5px; letter-spacing: .4px; text-transform: uppercase; color: #94a3b8; }
.rbi .rbi-catatan-v { font-size: 10px; color: #334155; }

/* ── Tanda tangan ── */
.rbi .rbi-ttd-kota { margin-top: 16px; text-align: right; font-size: 10px; }
.rbi .rbi-ttd { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 6px; text-align: center; font-size: 10px; }
.rbi .rbi-ttd-sp { height: 52px; }
.rbi .rbi-ttd-n { font-weight: 700; text-decoration: underline; }
.rbi .rbi-ttd-j { font-size: 9px; color: #64748b; }
.rbi .rbi-foot { margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 5px; font-size: 8.5px; color: #94a3b8; }

/* ── Cetak ──
   print-color-adjust WAJIB: tanpa ini peramban membuang seluruh warna latar saat mencetak,
   dan justru warna latarlah yang membedakan Reject BI Checking dari Batal Sepihak, serta
   menandai langkah mana pada skala kolektibilitas yang menjadi hasil pemohon. */
@page { size: A4; margin: 14mm; }
@media print {
    .rbi-wrap { background: #fff; padding: 0; min-height: 0; }
    .noprint { display: none !important; }
    .rbi-sheet { width: auto; max-width: 100%; margin: 0; padding: 0; box-shadow: none; }
    .rbi, .rbi * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rbi .rbi-tahap, .rbi .rbi-skala, .rbi .rbi-ttd, .rbi .rbi-vonis { break-inside: avoid; page-break-inside: avoid; }
}
</style>
