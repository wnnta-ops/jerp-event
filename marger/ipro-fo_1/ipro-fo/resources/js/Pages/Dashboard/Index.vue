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
import VizFunnel from '@/Components/Charts/VizFunnel.vue';
import VizGrouped from '@/Components/Charts/VizGrouped.vue';
import VizHBars from '@/Components/Charts/VizHBars.vue';
import VizHStack from '@/Components/Charts/VizHStack.vue';
import VizDiverging from '@/Components/Charts/VizDiverging.vue';
import VizMeters from '@/Components/Charts/VizMeters.vue';
import VizYearTotals from '@/Components/Charts/VizYearTotals.vue';
import { ORA, TAHAP, S, type Seri, type Kunci, type Baris, type BarisTumpuk } from '@/Components/Charts/viz';

interface Bulanan { labels: string[]; series: Seri[] }
interface Tumpuk { rows: BarisTumpuk[]; keys: Kunci[] }
interface SeriGabungan { n: string; c: number; unit: number[]; nilai: number }

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
const brTotalUnit = c.bast_ajb_br.series.map((s) => ({
    n: s.n, total: s.unit.reduce((a, b) => a + b, 0),
}));

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
                    Berkas yang lolos tiap tahap — corong memperlihatkan di tahap mana berkas tertahan.
                    Tiap tahap punya warnanya sendiri; urutannya dibaca dari nomor, posisi, dan panjang batang.
                </p>
                <VizFunnel :rows="c.inisiasi_kpr.rows" :warna="TAHAP" />
            </section>

            <!-- 2 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">2 · Proses KPR</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">KPR</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5 leading-relaxed">
                    Posisi berkas pada pipeline bank. Tahap 1 di sini menyambung dari tahap 6 grafik sebelumnya.
                </p>
                <VizFunnel :rows="c.proses_kpr.rows" :warna="TAHAP" />
            </section>

            <!-- 3 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">3 · Penolakan BI Checking &amp; Proses KPR</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">KPR</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Dua jenis penolakan per bulan — makin rendah makin baik.</p>
                <VizGrouped :labels="c.penolakan.labels" :series="c.penolakan.series" unit-axis satuan="berkas" />
            </section>

            <!-- 4 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">4 · Summary Rekening Ledger</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CASH BOOK</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Saldo akhir tiap rekening kas &amp; bank, diurutkan dari terbesar.</p>
                <!-- deret tunggal → satu warna; panjang batang yang membawa besarannya -->
                <VizHBars :rows="c.rekening.rows" :flat="S[0]" />
            </section>

            <!-- 5 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">5 · Bon Sementara / Kasbon</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CASH BOOK</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Pengajuan vs pertanggungjawaban per bulan.</p>
                <VizGrouped :labels="c.kasbon.labels" :series="c.kasbon.series" />
            </section>

            <!-- 6 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">6 · Aging Hutang</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">ACCOUNT PAYABLE</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Umur hutang supplier — makin tua kelompoknya, makin pekat oranyenya.</p>
                <!-- corak mengikuti URUTAN kelompok umur, bukan nilainya: lima kelompok, lima corak -->
                <VizHBars :rows="c.aging.rows" :ramp="ORA" />
            </section>

            <!-- 7 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">7 · Summary Neraca</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">GENERAL LEDGER</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Sisi aktiva dibanding pasiva — keduanya harus seimbang.</p>
                <VizHStack :rows="c.neraca.rows" :keys="c.neraca.keys" />
            </section>

            <!-- 8 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">8 · Summary Laba Rugi</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">GENERAL LEDGER</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Laba (biru) atau rugi (merah) tiap bulan, diukur dari garis nol.</p>
                <VizDiverging :labels="c.laba_rugi.labels" :vals="c.laba_rugi.vals" />
            </section>

            <!-- 9 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">9 · Overview Aset Tetap</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">ASET TETAP</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Nilai perolehan terbagi menjadi akumulasi penyusutan dan nilai buku.</p>
                <VizHStack :rows="c.aset_tetap.rows" :keys="c.aset_tetap.keys" />
            </section>

            <!-- 10 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">10 · Realisasi Budget</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">BUDGET CONTROL</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Realisasi terhadap plafon tiap cost center. Garis tegak = plafon.</p>
                <VizMeters :rows="c.budget.rows" />
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
                        <p class="text-[10.5px] font-bold tracking-wide text-neutral-400 mb-1">JUMLAH SPK AKTIF</p>
                        <VizHBars :rows="spkJumlah" :ramp="ORA" :kunci="kunciSpk" hitung satuan="SPK" :talang-kiri="96" :talang-kanan="78" />
                    </div>
                    <div>
                        <p class="text-[10.5px] font-bold tracking-wide text-neutral-400 mb-1">TOTAL NILAI SPK</p>
                        <VizHBars :rows="spkNilai" :ramp="ORA" :kunci="kunciSpk" :talang-kiri="96" :talang-kanan="132" />
                    </div>
                </div>
            </section>

            <!-- 12 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">12 · Progress Lapangan</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CONTRACTOR MGT</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Sebaran unit menurut tahap pembangunan.</p>
                <VizHStack :rows="c.progress.rows" :keys="c.progress.keys" hitung satuan="unit" />
            </section>

            <!-- 13 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">13 · Summary Retensi</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">CONTRACTOR MGT</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Retensi ditahan vs yang sudah dilepas per bulan.</p>
                <VizGrouped :labels="c.retensi.labels" :series="c.retensi.series" />
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
                        <VizYearTotals :rows="brNilai" />
                        <p class="mt-2.5 text-[11.5px] text-neutral-600 dark:text-neutral-300 leading-relaxed">
                            Total unit setahun —
                            <template v-for="(t, i) in brTotalUnit" :key="t.n">
                                <span v-if="i"> · </span>{{ t.n }}
                                <b class="text-neutral-800 dark:text-neutral-100">{{ t.total.toLocaleString('id-ID') }}</b>
                            </template>
                        </p>
                    </div>
                </div>
            </section>

            <!-- 17 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">17 · Rekonsiliasi Piutang</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">PIUTANG USAHA (BOOKED)</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Tagihan terbagi menjadi sudah terbayar dan sisa piutang.</p>
                <VizHStack :rows="c.piutang.rows" :keys="c.piutang.keys" />
            </section>

            <!-- 18 -->
            <section class="card col-span-12 lg:col-span-6 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-bold">18 · Summary HPP</h2>
                    <span class="text-[10.5px] font-bold tracking-wide text-neutral-400">COGS</span>
                </div>
                <p class="text-xs text-neutral-500 mt-0.5 mb-2.5">Susunan harga pokok penjualan per cluster.</p>
                <VizHStack :rows="c.hpp.rows" :keys="c.hpp.keys" />
            </section>
        </div>
    </div>
</template>
