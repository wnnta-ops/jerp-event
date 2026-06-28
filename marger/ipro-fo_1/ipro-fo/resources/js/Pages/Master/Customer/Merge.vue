<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, Users, GitMerge, TriangleAlert } from 'lucide-vue-next';

const props = defineProps<{ customers: Array<{ id: number; code: string; full_name: string; id_number: string | null }> }>();

const form = useForm({ source_id: null as number | null, target_id: null as number | null });
const refs = reactive<{ loading: boolean; data: Record<string, number> | null }>({ loading: false, data: null });

const label = (id: number | null) => {
    const c = props.customers.find(x => x.id === id);
    return c ? `${c.code} — ${c.full_name}` : '';
};
const totalRefs = computed(() => (refs.data ? Object.values(refs.data).reduce((a, b) => a + b, 0) : 0));

async function loadRefs() {
    refs.data = null;
    if (!form.source_id) return;
    refs.loading = true;
    try {
        const { data } = await axios.get(route('customers.merge.references'), { params: { customer_id: form.source_id } });
        refs.data = data.references;
    } finally {
        refs.loading = false;
    }
}

function submit() {
    if (!form.source_id || !form.target_id) return;
    if (form.source_id === form.target_id) { alert('Customer A dan B tidak boleh sama.'); return; }
    if (!confirm(`Gabungkan ${label(form.source_id)} → ${label(form.target_id)}?\n${totalRefs.value} referensi akan dipindahkan dan Customer A dinonaktifkan.`)) return;
    form.post(route('customers.merge.apply'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('customers.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Customer</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><GitMerge class="h-6 w-6 text-primary-600" /> Gabung Customer</h1>
        <p class="text-sm text-neutral-500">Memindahkan seluruh kontrak/booking dari <b>Customer A</b> ke <b>Customer B</b>, lalu menonaktifkan Customer A.</p>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="card p-4">
                <label :class="labelCls">Customer A (sumber — akan dinonaktifkan)</label>
                <select v-model.number="form.source_id" @change="loadRefs" :class="inputCls">
                    <option :value="null">— Pilih Customer A —</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.full_name }}{{ c.id_number ? ` (${c.id_number})` : '' }}</option>
                </select>
                <div v-if="refs.loading" class="mt-3 text-xs text-neutral-400">Menghitung referensi…</div>
                <div v-else-if="refs.data" class="mt-3">
                    <div class="text-xs font-semibold text-neutral-500">Referensi yang akan dipindahkan ({{ totalRefs }}):</div>
                    <ul v-if="totalRefs" class="mt-1 space-y-0.5 text-xs">
                        <li v-for="(n, t) in refs.data" :key="t" class="flex justify-between"><span class="font-mono text-neutral-500">{{ t }}</span><span class="font-semibold">{{ n }}</span></li>
                    </ul>
                    <div v-else class="mt-1 text-xs text-neutral-400">Tidak ada referensi (customer kosong).</div>
                </div>
            </div>

            <div class="card p-4">
                <label :class="labelCls">Customer B (tujuan — menerima data)</label>
                <select v-model.number="form.target_id" :class="inputCls">
                    <option :value="null">— Pilih Customer B —</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id" :disabled="c.id === form.source_id">{{ c.code }} — {{ c.full_name }}{{ c.id_number ? ` (${c.id_number})` : '' }}</option>
                </select>
            </div>
        </div>

        <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-900/20">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Aksi ini memindahkan data dan menonaktifkan Customer A. Pastikan Anda memilih arah yang benar (A → B).</span>
        </div>

        <button @click="submit" :disabled="form.processing || !form.source_id || !form.target_id" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
            <GitMerge class="h-4 w-4" /> Gabungkan Customer
        </button>
    </div>
</template>
