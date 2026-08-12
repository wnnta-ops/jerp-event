<script setup lang="ts">
// Kolom berkelompok — beberapa deret bersebelahan pada tiap label bulan.
// Satu sumbu saja. Dua ukuran berbeda skala TIDAK PERNAH digabung di sini;
// itu kesalahan grafik yang paling sering menyesatkan pembaca.
import { computed } from 'vue';
import { S, jt, rp, maxAman, kosong, type Seri } from './viz';

const props = withDefaults(defineProps<{
    labels: string[];
    series: Seri[];
    /** true → sumbu memakai angka bulat (jumlah unit/berkas), false → jutaan rupiah */
    unitAxis?: boolean;
    satuan?: string;
    tinggi?: number;
}>(), { unitAxis: false, satuan: '', tinggi: 210 });

const W = 560, P = { l: 56, r: 12, t: 12, b: 26 };
const IW = W - P.l - P.r;
const IH = computed(() => props.tinggi - P.t - P.b);
const semua = computed(() => props.series.flatMap((s) => s.v));
const hampa = computed(() => kosong(semua.value));
const puncak = computed(() => maxAman(semua.value) * 1.15);

const garis = computed(() => [0, 1, 2, 3, 4].map((i) => {
    const y = P.t + IH.value * i / 4;
    const v = puncak.value * (1 - i / 4);
    return { y, teks: props.unitAxis ? String(Math.round(v)) : jt(v) };
}));

const bw = computed(() => IW / Math.max(1, props.labels.length));
const lebar = computed(() => Math.min(18, (bw.value - 10) / Math.max(1, props.series.length) - 2));

const batang = computed(() => props.labels.flatMap((l, i) => props.series.map((s, j) => {
    const v = s.v[i] ?? 0;
    const bh = Math.max(2, IH.value * v / puncak.value);
    const n = props.series.length;
    return {
        key: `${i}-${j}`,
        x: P.l + bw.value * i + bw.value / 2 - (n * (lebar.value + 2) - 2) / 2 + j * (lebar.value + 2),
        y: P.t + IH.value - bh,
        h: bh,
        fill: S[s.c],
        judul: `${l} · ${s.n}: ${props.unitAxis ? v.toLocaleString('id-ID') + (props.satuan ? ' ' + props.satuan : '') : rp(v)}`,
    };
})));

const sumbuX = computed(() => props.labels.map((l, i) => ({ l, x: P.l + bw.value * i + bw.value / 2 })));
</script>

<template>
    <div class="viz">
        <p v-if="hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${tinggi}`" role="img" preserveAspectRatio="xMidYMid meet">
                <g v-for="(g, i) in garis" :key="'g' + i">
                    <line :x1="P.l" :x2="W - P.r" :y1="g.y" :y2="g.y" class="gl" />
                    <text :x="P.l - 8" :y="g.y + 3" text-anchor="end" class="tick">{{ g.teks }}</text>
                </g>
                <text v-for="(t, i) in sumbuX" :key="'x' + i" :x="t.x" :y="tinggi - 8" text-anchor="middle" class="tick">{{ t.l }}</text>
                <rect v-for="b in batang" :key="b.key" data-mark
                      :x="b.x.toFixed(1)" :y="b.y.toFixed(1)" :width="lebar.toFixed(1)" :height="b.h.toFixed(1)"
                      rx="3" :fill="b.fill"><title>{{ b.judul }}</title></rect>
                <line :x1="P.l" :x2="W - P.r" :y1="P.t + IH" :y2="P.t + IH" class="ax" />
            </svg>
            <!-- deret ≥2 selalu berlegenda: identitas tidak boleh bergantung pada warna saja -->
            <div v-if="series.length > 1" class="mt-2 flex flex-wrap gap-2.5 text-[11.5px] text-neutral-600 dark:text-neutral-300">
                <span v-for="(s, i) in series" :key="i" class="inline-flex items-center gap-1.5">
                    <i class="inline-block h-2.5 w-2.5 rounded-[3px]" :style="{ background: S[s.c] }"></i>{{ s.n }}
                </span>
            </div>
        </template>
    </div>
</template>
