<script setup lang="ts">
// Kolom divergen — laba di atas garis nol, rugi di bawahnya.
// Dua kutub hangat/sejuk dengan titik tengah netral (garis nol), bukan rona di tengah.
import { computed } from 'vue';
import { S, jt, rp, kosong } from './viz';

const props = withDefaults(defineProps<{
    labels: string[];
    vals: number[];
    tinggi?: number;
}>(), { tinggi: 214 });

const W = 560, P = { l: 58, r: 12, t: 12, b: 26 };
const IW = W - P.l - P.r;
const IH = computed(() => props.tinggi - P.t - P.b);
const hampa = computed(() => kosong(props.vals));
// skala simetris: satu langkah rupiah harus setinggi sama di sisi laba maupun rugi
const rentang = computed(() => Math.max(...props.vals.map((v) => Math.abs(v)), 0) * 1.2 || 1);
const nol = computed(() => P.t + IH.value / 2);

const garis = computed(() => [1, .5, 0, -.5, -1].map((f) => ({
    f, y: nol.value - IH.value / 2 * f, teks: jt(rentang.value * f),
})));

const bw = computed(() => IW / Math.max(1, props.labels.length));
const lebar = computed(() => Math.min(20, bw.value - 12));

const batang = computed(() => props.labels.map((l, i) => {
    const v = props.vals[i] ?? 0;
    const bh = Math.abs(v) / rentang.value * (IH.value / 2);
    return {
        l, v,
        x: P.l + bw.value * i + bw.value / 2 - lebar.value / 2,
        y: v >= 0 ? nol.value - bh : nol.value,
        h: Math.max(2, bh),
        cx: P.l + bw.value * i + bw.value / 2,
        fill: v >= 0 ? S[0] : S[7],
        judul: `${l}: ${rp(v)}`,
    };
}));
</script>

<template>
    <div class="viz">
        <p v-if="hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${tinggi}`" role="img" preserveAspectRatio="xMidYMid meet">
                <g v-for="(g, i) in garis" :key="'g' + i">
                    <line :x1="P.l" :x2="W - P.r" :y1="g.y" :y2="g.y" :class="g.f === 0 ? 'ax' : 'gl'" />
                    <text :x="P.l - 8" :y="g.y + 3" text-anchor="end" class="tick">{{ g.teks }}</text>
                </g>
                <g v-for="(b, i) in batang" :key="i">
                    <rect data-mark :x="b.x.toFixed(1)" :y="b.y.toFixed(1)" :width="lebar.toFixed(1)"
                          :height="b.h.toFixed(1)" rx="3" :fill="b.fill"><title>{{ b.judul }}</title></rect>
                    <text :x="b.cx" :y="tinggi - 8" text-anchor="middle" class="tick">{{ b.l }}</text>
                </g>
            </svg>
            <div class="mt-2 flex flex-wrap gap-2.5 text-[11.5px] text-neutral-600 dark:text-neutral-300">
                <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" :style="{ background: S[0] }"></i>Laba</span>
                <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" :style="{ background: S[7] }"></i>Rugi</span>
            </div>
        </template>
    </div>
</template>
