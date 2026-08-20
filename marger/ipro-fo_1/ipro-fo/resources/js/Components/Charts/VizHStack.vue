<script setup lang="ts">
// Batang bertumpuk horizontal — komposisi tiap baris, satu segmen per kunci.
// Warna mengikuti KUNCI (entitas), bukan urutan besarannya, sehingga menyaring
// baris tidak pernah mengecat ulang segmen yang tersisa.
//
// Penempatan angka
// ----------------
// Angka SELALU ditulis DI LUAR ujung batang, dengan warna tinta.
//
// Dulu ia ditulis di dalam segmen dengan warna putih bila segmennya kebetulan cukup lebar.
// Dua akibatnya: angka yang lebih lebar daripada segmennya MENGHILANG — bagian yang
// melewati tepi segmen jatuh ke latar putih kartu dan tidak terbaca sama sekali — dan
// angka-angka itu tidak pernah berbaris rata, sebab letaknya mengikuti panjang batang
// masing-masing sehingga mata harus mencarinya di tempat berbeda tiap baris.
//
// Di luar batang, ketiganya beres sekaligus: tidak ada yang bisa terpotong, warnanya selalu
// kontras terhadap permukaan kartu, dan seluruh angka berbaris pada satu garis rata kiri
// sehingga mudah dibandingkan dari atas ke bawah.
//
// Talang kiri & kanan DIUKUR dari isinya — selebar nama baris terpanjang dan angka
// terpanjang — bukan angka tetap. Nama baris di sini pendek ("Bangunan", "Inventaris"),
// jadi talang tetap selebar 144 satuan hanya akan menyisakan ruang kosong di kiri dan
// memendekkan batangnya tanpa alasan.
import { computed } from 'vue';
import { S, rp, maxAman, kosong, type Kunci, type BarisTumpuk } from './viz';

const props = withDefaults(defineProps<{
    rows: BarisTumpuk[];
    keys: Kunci[];
    hitung?: boolean;
    satuan?: string;
}>(), { hitung: false, satuan: '' });

const W = 560, BH = 24, GP = 10, PT = 6;
const SELA = 9;          // jarak angka dari ujung batang
const PL_MIN = 56;       // talang kiri paling sempit, supaya nama tidak menempel tepi kartu
const PL_MAKS = 168;     // batas atas, supaya nama yang sangat panjang tidak menelan batangnya
const NAPAS = 12;        // jarak nama baris ke pangkal batang

// Taksiran lebar satu karakter pada font 11px (angka) dan 10,5px (nama baris), dalam satuan
// viewBox. Sengaja DILEBIHKAN: talang yang sedikit terlalu lebar hanya menyisakan ruang
// kosong, sedangkan talang yang terlalu sempit memotong tulisannya.
const KAR = 6.2;
const KAR_NAMA = 5.9;

const H = computed(() => PT * 2 + props.rows.length * (BH + GP));

// Jaring pengaman terakhir: nilai negatif akan menggeser kursor x mundur sehingga segmen
// berikutnya tumpang tindih — batangnya salah gambar, bukan sekadar jelek. Controller sudah
// menjepitnya, tapi komponen ini tidak boleh bergantung pada itu.
const nilai = (r: BarisTumpuk, k: string): number => {
    const v = r[k];

    return typeof v === 'number' && Number.isFinite(v) && v > 0 ? v : 0;
};

const total = computed(() => props.rows.map((r) => props.keys.reduce((a, k) => a + nilai(r, k.k), 0)));
const puncak = computed(() => maxAman(total.value));
const hampa = computed(() => kosong(total.value));

function label(v: number): string {
    return props.hitung ? `${v.toLocaleString('id-ID')}${props.satuan ? ' ' + props.satuan : ''}` : rp(v);
}

const lebarTeks = (t: string) => t.length * KAR;

/**
 * Talang kiri: selebar nama baris terpanjang, dijepit antara batas bawah & atas.
 * Diukur dari isinya supaya grafik dengan nama pendek tidak menyisakan ruang kosong —
 * itulah yang menggeser seluruh batang ke kiri dan memanjangkannya.
 */
const PL = computed(() => {
    const terpanjang = Math.max(0, ...props.rows.map((r) => String(r.n ?? '').length * KAR_NAMA));

    return Math.round(Math.min(PL_MAKS, Math.max(PL_MIN, terpanjang + NAPAS)));
});

/**
 * Talang kanan: selebar angka terpanjang yang akan ditulis di sana, plus selanya.
 *
 * Angka yang ditulis adalah nilai segmen TERAKHIR tiap baris. Segmen sebelumnya tidak
 * diberi angka di luar — tempatnya sama dan keduanya akan saling menimpa; nilainya tetap
 * terbaca lewat tooltip segmennya.
 */
const PR = computed(() => {
    const akhir = props.keys[props.keys.length - 1];
    const terpanjang = Math.max(0, ...props.rows.map((r) => {
        const v = nilai(r, akhir?.k ?? '');

        return v > 0 ? lebarTeks(label(v)) : 0;
    }));

    return Math.ceil(terpanjang + SELA * 2);
});

const baris = computed(() => {
    const pl = PL.value;
    const iw = W - pl - PR.value;
    const akhir = props.keys.length - 1;

    return props.rows.map((r, i) => {
        let x = pl;
        const seg = props.keys.map((k, j) => {
            const v = nilai(r, k.k);
            const w = iw * v / puncak.value;
            const teks = label(v);
            const terakhir = j === akhir;
            const s = {
                key: k.k, x, w,
                // sudut membulat hanya di ujung akhir supaya tumpukan terbaca menyatu
                rx: terakhir ? 4 : 2,
                fill: S[k.c],
                judul: `${r.n} · ${k.n}: ${teks}`,
                // Hanya segmen terakhir yang angkanya dicetak, dan selalu di LUAR batang.
                luar: (terakhir && v > 0) ? teks : '',
            };
            x += w;

            return s;
        }).filter((s) => s.w > 0);

        return { n: r.n, y: PT + i * (BH + GP), seg, xAkhir: x };
    });
});
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
                <g v-for="b in baris" :key="b.n">
                    <text :x="PL - NAPAS + 2" :y="b.y + BH / 2 + 4" text-anchor="end" class="dlab">{{ b.n }}</text>
                    <g v-for="s in b.seg" :key="s.key">
                        <!-- lebar dikurangi 2px: itulah jeda permukaan antar segmen -->
                        <rect data-mark :x="s.x.toFixed(1)" :y="b.y" :width="Math.max(1, s.w - 2).toFixed(1)"
                              :height="BH" :rx="s.rx" :fill="s.fill"><title>{{ s.judul }}</title></rect>
                        <!-- Angka SELALU di luar ujung batang dan berwarna tinta: di sini
                             latarnya permukaan kartu, bukan segmen, jadi putih akan hilang. -->
                        <text v-if="s.luar" :x="(s.x + s.w + SELA - 2).toFixed(1)" :y="b.y + BH / 2 + 4"
                              text-anchor="start" class="seglab-luar">{{ s.luar }}</text>
                    </g>
                </g>
            </svg>
            <div class="mt-2 flex flex-wrap gap-2.5 text-[11.5px] text-neutral-600 dark:text-neutral-300">
                <span v-for="(k, i) in keys" :key="i" class="inline-flex items-center gap-1.5">
                    <i class="inline-block h-2.5 w-2.5 rounded-[3px]" :style="{ background: S[k.c] }"></i>{{ k.n }}
                </span>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Warna tinta — hitam pada mode terang. SENGAJA memakai var(--viz-ink), bukan #000 mati:
   pada mode gelap permukaan kartunya ikut menjadi gelap, dan hitam di atas gelap sama
   tidak terbacanya dengan putih di atas terang — persoalan yang sama, hanya berpindah. */
.viz :deep(.seglab-luar) { font-size: 11px; fill: var(--viz-ink); font-weight: 700; }
</style>
