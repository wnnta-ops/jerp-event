<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{
    labels: string[];
    values: number[];
    color?: string;
    money?: boolean;
    height?: number;
}>(), { color: '#ff8c00', money: false, height: 240 });

const uid = Math.random().toString(36).slice(2, 8);
const PAD_T = 14;
const PAD_B = 14;
const hover = ref<number | null>(null);

const max = computed(() => Math.max(...props.values, 1));
const min = computed(() => Math.min(...props.values, 0));
const range = computed(() => max.value - min.value || 1);
const n = computed(() => props.values.length);

function xPct(i: number) { return n.value > 1 ? (i / (n.value - 1)) * 100 : 50; }
function yPct(v: number) { return PAD_T + (1 - (v - min.value) / range.value) * (100 - PAD_T - PAD_B); }

const linePath = computed(() => props.values.map((v, i) => `${i === 0 ? 'M' : 'L'}${xPct(i).toFixed(2)},${yPct(v).toFixed(2)}`).join(' '));
const areaPath = computed(() => `${linePath.value} L100,100 L0,100 Z`);

const points = computed(() => props.values.map((v, i) => ({ x: xPct(i), y: yPct(v), v, label: props.labels[i] ?? '' })));

function fmt(v: number) {
    if (props.money) {
        if (v >= 1e9) return 'Rp ' + (v / 1e9).toFixed(1).replace('.', ',') + ' M';
        if (v >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1).replace('.', ',') + ' Jt';
        if (v >= 1e3) return 'Rp ' + Math.round(v / 1e3) + ' Rb';
        return 'Rp ' + v.toLocaleString('id-ID');
    }
    return v.toLocaleString('id-ID');
}

function onMove(e: MouseEvent) {
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const ratio = (e.clientX - rect.left) / rect.width;
    hover.value = Math.max(0, Math.min(n.value - 1, Math.round(ratio * (n.value - 1))));
}
</script>

<template>
    <div class="relative w-full select-none" :style="{ height: height + 'px' }"
         @mousemove="onMove" @mouseleave="hover = null">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
            <defs>
                <linearGradient :id="`al-${uid}`" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" :stop-color="color" stop-opacity="0.38" />
                    <stop offset="100%" :stop-color="color" stop-opacity="0.02" />
                </linearGradient>
            </defs>

            <!-- gridlines -->
            <line v-for="g in [25, 50, 75]" :key="g" x1="0" :y1="g" x2="100" :y2="g"
                  stroke="currentColor" class="text-neutral-200 dark:text-neutral-800"
                  stroke-width="0.6" vector-effect="non-scaling-stroke" stroke-dasharray="2 3" />

            <path :d="areaPath" :fill="`url(#al-${uid})`" class="al-area" />
            <path :d="linePath" fill="none" :stroke="color" stroke-width="2.4"
                  stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="al-line" />

            <!-- crosshair -->
            <line v-if="hover !== null" :x1="points[hover].x" y1="0" :x2="points[hover].x" y2="100"
                  :stroke="color" stroke-width="1" vector-effect="non-scaling-stroke" stroke-opacity="0.4" />
        </svg>

        <!-- dots -->
        <span v-for="(p, i) in points" :key="i"
              class="absolute w-2 h-2 rounded-full border-2 border-white dark:border-neutral-900 transition-transform"
              :style="{ left: p.x + '%', top: p.y + '%', background: color, transform: `translate(-50%,-50%) scale(${hover === i ? 1.8 : 1})` }"></span>

        <!-- x labels -->
        <div class="absolute inset-x-0 bottom-0 flex justify-between text-[11px] text-neutral-400 px-0.5">
            <span v-for="(l, i) in labels" :key="i">{{ l }}</span>
        </div>

        <!-- tooltip -->
        <div v-if="hover !== null"
             class="absolute z-10 -translate-x-1/2 -translate-y-full px-2.5 py-1.5 rounded-lg bg-neutral-900 text-white text-xs shadow-xl whitespace-nowrap pointer-events-none"
             :style="{ left: Math.min(92, Math.max(8, points[hover].x)) + '%', top: (points[hover].y - 4) + '%' }">
            <div class="text-neutral-300 text-[10px]">{{ points[hover].label }}</div>
            <div class="font-bold">{{ fmt(points[hover].v) }}</div>
        </div>
    </div>
</template>

<style scoped>
.al-line { stroke-dasharray: 300; stroke-dashoffset: 300; animation: draw 1.5s cubic-bezier(.4,0,.2,1) forwards; }
.al-area { opacity: 0; animation: fade 1s ease .6s forwards; }
@keyframes draw { to { stroke-dashoffset: 0; } }
@keyframes fade { to { opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
    .al-line { animation: none; stroke-dashoffset: 0; }
    .al-area { animation: none; opacity: 1; }
}
</style>
