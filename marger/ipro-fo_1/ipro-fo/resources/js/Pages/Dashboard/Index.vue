<script setup lang="ts">
// Dashboard Back Office — 16 kartu grafik untuk 18 poin ringkasan modul.
//
// Tiga grafik lama (BAST, AJB, Booked Revenue) digabung jadi satu kartu: unit dirinci
// per bulan, nilai diringkas setahun. Dipisah PANEL, bukan dua sumbu Y pada satu grafik —
// dua sumbu berbeda skala adalah kesalahan grafik yang paling sering menyesatkan pembaca.
//
// Semua angka datang dari DashboardController. Bila tabelnya masih kosong, kartunya
// menampilkan keadaan kosong apa adanya — tidak ada angka contoh di layar ini.
import { Head } from '@inertiajs/vue3';
import VizColumns from '@/Components/Charts/VizColumns.vue';
import VizFunnel from '@/Components/Charts/VizFunnel.vue';
import VizGrouped from '@/Components/Charts/VizGrouped.vue';
import VizHBars from '@/Components/Charts/VizHBars.vue';
import VizHStack from '@/Components/Charts/VizHStack.vue';
import VizDiverging from '@/Components/Charts/VizDiverging.vue';
import VizMeters from '@/Components/Charts/VizMeters.vue';
import VizYearTotals from '@/Components/Charts/VizYearTotals.vue';
import VizTotal, { type Pos } from '@/Components/Charts/VizTotal.vue';
import { ORA, TAHAP, S, rp, type Seri, type Kunci, type Baris, type BarisTumpuk } from '@/Components/Charts/viz';

interface Bulanan { labels: string[]; series: Seri[] }
interface Tumpuk { rows: BarisTumpuk[]; keys: Kunci[] }
interface SeriGabungan { n: string; c: number; unit: number[]; nilai: number; unit_tahunan: number }

const props = defineProps<{
    greeting?: { name: string | null; last_login_at: string | null; last_login_ip: string | null };
    periode: { label: string; project: string };
    charts: {
        inisiasi_kpr: { rows: Baris[] };
        proses_kpr: { rows: Baris[] };
        penolakan: Bulanan;
        rekening: { rows: Baris[] };
        kasbon: Bulanan;
        aging: { rows: Baris[] };
        neraca: Tumpuk;
        laba_rugi: { labels: string[]; vals: number[] };
        aset_tetap: Tumpuk;
        budget: { rows: Array<{ n: string; real: number; plafon: number }> };
        spk: { rows: Array<{ n: string; jml: number; nilai: number }> };
        progress: Tumpuk;
        retensi: Bulanan;
        bast_ajb_br: { labels: string[]; series: SeriGabungan[] };
        piutang: Tumpuk;
        hpp: Tumpuk;
    };
}>();

const c = props.charts;

/** Satu cluster memakai corak oranye yang SAMA di panel jumlah maupun panel nilai. */
const kunciSpk = c.spk.rows.map((_, i) => i);
const spkJumlah: Baris[] = c.spk.rows.map((r) => ({ n: r.n, v: r.jml }));
const spkNilai: Baris[] = c.spk.rows.map((r) => ({ n: r.n, v: r.nilai }));

/** Grafik gabungan: deret bulanan (unit) dan ringkasan tahunan (nilai) dari sumber yang sama. */
const brUnit: Seri[] = c.bast_ajb_br.series.map((s) => ({ n: s.n, c: s.c, v: s.unit }));
const brNilai = c.bast_ajb_br.series.map((s) => ({ n: s.n, c: s.c, v: s.nilai }));

// ══════════════════════════════════════════════════════════════════════════════════════
// Total tiap kartu
//
// Tidak semua grafik boleh dijumlahkan begitu saja. Tiga bentuk yang penjumlahannya SALAH,
// dan bagaimana masing-masing ditangani di bawah:
//
//   1. Corong bersarang (kartu 1 & 2). Controller menghitung tahapnya kumulatif
//      (`stage >= min`), jadi 19 berkas di "BI Checking" ADALAH bagian dari 30 berkas di
//      "SPRS". Menjumlahkan keenam tahap menghitung berkas yang sama sampai enam kali.
//      Yang sah: angka tahap pertama — itulah seluruh populasinya.
//   2. Dua sisi yang sudah seimbang (kartu 7). AKTIVA dan PASIVA adalah dua cara memandang
//      harta yang sama; jumlahnya selalu dua kali nilai perusahaan. Ditampilkan terpisah,
//      justru supaya ketimpangannya kelihatan bila ada.
//   3. Tahapan atas objek yang sama (kartu 14–16). BAST → AJB → Booked Revenue menyangkut
//      unit yang sama pada tiga tahap; menjumlahkannya menghitung uang yang sama tiga kali.
//      Ditampilkan per tahap. Alasan yang sama sudah tertulis di VizYearTotals.
// ══════════════════════════════════════════════════════════════════════════════════════

const jumlah = (v: number[]): number => v.reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);
const jumlahBaris = (rows: Baris[]): number => jumlah(rows.map((r) => r.v));
const jumlahSeri = (s: Seri[], i: number): number => (s[i] ? jumlah(s[i].v) : 0);

// Segmen bertumpuk: nilai negatif DIJEPIT ke nol, persis seperti yang dilakukan VizHStack
// saat menggambar. Kalau tidak, baris totalnya akan membantah batang di atasnya.
const jumlahTumpukBaris = (t: Tumpuk, i: number): number => {
    const r = t.rows[i];
    if (!r) return 0;
    return jumlah(t.keys.map((k) => {
        const v = r[k.k];
        return typeof v === 'number' && Number.isFinite(v) && v > 0 ? v : 0;
    }));
};
const jumlahTumpuk = (t: Tumpuk): number => t.rows.reduce((a, _r, i) => a + jumlahTumpukBaris(t, i), 0);

/** Rupiah penuh untuk baris total. */
const posRp = (label: string, v: number, catatan?: string): Pos => ({ label, v, teks: rp(v), catatan });
/** Jumlah + satuan untuk baris total. */
const posJml = (label: string, v: number, satuan: string, catatan?: string): Pos =>
    ({ label, v, teks: v.toLocaleString('id-ID') + (satuan ? ' ' + satuan : ''), catatan });

const CORONG = 'Tahap berikutnya adalah bagian dari angka ini, bukan tambahannya — karena itu keenam tahap tidak dijumlahkan.';

const totalInisiasi: Pos[] = [posJml('Total berkas (tahap 1)', c.inisiasi_kpr.rows[0]?.v ?? 0, 'berkas', CORONG)];
const totalProses: Pos[] = [posJml('Total berkas (tahap 1)', c.proses_kpr.rows[0]?.v ?? 0, 'berkas', CORONG)];

const totalPenolakan: Pos[] = [
    posJml('Reject BI Checking', jumlahSeri(c.penolakan.series, 0), 'berkas'),
    posJml('Reject Proses KPR', jumlahSeri(c.penolakan.series, 1), 'berkas'),
    posJml('Total penolakan', jumlahSeri(c.penolakan.series, 0) + jumlahSeri(c.penolakan.series, 1), 'berkas'),
];

const totalRekening: Pos[] = [
    posRp('Total saldo kas & bank', jumlahBaris(c.rekening.rows), 'Penjumlahan seluruh rekening; saldo berposisi kredit ikut mengurangi.'),
];

const totalKasbon: Pos[] = [
    posRp('Total diajukan', jumlahSeri(c.kasbon.series, 0)),
    posRp('Total dipertanggungjawabkan', jumlahSeri(c.kasbon.series, 1)),
    posRp('Belum dipertanggungjawabkan', jumlahSeri(c.kasbon.series, 0) - jumlahSeri(c.kasbon.series, 1)),
];

const totalAging: Pos[] = [posRp('Total hutang supplier', jumlahBaris(c.aging.rows))];

const totalNeraca: Pos[] = [
    posRp('Total ' + (c.neraca.rows[0]?.n ?? 'AKTIVA'), jumlahTumpukBaris(c.neraca, 0)),
    posRp('Total ' + (c.neraca.rows[1]?.n ?? 'PASIVA'), jumlahTumpukBaris(c.neraca, 1)),
    posRp('Selisih', jumlahTumpukBaris(c.neraca, 0) - jumlahTumpukBaris(c.neraca, 1), 'Neraca seimbang bila selisihnya nol.'),
];

const totalLabaRugi: Pos[] = [posRp('Laba/rugi kumulatif', jumlah(c.laba_rugi.vals))];
const totalAsetTetap: Pos[] = [posRp('Total nilai perolehan', jumlahTumpuk(c.aset_tetap))];

const totalBudget: Pos[] = [
    posRp('Total realisasi', jumlah(c.budget.rows.map((r) => r.real))),
    posRp('Total plafon', jumlah(c.budget.rows.map((r) => r.plafon))),
    posRp('Sisa plafon', jumlah(c.budget.rows.map((r) => r.plafon)) - jumlah(c.budget.rows.map((r) => r.real))),
];

const totalSpk: Pos[] = [
    posJml('Total SPK', jumlah(c.spk.rows.map((r) => r.jml)), 'SPK'),
    posRp('Total nilai SPK', jumlah(c.spk.rows.map((r) => r.nilai))),
];

const totalProgress: Pos[] = [posJml('Total unit', jumlahTumpuk(c.progress), 'unit')];

const totalRetensi: Pos[] = [
    posRp('Total ditahan', jumlahSeri(c.retensi.series, 0)),
    posRp('Total dilepas', jumlahSeri(c.retensi.series, 1)),
];

// Per tahap, TIDAK dijumlahkan — lihat catatan 3 di kepala blok ini.
const totalBastAjbBr: Pos[] = c.bast_ajb_br.series.map((s) => posRp(
    'Total ' + s.n,
    s.nilai,
    'Tiga tahap ini menyangkut unit yang sama, jadi nilainya tidak dijumlahkan menjadi satu angka.',
));
// Jumlah unit setahun DIAMBIL DARI CONTROLLER (`unit_tahunan`), bukan dijumlahkan dari ember
// bulanan di sebelahnya: ember bulanan di-dedupe per (unit, bulan), sehingga satu unit yang
// dokumennya terbit ulang di bulan berbeda akan terhitung dua kali dan membantah angka rupiah
// di sampingnya.
const totalBastAjbBrUnit: Pos[] = c.bast_ajb_br.series.map((s) => posJml('Total ' + s.n, s.unit_tahunan, 'unit'));

const totalPiutang: Pos[] = [posRp('Total tagihan', jumlahTumpuk(c.piutang))];
const totalHpp: Pos[] = [posRp('Total HPP', jumlahTumpuk(c.hpp))];

function fmtDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', {
        weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Dashboard Back Office" />

    <div class="space-y-4">
        <!-- ══ Kepala halaman ══ -->
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">Dashboard Back Office</h1>
                <p class="text-sm text-neutral-500 mt-1">
                    Ringkasan 18 poin modul back office{{ greeting?.name ? ` — halo, ${greeting.name}` : '' }}
                </p>
                <p v-if="greeting?.last_login_at" class="text-xs text-neutral-400 mt-1">
                    Login terakhir: {{ fmtDateTime(greeting.last_login_at) }}
                    <span v-if="greeting.last_login_ip">· IP {{ greeting.last_login_ip }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 px-3 text-[13px] text-neutral-500">
                    Periode <b class="font-semibold text-neutral-800 dark:text-neutral-100">{{ periode.label }}</b>
                </span>
                <span class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 px-3 text-[13px] text-neutral-500">
                    Project <b class="font-semibold text-neutral-800 dark:text-neutral-100">{{ periode.project }}</b>
                </span>
            </div>
        </div>

        <!-- ══ Kisi 12 kolom ══ -->
        <div class="grid grid-cols-12 gap-3.5">
            <!-- 1 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">1 · Inisiasi KPR</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">KPR</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5 leading-relaxed">
                    Jumlah <b>berkas</b> yang lolos tiap tahap, dibaca dari atas ke bawah. Corongnya
                    menyempit karena tahap berikutnya adalah <b>bagian dari</b> tahap sebelumnya — berkas
                    yang sama, bukan kelompok yang berdiri sendiri. Angka di antara dua tahap adalah
                    jumlah berkas yang <b>tertahan</b> di tahap atasnya.
                </p>
                <VizFunnel :rows="c.inisiasi_kpr.rows" :warna="TAHAP" satuan="berkas" />
                <VizTotal :items="totalInisiasi" />
            </section>

            <!-- 2 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">2 · Proses KPR</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">KPR</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5 leading-relaxed">
                    Jumlah <b>berkas</b> pada tiap posisi pipeline bank, satu aliran dari atas ke bawah.
                    Tahap 1 di sini menyambung dari tahap 6 grafik sebelumnya. Angka di antara dua tahap
                    adalah jumlah berkas yang <b>tertahan</b> di tahap atasnya.
                </p>
                <VizFunnel :rows="c.proses_kpr.rows" :warna="TAHAP" satuan="berkas" />
                <VizTotal :items="totalProses" />
            </section>

            <!-- 3 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">3 · Penolakan BI Checking &amp; Proses KPR</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">KPR</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Dua jenis penolakan per bulan — makin rendah makin baik.</p>
                <VizGrouped :labels="c.penolakan.labels" :series="c.penolakan.series" unit-axis satuan="berkas" />
                <VizTotal :items="totalPenolakan" />
            </section>

            <!-- 4 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">4 · Summary Rekening Ledger</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CASH BOOK</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">
                    Saldo akhir tiap rekening kas &amp; bank, diurutkan dari terbesar. Kolom merah
                    ke bawah = rekening berposisi kredit. Angka disingkat — nilai penuhnya muncul
                    saat kursor menyentuh kolom.
                </p>
                <!-- deret tunggal → satu warna; tinggi kolom yang membawa besarannya -->
                <VizColumns :rows="c.rekening.rows" :flat="S[0]" :plot="170" :baris-label="3" />
                <VizTotal :items="totalRekening" />
            </section>

            <!-- 5 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">5 · Bon Sementara / Kasbon</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CASH BOOK</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Pengajuan vs pertanggungjawaban per bulan — sumbu dalam <b>juta rupiah</b>.</p>
                <VizGrouped :labels="c.kasbon.labels" :series="c.kasbon.series" />
                <VizTotal :items="totalKasbon" />
            </section>

            <!-- 6 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">6 · Aging Hutang</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">ACCOUNT PAYABLE</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">
                    Umur hutang supplier — makin tua kelompoknya, makin pekat oranyenya. Angka
                    disingkat; nilai penuhnya muncul saat kursor menyentuh kolom.
                </p>
                <!-- corak mengikuti URUTAN kelompok umur, bukan nilainya: lima kelompok, lima corak -->
                <VizColumns :rows="c.aging.rows" :ramp="ORA" :plot="170" />
                <VizTotal :items="totalAging" />
            </section>

            <!-- 7 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">7 · Summary Neraca</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">GENERAL LEDGER</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Sisi aktiva dibanding pasiva — keduanya harus seimbang.</p>
                <VizHStack :rows="c.neraca.rows" :keys="c.neraca.keys" />
                <VizTotal :items="totalNeraca" />
            </section>

            <!-- 8 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">8 · Summary Laba Rugi</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">GENERAL LEDGER</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Laba (biru) atau rugi (merah) tiap bulan, diukur dari garis nol — sumbu dalam <b>juta rupiah</b>.</p>
                <VizDiverging :labels="c.laba_rugi.labels" :vals="c.laba_rugi.vals" />
                <VizTotal :items="totalLabaRugi" :sumber="c.laba_rugi.vals" />
            </section>

            <!-- 9 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">9 · Overview Aset Tetap</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">ASET TETAP</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Nilai perolehan terbagi menjadi akumulasi penyusutan dan nilai buku.</p>
                <VizHStack :rows="c.aset_tetap.rows" :keys="c.aset_tetap.keys" />
                <VizTotal :items="totalAsetTetap" />
            </section>

            <!-- 10 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">10 · Realisasi Budget</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">BUDGET CONTROL</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Realisasi terhadap plafon tiap cost center. Garis tegak = plafon.</p>
                <VizMeters :rows="c.budget.rows" />
                <VizTotal :items="totalBudget" />
            </section>

            <!-- 11 — dua ukuran, dua panel -->
            <section class="card col-span-12 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">11 · SPK per Cluster</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CONTRACTOR MGT</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5 leading-relaxed">
                    Jumlah SPK dan total nilainya. Dua ukuran ini beda satuan, jadi dipisah menjadi dua panel —
                    bukan dua sumbu pada satu grafik.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-[10.5px] font-bold tracking-wide text-neutral-400 mb-1">JUMLAH SPK (TIDAK BATAL)</p>
                        <VizHBars :rows="spkJumlah" :ramp="ORA" :kunci="kunciSpk" hitung satuan="SPK" :talang-kiri="96" :talang-kanan="78" />
                    </div>
                    <div>
                        <p class="text-[10.5px] font-bold tracking-wide text-neutral-400 mb-1">TOTAL NILAI SPK</p>
                        <VizHBars :rows="spkNilai" :ramp="ORA" :kunci="kunciSpk" :talang-kiri="96" :talang-kanan="132" />
                    </div>
                </div>
                <VizTotal :items="totalSpk" />
            </section>

            <!-- 12 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">12 · Progress Lapangan</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CONTRACTOR MGT</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Sebaran unit menurut tahap pembangunan.</p>
                <VizHStack :rows="c.progress.rows" :keys="c.progress.keys" hitung satuan="unit" />
                <VizTotal :items="totalProgress" />
            </section>

            <!-- 13 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">13 · Summary Retensi</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CONTRACTOR MGT</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Retensi SPK ditahan vs yang sudah dilepas per bulan — sumbu dalam <b>juta rupiah</b>.</p>
                <VizGrouped :labels="c.retensi.labels" :series="c.retensi.series" />
                <VizTotal :items="totalRetensi" />
            </section>

            <!-- 14–16 — tiga tahapan, satu kartu -->
            <section class="card col-span-12 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">14–16 · BAST → AJB → Booked Revenue</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">BOOKED REVENUE</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5 leading-relaxed">
                    Satu grafik untuk tiga tahapan yang sama urutannya. Kiri:
                    <b class="text-neutral-700 dark:text-neutral-200">rincian per bulan dalam jumlah unit</b> — jarak antar batang
                    memperlihatkan berapa unit yang tertinggal di tiap tahap. Kanan:
                    <b class="text-neutral-700 dark:text-neutral-200">ringkasan setahun dalam rupiah</b>, dipisah karena satuannya berbeda.
                </p>
                <div class="grid grid-cols-1 lg:grid-cols-[1.75fr_1fr] gap-5 items-start">
                    <div>
                        <p class="text-[10.5px] font-bold tracking-wide text-neutral-400 mb-1">RINCIAN BULANAN — JUMLAH UNIT</p>
                        <VizGrouped :labels="c.bast_ajb_br.labels" :series="brUnit" unit-axis satuan="unit" :tinggi="236" />
                    </div>
                    <div>
                        <p class="text-[10.5px] font-bold tracking-wide text-neutral-400 mb-1">RINGKASAN TAHUNAN — NILAI</p>
                        <VizYearTotals :rows="brNilai" :total="false" />
                    </div>
                </div>
                <!-- tiga tahap atas unit yang SAMA — ditulis per tahap, tidak dijumlahkan jadi satu angka -->
                <VizTotal :items="totalBastAjbBrUnit" />
                <VizTotal :items="totalBastAjbBr" />
            </section>

            <!-- 17 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">17 · Rekonsiliasi Piutang</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">PIUTANG USAHA (BOOKED)</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Tagihan terbagi menjadi sudah terbayar dan sisa piutang.</p>
                <VizHStack :rows="c.piutang.rows" :keys="c.piutang.keys" />
                <VizTotal :items="totalPiutang" />
            </section>

            <!-- 18 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">18 · Summary HPP</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">COGS</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Susunan harga pokok penjualan per cluster.</p>
                <VizHStack :rows="c.hpp.rows" :keys="c.hpp.keys" />
                <VizTotal :items="totalHpp" />
            </section>
        </div>
    </div>
</template>
