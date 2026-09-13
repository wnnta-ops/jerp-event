<script setup lang="ts">
/*
 * Blok analisa di bawah laporan: apa yang paling menggerakkan angkanya, seberapa besar
 * peranannya terhadap keseluruhan, dan apa yang menahannya dari arah sebaliknya.
 *
 * Dipakai bersama Neraca, Laba Rugi, dan Laba Rugi vs Budget. Satu komponen, bukan tiga
 * salinan: kalimat yang ditulis tiga kali akan lambat laun mengatakan tiga hal yang berbeda
 * untuk keadaan yang sama.
 *
 * Peringkatnya datang dari PHP (AnalisaKenaikan) dan SELALU menurut rupiah, bukan persentase.
 * Komponen ini hanya menyusun kalimatnya.
 */
import { computed } from 'vue';
import { TrendingUp, TrendingDown, Lightbulb } from 'lucide-vue-next';

interface Gerak {
    kode: string; nama: string; grup: string;
    kini: number; lalu: number; delta: number;
    pct: number | null; baru: boolean;
}
interface Analisa {
    label_kini: string; label_lalu: string;
    naik: Gerak[]; turun: Gerak[];
    jumlah_naik: number; jumlah_turun: number; netto: number;
    akun_naik: number; akun_turun: number;
    pusat_pct: number | null;
    /* Sumbangan seluruh pergerakan terhadap LABA. Kosong pada Neraca, yang memang tidak
       menyentuh laba. Sengaja terpisah dari `netto`: pendapatan naik dan beban naik dua-duanya
       "naik", tetapi bagi laba yang satu menambah dan yang satu menggerus. */
    dampak_laba: number | null;
}

const KOSONG: Analisa = {
    label_kini: '', label_lalu: '', naik: [], turun: [],
    jumlah_naik: 0, jumlah_turun: 0, netto: 0, akun_naik: 0, akun_turun: 0,
    pusat_pct: null, dampak_laba: null,
};

const props = withDefaults(defineProps<{
    /* Boleh kosong. Analisa adalah tambahan di bawah laporan, bukan laporannya sendiri —
       kalau datanya tidak sampai, yang hilang cukup bloknya, jangan seluruh halaman laporan
       keuangan ikut gelap. Bahwa ketiga controller MEMANG mengirimnya dijaga uji PHP. */
    analisa?: Analisa | null;
    judul?: string;
    /* 'periode' membandingkan dua rentang waktu; 'budget' membandingkan realisasi dengan
       anggaran. Kata kerjanya berbeda: sebuah akun "naik" dibanding tahun lalu, tetapi
       "melampaui anggaran" — bukan "naik dari anggaran". */
    mode?: 'periode' | 'budget';
}>(), { analisa: null, judul: 'Analisa Pergerakan', mode: 'periode' });

const analisa = computed<Analisa>(() => props.analisa ?? KOSONG);

const kata = computed(() => props.mode === 'budget'
    ? { naik: 'melampaui anggaran', turun: 'di bawah anggaran', naikSingkat: 'lebih', turunSingkat: 'hemat' }
    : { naik: 'naik', turun: 'turun', naikSingkat: 'naik', turunSingkat: 'turun' });

function rp(v: number | null) {
    const n = Number(v ?? 0);
    const s = 'Rp ' + Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });

    return n < 0 ? '(' + s + ')' : s;
}
/* Rupiah diringkas HANYA di dalam kalimat, supaya kalimatnya tetap enak dibaca. Tabel di
   bawahnya tetap memuat angka penuhnya. */
function ringkas(v: number | null) {
    const n = Math.abs(Number(v ?? 0));
    if (n >= 1_000_000_000) return 'Rp ' + (n / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' M';
    if (n >= 1_000_000) return 'Rp ' + (n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';

    return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 0 });
}
function pct(v: number | null) {
    return v === null ? null : (v > 0 ? '+' : '') + v.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
}
/* Persentase di dalam kalimat ditulis tanpa tanda +/−, tetapi tetap berkoma Indonesia —
   "88.2%" di tengah kalimat berbahasa Indonesia terbaca seperti salah ketik. */
function angkaPct(v: number | null) {
    return Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 1 });
}

const utama = computed(() => analisa.value.naik[0] ?? null);
const penahan = computed(() => analisa.value.turun[0] ?? null);
/* Bagian yang disumbang penggerak teratas terhadap SELURUH kenaikan. Inilah yang membedakan
   "naik paling besar" dari "yang sebenarnya menjelaskan kenaikannya". */
const bagianUtama = computed(() => {
    const a = analisa.value;
    if (! utama.value || a.jumlah_naik <= 0) return null;

    return Math.round(utama.value.delta / a.jumlah_naik * 100);
});
const terpusat = computed(() => (analisa.value.pusat_pct ?? 0) >= 70);
const adaIsi = computed(() => analisa.value.naik.length > 0 || analisa.value.turun.length > 0);
const pendamping = computed(() => analisa.value.naik.slice(1, 3));
</script>

<template>
    <div class="card p-5">
        <h2 class="mb-1 flex items-center gap-2 text-sm font-bold">
            <Lightbulb class="h-4 w-4 text-primary-600" /> {{ judul }}
        </h2>
        <p class="mb-4 text-[11px] text-neutral-500">
            Membandingkan <b>{{ analisa.label_kini }}</b> dengan <b>{{ analisa.label_lalu }}</b>.
            Diurutkan menurut <b>besarnya rupiah</b>, bukan persentase — akun kecil yang naik berlipat
            tidak menggerakkan angka totalnya.
        </p>

        <div v-if="!adaIsi" class="rounded-lg bg-neutral-50 px-4 py-6 text-center text-sm text-neutral-500 dark:bg-neutral-800/60">
            Tidak ada pergerakan yang berarti dibanding {{ analisa.label_lalu }}.
        </div>

        <template v-else>
            <!-- ── Kalimat analisa ─────────────────────────────────────────── -->
            <div class="space-y-2 text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">
                <p v-if="utama">
                    Yang paling menggerakkan angkanya adalah
                    <b class="text-neutral-900 dark:text-white">{{ utama.nama }}</b>
                    <span v-if="utama.grup" class="text-neutral-400"> ({{ utama.grup }})</span> —
                    {{ kata.naik }} <b>{{ ringkas(utama.delta) }}</b><span v-if="pct(utama.pct)"> ({{ pct(utama.pct) }})</span>.
                    <template v-if="utama.baru">
                        Pos ini <b>sama sekali belum ada</b> pada {{ analisa.label_lalu }}, jadi ini hal baru —
                        bukan kenaikan dari angka yang sudah berjalan.
                    </template>
                    <template v-else-if="bagianUtama !== null">
                        Sendirian, ia menyumbang <b>{{ bagianUtama }}%</b> dari seluruh kenaikan.
                    </template>
                </p>

                <p v-if="pendamping.length">
                    Disusul
                    <template v-for="(g, i) in pendamping" :key="g.kode || g.nama">
                        <b>{{ g.nama }}</b> ({{ ringkas(g.delta) }}){{ i < pendamping.length - 1 ? ' dan ' : '' }}
                    </template>.
                </p>

                <p v-if="penahan">
                    Di arah sebaliknya, <b class="text-neutral-900 dark:text-white">{{ penahan.nama }}</b>
                    {{ kata.turun }} <b>{{ ringkas(penahan.delta) }}</b><span v-if="pct(penahan.pct)"> ({{ pct(penahan.pct) }})</span> —
                    itulah yang menahan angka keseluruhannya dari naik lebih tinggi lagi.
                </p>

                <p>
                    Seluruhnya: <b>{{ analisa.akun_naik }}</b> akun {{ kata.naikSingkat }} sebesar
                    <b>{{ ringkas(analisa.jumlah_naik) }}</b>, <b>{{ analisa.akun_turun }}</b> akun
                    {{ kata.turunSingkat }} sebesar <b>{{ ringkas(analisa.jumlah_turun) }}</b>.
                </p>

                <!-- Kalimat inilah yang menjawab "lalu labanya bagaimana". Tanpa pemisahan ini,
                     pendapatan naik 150 jt dan beban naik 20 jt terjumlah menjadi 170 jt yang
                     terbaca seperti kenaikan laba — padahal labanya hanya naik 130 jt. -->
                <p v-if="analisa.dampak_laba !== null" class="rounded-lg bg-neutral-50 px-3 py-2 dark:bg-neutral-800/60">
                    Bagi <b>laba</b>, setelah beban ikut diperhitungkan, seluruh pergerakan ini
                    <template v-if="analisa.dampak_laba >= 0">
                        <b class="text-success-700 dark:text-success-400">menambah {{ ringkas(analisa.dampak_laba) }}</b>.
                    </template>
                    <template v-else>
                        <b class="text-danger-600">menggerus {{ ringkas(analisa.dampak_laba) }}</b>.
                    </template>
                    Angka itu berbeda dari jumlah kenaikan di atas, dan memang seharusnya berbeda:
                    beban yang naik ikut terhitung sebagai kenaikan pada akunnya sendiri, tetapi
                    bagi laba ia mengurangi.
                </p>

                <p v-if="analisa.pusat_pct !== null">
                    <template v-if="terpusat">
                        Pergerakannya <b>terpusat</b> — lima akun teratas mencakup <b>{{ angkaPct(analisa.pusat_pct) }}%</b>
                        dari seluruh kenaikan. Memperhatikan segelintir akun itu saja sudah menjelaskan hampir
                        seluruh perubahannya.
                    </template>
                    <template v-else>
                        Pergerakannya <b>tersebar</b> — lima akun teratas hanya mencakup <b>{{ angkaPct(analisa.pusat_pct) }}%</b>
                        dari seluruh kenaikan. Perubahannya datang dari banyak akun kecil, bukan dari satu-dua
                        penyebab, jadi mencari satu biang keladi tidak akan menjelaskannya.
                    </template>
                </p>
            </div>

            <!-- ── Rincian angkanya ────────────────────────────────────────── -->
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div v-if="analisa.naik.length">
                    <h3 class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-success-700 dark:text-success-400">
                        <TrendingUp class="h-3.5 w-3.5" /> {{ mode === 'budget' ? 'Paling melampaui anggaran' : 'Kenaikan tertinggi' }}
                    </h3>
                    <table class="w-full text-xs">
                        <thead><tr class="text-left uppercase text-neutral-400">
                            <th class="py-1">Akun</th>
                            <th class="py-1 text-right">{{ analisa.label_lalu }}</th>
                            <th class="py-1 text-right">{{ analisa.label_kini }}</th>
                            <th class="py-1 text-right">Selisih</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="g in analisa.naik" :key="'n' + (g.kode || g.nama)">
                                <td class="py-1.5 pr-2">
                                    <span v-if="g.kode" class="font-mono text-[10px] text-neutral-400">{{ g.kode }}</span>
                                    {{ g.nama }}
                                </td>
                                <td class="py-1.5 text-right font-mono text-neutral-500">{{ rp(g.lalu) }}</td>
                                <td class="py-1.5 text-right font-mono">{{ rp(g.kini) }}</td>
                                <td class="py-1.5 text-right font-mono font-semibold text-success-700 dark:text-success-400">
                                    {{ rp(g.delta) }}<span v-if="pct(g.pct)" class="ml-1 font-normal text-[10px] text-neutral-400">{{ pct(g.pct) }}</span>
                                    <span v-else class="ml-1 font-normal text-[10px] text-neutral-400">baru</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="analisa.turun.length">
                    <h3 class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-danger-600 dark:text-danger-400">
                        <TrendingDown class="h-3.5 w-3.5" /> {{ mode === 'budget' ? 'Paling hemat dari anggaran' : 'Penurunan terdalam' }}
                    </h3>
                    <table class="w-full text-xs">
                        <thead><tr class="text-left uppercase text-neutral-400">
                            <th class="py-1">Akun</th>
                            <th class="py-1 text-right">{{ analisa.label_lalu }}</th>
                            <th class="py-1 text-right">{{ analisa.label_kini }}</th>
                            <th class="py-1 text-right">Selisih</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="g in analisa.turun" :key="'t' + (g.kode || g.nama)">
                                <td class="py-1.5 pr-2">
                                    <span v-if="g.kode" class="font-mono text-[10px] text-neutral-400">{{ g.kode }}</span>
                                    {{ g.nama }}
                                </td>
                                <td class="py-1.5 text-right font-mono text-neutral-500">{{ rp(g.lalu) }}</td>
                                <td class="py-1.5 text-right font-mono">{{ rp(g.kini) }}</td>
                                <td class="py-1.5 text-right font-mono font-semibold text-danger-600 dark:text-danger-400">{{ rp(g.delta) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
