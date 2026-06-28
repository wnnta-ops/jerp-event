<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

interface Item { label: string; value: number; color?: string }

const props = withDefaults(defineProps<{
    items: Item[];
    height?: number;
    money?: boolean;
}>(), { height: 200, money: false });

const palette = ['#ff8c00', '#10b981', '#f59e0b', '#14b8a6', '#7c3aed', '#f43f5e', '#84cc16', '#64748b'];
const max = computed(() => Math.max(...props.items.map((i) => i.value), 1));
const mounted = ref(false);
const hover = ref<number | null>(null);

function color(it: Item, i: number) { return it.color ?? palette[i % palette.length]; }
function fmt(v: number) {
    if (props.money) {
        if (v >= 1e9) return 'Rp ' + (v / 1e9).toFixed(1) + ' M';
        if (v >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1) + ' Jt';
        return 'Rp ' + v.toLocaleString('id-ID');
    }
    return v.toLocaleString('id-ID');
}

onMounted(() => requestAnimationFrame(() => requestAnimationFrame(() => { mounted.value = true; })));
</script>

<template>
    <div v-if="items.length" class="w-full">
        <div class="flex items-end gap-2 sm:gap-3" :style="{ height: height + 'px' }">
            <div
                v-for="(it, i) in items"
                :key="i"
                class="flex-1 h-full flex flex-col items-center justify-end group relative"
                @mouseenter="hover = i" @mouseleave="hover = null"
            >
                <!-- tooltip -->
                <div
                    class="absolute -top-1 z-10 px-2 py-1 rounded-md bg-neutral-900 text-white text-xs font-medium whitespace-nowrap shadow-lg transition-opacity pointer-events-none"
                    :class="hover === i ? 'opacity-100' : 'opacity-0'"
                    :style="{ bottom: 'calc(' + (mounted ? (it.value / max) * 100 : 0) + '% + 8px)', top: 'auto' }"
                >
                    {{ it.label }}: <strong>{{ fmt(it.value) }}</strong>
                </div>

                <span class="text-[11px] font-semibold text-neutral-500 mb-1 tabular-nums transition-opacity"
                      :class="hover === i ? 'opacity-100' : 'opacity-70'">
                    {{ money ? '' : it.value }}
                </span>
                <div
                    class="w-full max-w-[46px] rounded-t-md transition-all duration-1000 ease-out"
                    :style="{
                        height: (mounted ? (it.value / max) * 100 : 0) + '%',
                        background: `linear-gradient(180deg, ${color(it, i)}, ${color(it, i)}cc)`,
                        boxShadow: hover === i ? `0 0 0 2px ${color(it, i)}55` : 'none',
                        minHeight: it.value > 0 ? '4px' : '0',
                    }"
                ></div>
            </div>
        </div>
        <div class="flex gap-2 sm:gap-3 mt-2">
            <div v-for="(it, i) in items" :key="i" class="flex-1 text-center text-[10px] sm:text-xs text-neutral-500 leading-tight truncate">
                {{ it.label }}
            </div>
        </div>
    </div>
    <div v-else class="flex items-center justify-center text-sm text-neutral-400" :style="{ height: height + 'px' }">Belum ada data</div>
</template>
