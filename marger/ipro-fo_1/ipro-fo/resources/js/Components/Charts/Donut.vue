<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

interface Item { label: string; value: number; color: string }

const props = withDefaults(defineProps<{
    items: Item[];
    centerLabel?: string;
    size?: number;
    thickness?: number;
}>(), { size: 180, thickness: 22 });

const R = computed(() => (props.size - props.thickness) / 2);
const C = computed(() => 2 * Math.PI * R.value);
const center = computed(() => props.size / 2);

const total = computed(() => props.items.reduce((a, b) => a + b.value, 0));
const mounted = ref(false);
const hover = ref<number | null>(null);

const segments = computed(() => {
    let acc = 0;
    const t = total.value || 1;
    return props.items.map((it) => {
        const frac = it.value / t;
        const seg = { ...it, frac, offset: acc, pct: Math.round(frac * 100) };
        acc += frac;
        return seg;
    });
});

const centerValue = computed(() => {
    if (hover.value !== null && props.items[hover.value]) return String(props.items[hover.value].value);
    return String(total.value);
});
const centerSub = computed(() => {
    if (hover.value !== null && props.items[hover.value]) return props.items[hover.value].label;
    return props.centerLabel ?? 'Total';
});

onMounted(() => requestAnimationFrame(() => requestAnimationFrame(() => { mounted.value = true; })));
</script>

<template>
    <div v-if="total > 0" class="flex flex-col sm:flex-row items-center gap-5">
        <div class="relative shrink-0" :style="{ width: size + 'px', height: size + 'px' }">
            <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="-rotate-90">
                <circle :cx="center" :cy="center" :r="R" fill="none" stroke="currentColor"
                        class="text-neutral-100 dark:text-neutral-800" :stroke-width="thickness" />
                <circle
                    v-for="(s, i) in segments"
                    :key="i"
                    :cx="center" :cy="center" :r="R"
                    fill="none"
                    :stroke="s.color"
                    :stroke-width="hover === i ? thickness + 4 : thickness"
                    stroke-linecap="round"
                    :stroke-dasharray="`${(mounted ? s.frac : 0) * C} ${C}`"
                    :stroke-dashoffset="-s.offset * C"
                    class="seg"
                    @mouseenter="hover = i"
                    @mouseleave="hover = null"
                />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <span class="text-2xl font-extrabold text-neutral-800 dark:text-neutral-100 tabular-nums">{{ centerValue }}</span>
                <span class="text-[11px] text-neutral-500 mt-0.5 max-w-[80%] truncate">{{ centerSub }}</span>
            </div>
        </div>

        <ul class="flex-1 w-full space-y-1.5">
            <li
                v-for="(s, i) in segments"
                :key="i"
                class="flex items-center justify-between gap-2 text-sm rounded-md px-2 py-1 cursor-default transition"
                :class="hover === i ? 'bg-neutral-100 dark:bg-neutral-800' : ''"
                @mouseenter="hover = i"
                @mouseleave="hover = null"
            >
                <span class="flex items-center gap-2 min-w-0">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: s.color }"></span>
                    <span class="truncate text-neutral-600 dark:text-neutral-300">{{ s.label }}</span>
                </span>
                <span class="flex items-center gap-2 shrink-0">
                    <span class="font-semibold tabular-nums">{{ s.value }}</span>
                    <span class="text-xs text-neutral-400 tabular-nums w-9 text-right">{{ s.pct }}%</span>
                </span>
            </li>
        </ul>
    </div>
    <div v-else class="flex items-center justify-center h-40 text-sm text-neutral-400">Belum ada data</div>
</template>

<style scoped>
.seg { transition: stroke-dasharray 1.1s cubic-bezier(.4,0,.2,1), stroke-width .15s ease; cursor: pointer; }
@media (prefers-reduced-motion: reduce) { .seg { transition: stroke-width .15s ease; } }
</style>
