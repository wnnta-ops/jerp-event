<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, Percent, Calculator, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps<{ projects: Array<{ id: number; name: string }> }>();

const projectId = ref<number | null>(props.projects[0]?.id ?? null);
const oldRate = ref(11);
const newRate = ref(12);
const state = reactive<{ loading: boolean; error: string; rows: any[] }>({ loading: false, error: '', rows: [] });
const selected = ref<Set<number>>(new Set());

async function preview() {
    if (!projectId.value) return;
    state.loading = true; state.error = ''; state.rows = []; selected.value = new Set();
    try {
        const { data } = await axios.post(route('sales.kontrak-ops.ppn.preview'), { project_id: projectId.value, old_rate: oldRate.value, new_rate: newRate.value });
        state.rows = data.rows;
        state.rows.forEach((r: any) => selected.value.add(r.contract_id));
    } catch (e: any) {
        state.error = e?.response?.data?.message ?? 'Gagal menghitung.';
    } finally {
        state.loading = false;
    }
}

function toggle(id: number) { selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); selected.value = new Set(selected.value); }
function toggleAll() {
    if (selected.value.size === state.rows.length) selected.value = new Set();
    else selected.value = new Set(state.rows.map((r: any) => r.contract_id));
}
const totalKenaikan = computed(() => state.rows.filter((r: any) => selected.value.has(r.contract_id)).reduce((s: number, r: any) => s + r.kenaikan_ppn, 0));

const applyForm = useForm({ contract_ids: [] as number[], old_rate: 0, new_rate: 0 });
function apply() {
    if (!selected.value.size) return;
    if (!confirm(`Terapkan tarif PPN ${newRate.value}% ke ${selected.value.size} kontrak terpilih?`)) return;
    applyForm.contract_ids = Array.from(selected.value);
    applyForm.old_rate = oldRate.value;
    applyForm.new_rate = newRate.value;
    applyForm.post(route('sales.kontrak-ops.ppn.apply'));
}

const rb = (n: number) => Math.round(n || 0).toLocaleString('id-ID');
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('sales.kontrak-ops.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Operasi Kontrak</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Percent class="h-6 w-6 text-accent-600" /> Adjustment Kontrak PPN</h1>
        <p class="text-sm text-neutral-500">Transisi tarif PPN: hitung ulang sisa tagihan seluruh kontrak aktif &amp; terapkan tarif baru.</p>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label :class="labelCls">Project</label><select v-model="projectId" :class="inputCls"><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
            <div><label :class="labelCls">PPN Lama (%)</label><input type="number" step="0.01" v-model.number="oldRate" :class="inputCls + ' w-28'" /></div>
            <div><label :class="labelCls">PPN Baru (%)</label><input type="number" step="0.01" v-model.number="newRate" :class="inputCls + ' w-28'" /></div>
            <button type="button" @click="preview" :disabled="state.loading" class="inline-flex items-center gap-2 rounded-lg bg-neutral-800 px-4 py-2 text-sm font-semibold text-white hover:bg-neutral-900 disabled:opacity-50 dark:bg-neutral-700"><Calculator class="h-4 w-4" /> {{ state.loading ? 'Menghitung…' : 'Hitung' }}</button>
        </div>
        <p v-if="state.error" class="text-xs text-danger-600">{{ state.error }}</p>

        <div v-if="state.rows.length" class="card overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-neutral-50 uppercase text-neutral-500 dark:bg-neutral-800">
                    <tr>
                        <th class="px-2 py-2"><input type="checkbox" :checked="selected.size === state.rows.length" @change="toggleAll" /></th>
                        <th class="px-2 py-2 text-left">No. Kontrak</th><th class="px-2 py-2 text-left">Unit</th><th class="px-2 py-2 text-left">Customer</th>
                        <th class="px-2 py-2 text-right">DPP (A)</th><th class="px-2 py-2 text-right">PPN Lama (B)</th><th class="px-2 py-2 text-right">Nilai Kontrak (C)</th>
                        <th class="px-2 py-2 text-right">Terbayar (F)</th><th class="px-2 py-2 text-right">Sisa (G)</th>
                        <th class="px-2 py-2 text-right">DPP Sisa (H)</th><th class="px-2 py-2 text-right">PPN Baru (I)</th><th class="px-2 py-2 text-right">Sisa Baru (J)</th>
                        <th class="px-2 py-2 text-right">Nilai Kontrak Baru (K)</th><th class="px-2 py-2 text-right">Kenaikan PPN (N)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in state.rows" :key="r.contract_id" :class="selected.has(r.contract_id) ? 'bg-accent-50/40 dark:bg-accent-900/10' : ''">
                        <td class="px-2 py-1.5 text-center"><input type="checkbox" :checked="selected.has(r.contract_id)" @change="toggle(r.contract_id)" /></td>
                        <td class="px-2 py-1.5 font-mono">{{ r.contract_number }}</td>
                        <td class="px-2 py-1.5">{{ r.unit ?? '—' }}</td>
                        <td class="px-2 py-1.5 truncate max-w-[10rem]">{{ r.customer ?? '—' }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.dpp) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.ppn_old) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.nilai_kontrak) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums text-success-600">{{ rb(r.paid) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.sisa) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.dpp_sisa) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.ppn_new) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ rb(r.sisa_new) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums font-medium">{{ rb(r.nilai_kontrak_new) }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums text-danger-600">{{ rb(r.kenaikan_ppn) }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="text-sm">{{ selected.size }} kontrak dipilih · Total kenaikan PPN <b class="text-danger-600">Rp {{ rb(totalKenaikan) }}</b></div>
                <button type="button" @click="apply" :disabled="!selected.size || applyForm.processing" class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-5 py-2 text-sm font-semibold text-white hover:bg-accent-700 disabled:opacity-50"><CheckCircle2 class="h-4 w-4" /> Terapkan PPN {{ newRate }}%</button>
            </div>
        </div>
        <div v-else-if="!state.loading && !state.error" class="card p-8 text-center text-sm text-neutral-400">Pilih project &amp; tarif, lalu klik “Hitung” untuk simulasi transisi PPN.</div>
    </div>
</template>
