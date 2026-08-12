<script setup lang="ts">
// Ringkasan nilai setahun — satu batang per entitas, plus baris total.
// Warna mengikuti ENTITAS (indeks slot dari controller), sama dengan warna entitas itu
// pada grafik bulanan di sebelahnya, supaya mata bisa berpindah panel tanpa kehilangan jejak.
import { computed } from 'vue';
import { S, md, maxAman, kosong } from './viz';

const props = defineProps<{ rows: Array<{ n: string; c: number; v: number }> }>();

const W = 330, P = { l: 12, r: 12, t: 4 }, BH = 20, ROW = 46;
const H = computed(() => P.t + props.rows.length * ROW + 30);
const IW = W - P.l - P.r;
const puncak = computed(() => maxAman(props.rows.map((r) => r.v)));
const hampa = computed(() => kosong(props.rows.map((r) => r.v)));
const total = computed(() => props.rows.reduce((a, r) => a + (Number.isFinite(r.v) ? r.v : 0), 0));
const yTotal = computed(() => P.t + props.rows.length * ROW + 4);

const bar = computed(() => props.rows.map((r, i) => ({
    n: r.n,
    y: P.t + i * ROW,
    w: Math.max(4, IW * r.v / puncak.value),
    fill: S[r.c],
    teks: md(r.v),
})));
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada nilai tercatat.</p>
        <svg v-else :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
            <g v-for="b in bar" :key="b.n">
                <text :x="P.l" :y="b.y + 11" class="dlab">{{ b.n }}</text>
                <text :x="W - P.r" :y="b.y + 11" text-anchor="end" class="vlab">{{ b.teks }}</text>
                <rect :x="P.l" :y="b.y + 18" :width="IW" :height="BH" rx="4" fill="var(--viz-track)" />
                <rect data-mark :x="P.l" :y="b.y + 18" :width="b.w.toFixed(1)" :height="BH" rx="4" :fill="b.fill">
                    <title>{{ b.n }} (setahun): {{ b.teks }}</title>
                </rect>
            </g>
            <line :x1="P.l" :x2="W - P.r" :y1="yTotal" :y2="yTotal" class="ax" />
            <text :x="P.l" :y="yTotal + 18" class="dlab">Total tercatat</text>
            <text :x="W - P.r" :y="yTotal + 18" text-anchor="end" class="vlab">{{ md(total) }}</text>
        </svg>
    </div>
</template>
