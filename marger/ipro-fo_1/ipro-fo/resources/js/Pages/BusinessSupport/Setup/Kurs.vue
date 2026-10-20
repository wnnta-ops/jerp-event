<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, RefreshCcw, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rates: any;
    currencies: Array<{ code: string; name: string }>;
    filters: Record<string, any>;
}>();

const filterCode = ref(props.filters.currency_code ?? '');
function display() { router.get(route('bs.gl.kurs.index'), { currency_code: filterCode.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ currency_code: '', rate_date: '', rate: 1 as number, note: '' });

function openCreate() { editingId.value = null; form.reset(); form.rate = 1; showModal.value = true; }
function openEdit(r: any) {
    editingId.value = r.id; form.currency_code = r.currency_code; form.rate_date = (r.rate_date ?? '').slice(0, 10); form.rate = Number(r.rate); form.note = r.note ?? '';
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.gl.kurs.update', editingId.value), opts) : form.post(route('bs.gl.kurs.store'), opts);
}
function destroy(r: any) { if (!confirm(`Hapus kurs ${r.currency_code} ${r.rate_date}?`)) return; router.delete(route('bs.gl.kurs.destroy', r.id), { preserveScroll: true }); }
function fmtNum(v: number) { return Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 6 }); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.gl.currency.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Multi Currency</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><RefreshCcw class="h-6 w-6 text-primary-600" /> Kurs Sistem</h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Kurs</Button>
        </div>

        <div class="card flex items-end gap-3 p-4">
            <div class="flex-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Mata Uang</label>
                <select v-model="filterCode" :class="inputCls" @change="display"><option value="">— Semua —</option><option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.code }} · {{ c.name }}</option></select>
            </div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Mata Uang</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3 text-right">Kurs (× IDR)</th><th class="px-4 py-3">Catatan</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rates.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.currency_code }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.rate_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtNum(r.rate) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.note ?? '—' }}</td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2">
                            <button @click="openEdit(r)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                            <button @click="destroy(r)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rates.data?.length" title="Belum ada kurs" description="Tambah kurs per tanggal untuk tiap mata uang asing." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kurs</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Mata Uang *</label>
                            <select v-model="form.currency_code" required :class="inputCls"><option value="">— pilih —</option><option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.code }} · {{ c.name }}</option></select>
                            <p v-if="form.errors.currency_code" class="mt-1 text-xs text-danger-500">{{ form.errors.currency_code }}</p>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.rate_date" type="date" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kurs (1 unit = ? IDR) *</label><input v-model.number="form.rate" type="number" step="0.000001" min="0" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="form.note" :class="inputCls" /></div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
        <Pagination :data="rates" />
    </div>
</template>
