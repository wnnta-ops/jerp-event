<script setup lang="ts">
// Corong tahapan — batang horizontal menurun, satu baris per tahap.
//
// Dua cara pewarnaan:
//   warna : satu rona berbeda per tahap (dipakai kedua corong KPR)
//   ramp  : satu rona bergradasi, diambil mulai indeks 1 karena langkah paling pucat
//           tidak lolos ambang kontras untuk tanda berurut
//
// Saat `warna` dipakai, rona TIDAK lagi membawa urutan tahap. Karena itu nomor tahap
// dicetak di label kiri — urutan dipikul nomor, posisi, dan panjang batang.
import { computed } from 'vue';
import { SEQ, maxAman, kosong, type Baris } from './viz';

const props = withDefaults(defineProps<{
    rows: Baris[];
    satuan?: string;
    warna?: readonly string[] | null;
    ramp?: readonly string[];
}>(), { satuan: 'berkas', warna: null, ramp: () => SEQ });

const W = 560, BH = 24, GP = 8, PT = 6;
const PL = computed(() => (props.warna ? 164 : 150));   // talang kiri melebar bila nomor ikut dicetak
const PR = 92;
const H = computed(() => PT * 2 + props.rows.length * (BH + GP));
const IW = computed(() => W - PL.value - PR);
const puncak = computed(() => maxAman(props.rows.map((r) => r.v)));
const hampa = computed(() => kosong(props.rows.map((r) => r.v)));

const bar = computed(() => props.rows.map((r, i) => ({
    n: r.n,
    v: r.v,
    y: PT + i * (BH + GP),
    w: Math.max(3, IW.value * r.v / puncak.value),
    no: i + 1,
    fill: props.warna ? props.warna[i % props.warna.length] : props.ramp[Math.min(5, 1 + i)],
    persen: puncak.value > 0 ? Math.round(r.v / puncak.value * 100) : 0,
})));
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">
            Belum ada data.
        </p>
        <svg v-else :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
            <g v-for="b in bar" :key="b.n">
                <text :x="PL - 10" :y="b.y + BH / 2 + 4" text-anchor="end" class="dlab">
                    <tspan v-if="warna" class="no">{{ b.no }}</tspan><tspan v-if="warna">&#160;&#160;</tspan>{{ b.n }}
                </text>
                <rect data-mark :x="PL" :y="b.y" :width="b.w.toFixed(1)" :height="BH" rx="4" :fill="b.fill">
                    <title>{{ b.n }}: {{ b.v.toLocaleString('id-ID') }} {{ satuan }}</title>
                </rect>
                <text :x="(PL + b.w + 8).toFixed(1)" :y="b.y + BH / 2 + 4" class="vlab">
                    {{ b.v.toLocaleString('id-ID') }}<tspan v-if="b.no > 1" class="pct"> ({{ b.persen }}%)</tspan>
                </text>
            </g>
        </svg>
    </div>
</template>
