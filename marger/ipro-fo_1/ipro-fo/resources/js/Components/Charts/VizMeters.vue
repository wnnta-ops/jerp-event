<script setup lang="ts">
// Meter realisasi terhadap plafon. Garis tegak di ujung lajur = plafon.
// Warna di sini adalah warna STATUS (aman / mendekati / lewat), bukan warna deret —
// karena itu selalu berpasangan dengan angka persen yang terbaca, tidak pernah warna saja.
import { computed } from 'vue';
import { rp } from './viz';

const props = defineProps<{ rows: Array<{ n: string; real: number; plafon: number }> }>();

const W = 560, P = { l: 150, r: 66 }, BH = 20, GP = 16, PT = 8;
// baris berplafon <= 0 dibuang di sini juga (selain di controller) supaya tidak ada pembagian nol
const sah = computed(() => props.rows.filter((r) => Number.isFinite(r.plafon) && r.plafon > 0));
const H = computed(() => PT * 2 + sah.value.length * (BH + GP));
const IW = W - P.l - P.r;

const meter = computed(() => sah.value.map((r, i) => {
    const rasio = r.real / r.plafon;
    return {
        n: r.n,
        y: PT + i * (BH + GP),
        w: Math.max(3, Math.min(1, rasio) * IW),
        persen: Math.round(rasio * 100),
        lewat: rasio > 1,
        fill: rasio > 1 ? 'var(--viz-crit)' : rasio > 0.9 ? 'var(--viz-warn)' : 'var(--viz-s3)',
        judul: `${r.n}: realisasi ${rp(r.real)} dari plafon ${rp(r.plafon)} (${Math.round(rasio * 100)}%)`,
    };
}));
</script>

<template>
    <div class="viz">
        <p v-if="!sah.length" class="py-10 text-center text-sm text-neutral-400">Belum ada anggaran berplafon.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
                <g v-for="m in meter" :key="m.n">
                    <text :x="P.l - 10" :y="m.y + BH / 2 + 4" text-anchor="end" class="dlab">{{ m.n }}</text>
                    <rect :x="P.l" :y="m.y" :width="IW" :height="BH" rx="4" fill="var(--viz-track)" />
                    <rect data-mark :x="P.l" :y="m.y" :width="m.w.toFixed(1)" :height="BH" rx="4" :fill="m.fill">
                        <title>{{ m.judul }}</title>
                    </rect>
                    <line :x1="P.l + IW" :x2="P.l + IW" :y1="m.y - 4" :y2="m.y + BH + 4" stroke="var(--viz-ink)" stroke-width="1.5" />
                    <text :x="P.l + IW + 9" :y="m.y + BH / 2 + 4" class="vlab" :fill="m.lewat ? 'var(--viz-crit)' : undefined">{{ m.persen }}%</text>
                </g>
            </svg>
            <div class="mt-2 flex flex-wrap gap-2.5 text-[11.5px] text-neutral-600 dark:text-neutral-300">
                <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" style="background:var(--viz-s3)"></i>Aman (&lt;90%)</span>
                <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" style="background:var(--viz-warn)"></i>Mendekati plafon</span>
                <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" style="background:var(--viz-crit)"></i>Lewat plafon</span>
                <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" style="background:var(--viz-ink)"></i>Garis plafon</span>
            </div>
        </template>
    </div>
</template>
