<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    points: number[];
    color?: string;
    height?: number;
    width?: number;
}>(), { color: '#ff8c00', height: 38, width: 120 });

const uid = Math.random().toString(36).slice(2, 8);

const coords = computed(() => {
    const pts = props.points.length ? props.points : [0, 0];
    const max = Math.max(...pts, 1);
    const min = Math.min(...pts, 0);
    const range = max - min || 1;
    const n = pts.length;
    const step = n > 1 ? props.width / (n - 1) : props.width;
    return pts.map((v, i) => {
        const x = i * step;
        const y = props.height - ((v - min) / range) * (props.height - 6) - 3;
        return { x, y };
    });
});

const line = computed(() => coords.value.map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' '));
const area = computed(() => `${line.value} L${props.width},${props.height} L0,${props.height} Z`);
const last = computed(() => coords.value[coords.value.length - 1]);
</script>

<template>
    <svg :viewBox="`0 0 ${width} ${height}`" :width="width" :height="height" preserveAspectRatio="none" class="block overflow-visible">
        <defs>
            <linearGradient :id="`sg-${uid}`" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="color" stop-opacity="0.34" />
                <stop offset="100%" :stop-color="color" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="area" :fill="`url(#sg-${uid})`" class="spark-area" />
        <path :d="line" fill="none" :stroke="color" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="spark-line" />
        <circle v-if="last" :cx="last.x" :cy="last.y" r="2.6" :fill="color" class="spark-dot" />
    </svg>
</template>

<style scoped>
.spark-line { stroke-dasharray: 400; stroke-dashoffset: 400; animation: draw 1.3s cubic-bezier(.4,0,.2,1) forwards; }
.spark-area { opacity: 0; animation: fade .9s ease .5s forwards; }
.spark-dot  { opacity: 0; animation: fade .4s ease 1.2s forwards; }
@keyframes draw { to { stroke-dashoffset: 0; } }
@keyframes fade { to { opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
    .spark-line { animation: none; stroke-dashoffset: 0; }
    .spark-area, .spark-dot { animation: none; opacity: 1; }
}
</style>
