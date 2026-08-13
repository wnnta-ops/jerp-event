<script setup lang="ts">
// Ringkasan nilai setahun — satu batang per entitas, plus baris total.
// Warna mengikuti ENTITAS (indeks slot dari controller), sama dengan warna entitas itu
// pada grafik bulanan di sebelahnya, supaya mata bisa berpindah panel tanpa kehilangan jejak.
import { computed } from 'vue';
import { S, md, maxAman, kosong } from './viz';

// total: tampilkan baris penjumlahan di bawah batang.
//
// MATIKAN untuk deret berupa TAHAPAN (corong). BAST → AJB → Booked Revenue adalah tiga
// tahap atas unit yang SAMA, jadi menjumlahkannya menghitung uang yang sama sampai tiga
// kali — 2 unit senilai Rp 800 juta akan tampil sebagai "Total tercatat Rp 2,4 M".
// Penjumlahan hanya sah bila deretnya benar-benar terpisah satu sama lain.
const props = withDefaults(defineProps<{
    rows: Array<{ n: string; c: number; v: number }>;
    total?: boolean;
}>(), { total: true });

const W = 330, P = { l: 12, r: 12, t: 4 }, BH = 20, ROW = 46;
const H = computed(() => P.t + props.rows.length * ROW + (props.total ? 30 : 4));
const IW = W - P.l - P.r;
const puncak = computed(() => maxAman(props.rows.map((r) => r.v)));
const hampa = computed(() => kosong(props.rows.map((r) => r.v)));
// dinamai `jumlah`, bukan `total`, karena `total` kini nama prop sakelarnya
const jumlah = computed(() => props.rows.reduce((a, r) => a + (Number.isFinite(r.v) ? r.v : 0), 0));
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
            <template v-if="total">
                <line :x1="P.l" :x2="W - P.r" :y1="yTotal" :y2="yTotal" class="ax" />
                <text :x="P.l" :y="yTotal + 18" class="dlab">Total tercatat</text>
                <text :x="W - P.r" :y="yTotal + 18" text-anchor="end" class="vlab">{{ md(jumlah) }}</text>
            </template>
        </svg>
    </div>
</template>
