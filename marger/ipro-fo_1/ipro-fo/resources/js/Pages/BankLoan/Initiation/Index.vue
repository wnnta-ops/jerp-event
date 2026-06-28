<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { Plus, GitMerge } from 'lucide-vue-next';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ records: any; filters: Record<string, any>; stages: Record<string, string> }>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const refresh = debounce(() => router.get(route('kpr.initiation.index'), {
    search: search.value || undefined, status: status.value || undefined,
}, { preserveState: true, replace: true }), 300);
watch([search, status], refresh);

const rows = computed<any[]>(() => props.records.data ?? []);
const stageNames = computed(() => Object.values(props.stages));
function statusVariant(s: string) { return s === 'completed' ? 'success' : (s === 'rejected' ? 'danger' : 'warning'); }
function statusLabel(s: string) { return s === 'completed' ? 'Selesai' : (s === 'rejected' ? 'Ditolak' : 'Berproses'); }
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><GitMerge class="h-6 w-6 text-primary-600" /> Inisiasi KPR</h1>
                <p class="text-sm text-neutral-500 mt-0.5">Pra-kualifikasi 7 tahap: TTS → SPRS → BI Checking → Collect Berkas → Analisa → Profiling → Proses KPR</p>
            </div>
            <Link :href="route('kpr.initiation.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Mulai Inisiasi</Button></Link>
        </div>

        <div class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
            <Input v-model="search" label="Cari No. Inisiasi / Customer / TTS" />
            <div>
                <label class="label-base">Status</label>
                <select v-model="status" class="input-base">
                    <option value="">Semua</option>
                    <option value="in_progress">Berproses</option>
                    <option value="completed">Selesai</option>
                    <option value="rejected">Ditolak</option>
                </select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No. Inisiasi</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">TTS (sumber)</th>
                        <th class="px-4 py-3">Tahap</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Draft KPR</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono text-xs">{{ r.number ?? '—' }}</td>
                        <td class="px-4 py-3">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ r.kuitansi ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium tabular-nums">{{ Math.min(r.current_stage, 7) }}/7</span>
                                <span class="text-xs text-neutral-500">{{ r.stage_label }}</span>
                            </div>
                            <div class="mt-1 h-1.5 w-28 rounded-full bg-neutral-100 dark:bg-neutral-800 overflow-hidden">
                                <div class="h-full rounded-full bg-primary-500" :style="{ width: (Math.min(r.current_stage, 7) / 7 * 100) + '%' }"></div>
                            </div>
                        </td>
                        <td class="px-4 py-3"><Badge :variant="statusVariant(r.status)" dot>{{ statusLabel(r.status) }}</Badge></td>
                        <td class="px-4 py-3">
                            <Link v-if="r.kpr_id" :href="route('kpr.show', r.kpr_id)" class="font-mono text-xs text-primary-600 hover:underline">{{ r.kpr_number }}</Link>
                            <span v-else class="text-neutral-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('kpr.initiation.show', r.id)" class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">Buka →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada inisiasi KPR" description="Klik 'Mulai Inisiasi' untuk memulai dari sebuah TTS." />
        </div>
    </div>
</template>
