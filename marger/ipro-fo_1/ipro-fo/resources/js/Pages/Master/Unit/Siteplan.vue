<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps<{
    project: { id: number; code: string; name: string; meta: any };
    units: { data: any[] };
}>();

// Master unit ada di FO, jadi tautan kembalinya menyeberang aplikasi.
const page = usePage();
const foUrl: string = ((page.props as any).config?.fo_url ?? '');

const filter   = ref<string>('');
const hovered  = ref<any>(null);
const editMode = ref(false);

const canvasW = computed(() => props.project.meta?.canvas_w ?? 1200);
const canvasH = computed(() => props.project.meta?.canvas_h ?? 800);

const units = computed(() => props.units.data.filter(u => !filter.value || u.status === filter.value));

function colorFor(status: string) {
    return ({
        available: '#10b981', hold: '#14b8a6', booked: '#f59e0b',
        sold: '#7c3aed', cancelled: '#94a3b8', maintenance: '#94a3b8',
    } as Record<string, string>)[status] ?? '#94a3b8';
}

async function onDrop(unit: any, e: DragEvent) {
    if (!editMode.value) return;
    const rect = (e.currentTarget as SVGSVGElement).getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    await axios.put(`/projects/${props.project.id}/siteplan/${unit.id}`, {
        x, y, width: unit.siteplan?.width ?? 60, height: unit.siteplan?.height ?? 40,
    });
    unit.siteplan = { ...(unit.siteplan ?? {}), x, y };
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <!-- Master unit ada di FO, jadi tautan kembalinya menyeberang aplikasi. -->
                <a :href="foUrl + '/fo/units'" class="text-sm text-neutral-500 hover:text-primary-600">← Unit (FO)</a>
                <h1 class="text-2xl font-bold">Site Plan · {{ project.name }}</h1>
            </div>
            <div class="flex gap-2">
                <button @click="editMode = !editMode" class="px-3 py-1.5 rounded-lg border border-neutral-300 dark:border-neutral-700 text-sm">
                    {{ editMode ? '✕ Selesai Edit' : '✏ Edit Mode' }}
                </button>
            </div>
        </div>

        <div class="card p-4 flex flex-wrap gap-3 items-center">
            <span class="text-sm font-semibold">Legend:</span>
            <span v-for="s in ['available','hold','booked','sold']" :key="s" @click="filter = filter === s ? '' : s"
                class="flex items-center gap-1 text-xs cursor-pointer"
                :class="filter && filter !== s ? 'opacity-30' : ''">
                <span class="w-3 h-3 rounded-sm" :style="{ background: colorFor(s) }"></span>
                {{ s }}
            </span>
        </div>

        <div class="card p-4 overflow-auto">
            <svg :viewBox="`0 0 ${canvasW} ${canvasH}`" class="w-full bg-neutral-100 dark:bg-neutral-800 rounded">
                <g v-for="u in units" :key="u.id">
                    <rect
                        v-if="u.siteplan"
                        :x="u.siteplan.x" :y="u.siteplan.y"
                        :width="u.siteplan.width || 60" :height="u.siteplan.height || 40"
                        :fill="colorFor(u.status)"
                        stroke="#fff" stroke-width="1.5"
                        class="cursor-pointer transition-all"
                        :class="hovered?.id === u.id ? 'opacity-100' : 'opacity-90 hover:opacity-100'"
                        @mouseenter="hovered = u"
                        @mouseleave="hovered = null"
                    />
                    <text
                        v-if="u.siteplan"
                        :x="u.siteplan.x + (u.siteplan.width || 60) / 2"
                        :y="u.siteplan.y + (u.siteplan.height || 40) / 2 + 4"
                        text-anchor="middle"
                        class="text-[10px] font-bold fill-white pointer-events-none"
                    >
                        {{ u.unit_code }}
                    </text>
                </g>
            </svg>
        </div>

        <div v-if="hovered" class="fixed bottom-6 right-6 card p-4 shadow-2xl min-w-[280px] z-10" @mouseenter="hovered = hovered">
            <p class="font-mono font-bold">{{ hovered.unit_code }}</p>
            <p class="text-sm text-neutral-500">{{ hovered.unit_type?.name }} · {{ hovered.unit_type?.lt }}/{{ hovered.unit_type?.lb }} m²</p>
            <p class="text-sm mt-2 font-semibold">{{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(hovered.current_price) }}</p>
            <p class="text-xs mt-1">Status: <span class="font-semibold capitalize">{{ hovered.status }}</span></p>
            <Link v-if="hovered.status === 'available'"
                :href="`${route('bookings.create')}?unit_id=${hovered.id}`"
                class="mt-3 block text-center px-3 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 transition">
                + Buat Booking untuk Unit Ini
            </Link>
        </div>
    </div>
</template>
