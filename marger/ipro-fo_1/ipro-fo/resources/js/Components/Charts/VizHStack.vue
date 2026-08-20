<script setup lang="ts">
// Batang bertumpuk horizontal — komposisi tiap baris, satu segmen per kunci.
// Warna mengikuti KUNCI (entitas), bukan urutan besarannya, sehingga menyaring
// baris tidak pernah mengecat ulang segmen yang tersisa.
//
// Penempatan angka
// ----------------
// Angka di dalam segmen ditulis putih di atas warnanya. Karena itu angka yang lebih lebar
// daripada segmennya tidak sekadar terlihat sesak — ia MENGHILANG: bagian yang melewati tepi
// segmen jatuh ke latar putih kartu dan tidak terbaca sama sekali. Dulu keputusannya diambil
// dari lebar segmen saja (`w > 58`), tanpa menimbang lebar angkanya, sehingga
// "Rp 2.057.301.583" pada segmen selebar 60 satuan terpotong di tengah jalan, sementara
// segmen yang lebih sempit lagi tidak diberi angka sama sekali.
//
// Sekarang lebar angkanya DITAKSIR lebih dulu:
//   muat di dalam segmen   → ditulis di dalam, putih
//   tidak muat, segmen akhir → ditulis DI LUAR ujung batang, warna tinta
//   tidak muat, segmen tengah → tidak ditulis (menaruhnya di luar akan menimpa segmen
//                               sebelahnya); nilainya tetap ada di tooltip
//
// Talang kanan hanya dilebarkan bila memang ADA angka yang perlu ditulis di luar, supaya
// grafik yang semua angkanya muat tidak kehilangan panjang batang tanpa alasan.
import { computed } from 'vue';
import { S, rp, maxAman, kosong, type Kunci, type BarisTumpuk } from './viz';

const props = withDefaults(defineProps<{
    rows: BarisTumpuk[];
    keys: Kunci[];
    hitung?: boolean;
    satuan?: string;
}>(), { hitung: false, satuan: '' });

const W = 560, PL = 144, BH = 24, GP = 10, PT = 6;
const PR_SEMPIT = 16;    // talang kanan saat semua angka muat di dalam segmennya
const SELA = 8;          // jarak angka luar dari ujung batang
const PAD = 10;          // ruang napas kiri-kanan angka di DALAM segmen

// Taksiran lebar satu karakter pada font 11px, dalam satuan viewBox. Sengaja DILEBIHKAN:
// bila meleset, angkanya harus meleset ke arah "dianggap tidak muat" — angka yang pindah ke
// luar padahal sebenarnya muat masih terbaca, angka yang dipaksa masuk lalu terpotong tidak.
const KAR = 6.2;

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

/** Susun seluruh baris untuk talang kanan tertentu. */
function susun(pr: number) {
    const iw = W - PL - pr;
    const akhir = props.keys.length - 1;
    let adaLuar = false;

    const baris = props.rows.map((r, i) => {
        let x = PL;
        const seg = props.keys.map((k, j) => {
            const v = nilai(r, k.k);
            const w = iw * v / puncak.value;
            const teks = label(v);
            // Muat = angkanya masih menyisakan ruang napas di kedua sisi segmennya.
            const muat = w >= lebarTeks(teks) + PAD;
            const terakhir = j === akhir;
            const s = {
                key: k.k, x, w,
                // sudut membulat hanya di ujung akhir supaya tumpukan terbaca menyatu
                rx: terakhir ? 4 : 2,
                fill: S[k.c],
                judul: `${r.n} · ${k.n}: ${teks}`,
                dalam: muat ? teks : '',
                // Angka yang tidak muat hanya boleh keluar dari segmen TERAKHIR; segmen
                // tengah tidak punya ruang di kanannya selain segmen berikutnya.
                luar: (! muat && terakhir && v > 0) ? teks : '',
                tx: x + w / 2 - 1,
            };
            x += w;
            if (s.luar) adaLuar = true;

            return s;
        }).filter((s) => s.w > 0);

        return { n: r.n, y: PT + i * (BH + GP), seg, xAkhir: x };
    });

    return { baris, adaLuar };
}

// Dua langkah: susun dulu dengan talang sempit untuk mengetahui apakah ada angka yang harus
// keluar, baru susun ulang dengan talang lebar bila memang perlu.
const hasil = computed(() => {
    const coba = susun(PR_SEMPIT);
    if (! coba.adaLuar) {
        return { ...coba, pr: PR_SEMPIT };
    }
    // Talang selebar angka terpanjang yang akan ditulis di luar, plus selanya.
    const terpanjang = Math.max(...coba.baris.flatMap((b) => b.seg.map((s) => (s.luar ? lebarTeks(s.luar) : 0))));

    return { ...susun(Math.ceil(terpanjang + SELA * 2)), pr: Math.ceil(terpanjang + SELA * 2) };
});

const baris = computed(() => hasil.value.baris);
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
                <g v-for="b in baris" :key="b.n">
                    <text :x="PL - 10" :y="b.y + BH / 2 + 4" text-anchor="end" class="dlab">{{ b.n }}</text>
                    <g v-for="s in b.seg" :key="s.key">
                        <!-- lebar dikurangi 2px: itulah jeda permukaan antar segmen -->
                        <rect data-mark :x="s.x.toFixed(1)" :y="b.y" :width="Math.max(1, s.w - 2).toFixed(1)"
                              :height="BH" :rx="s.rx" :fill="s.fill"><title>{{ s.judul }}</title></rect>
                        <text v-if="s.dalam" :x="s.tx.toFixed(1)" :y="b.y + BH / 2 + 4" text-anchor="middle" class="seglab">{{ s.dalam }}</text>
                        <!-- Angka yang tidak muat: di luar ujung batang, warna tinta — bukan
                             putih, sebab di sini latarnya permukaan kartu, bukan segmen. -->
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
/* Angka di luar batang memakai tinta biasa dan tebal yang sama dengan angka di dalamnya,
   supaya keduanya terbaca sebagai satu deret angka — hanya letaknya yang berbeda. */
.viz :deep(.seglab-luar) { font-size: 11px; fill: var(--viz-ink); font-weight: 700; }
</style>
