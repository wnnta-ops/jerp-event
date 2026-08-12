<script setup lang="ts">
// Batang horizontal berperingkat — satu deret, panjang batang membawa besaran.
//
// Pewarnaan, tiga pilihan — sengaja eksplisit supaya satu entitas tidak berganti warna
// saat muncul di dua panel berbeda:
//   flat  : satu warna untuk semua batang (deret tunggal; panjang batang sudah cukup)
//   indeks: indeks ramp ditetapkan pemanggil lewat `kunci` — dipakai agar satu cluster
//           memakai corak yang SAMA di panel "jumlah" maupun panel "nilai"
//   urutan: indeks ramp mengikuti urutan baris (kelompok ordinal seperti umur hutang),
//           bukan nilainya — sehingga tidak pernah ada dua baris berwarna kembar
import { computed } from 'vue';
import { SEQ, rp, maxAman, kosong, type Baris } from './viz';

const props = withDefaults(defineProps<{
    rows: Baris[];
    ramp?: readonly string[];
    flat?: string | null;
    /** indeks ramp per baris; bila kosong dipakai urutan baris */
    kunci?: number[] | null;
    /** true → label nilai berupa jumlah + satuan, bukan rupiah */
    hitung?: boolean;
    satuan?: string;
    talangKiri?: number;
    talangKanan?: number;
}>(), { ramp: () => SEQ, flat: null, kunci: null, hitung: false, satuan: '', talangKiri: 152, talangKanan: 112 });

const W = 560, BH = 22, GP = 8, PT = 6;
const H = computed(() => PT * 2 + props.rows.length * (BH + GP));
const IW = computed(() => W - props.talangKiri - props.talangKanan);
const puncak = computed(() => maxAman(props.rows.map((r) => r.v)));
const hampa = computed(() => kosong(props.rows.map((r) => r.v)));

function label(v: number): string {
    return props.hitung ? `${v.toLocaleString('id-ID')}${props.satuan ? ' ' + props.satuan : ''}` : rp(v);
}

const bar = computed(() => props.rows.map((r, i) => ({
    n: r.n,
    y: PT + i * (BH + GP),
    w: Math.max(3, IW.value * r.v / puncak.value),
    fill: props.flat ?? props.ramp[Math.min(5, 1 + (props.kunci ? (props.kunci[i] ?? i) : i))],
    teks: label(r.v),
})));
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <svg v-else :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
            <g v-for="b in bar" :key="b.n">
                <text :x="talangKiri - 10" :y="b.y + BH / 2 + 4" text-anchor="end" class="dlab">{{ b.n }}</text>
                <rect data-mark :x="talangKiri" :y="b.y" :width="b.w.toFixed(1)" :height="BH" rx="4" :fill="b.fill">
                    <title>{{ b.n }}: {{ b.teks }}</title>
                </rect>
                <text :x="(talangKiri + b.w + 8).toFixed(1)" :y="b.y + BH / 2 + 4" class="vlab">{{ b.teks }}</text>
            </g>
        </svg>
    </div>
</template>
