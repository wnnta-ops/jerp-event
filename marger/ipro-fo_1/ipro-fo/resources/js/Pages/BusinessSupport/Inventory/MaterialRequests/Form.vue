<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    projects: Array<{ id: number; name: string }>;
    costCenters: Array<{ id: number; code: string; name: string }>;
    items: Array<{ id: number; code: string; name: string }>;
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    project_id: '', cost_center_id: '', request_date: today, needed_date: '', department: '', notes: '',
    lines: [{ item_id: '', qty: 1, kind: 'expense', spec: '' }],
});
function addLine() { form.lines.push({ item_id: '', qty: 1, kind: 'expense', spec: '' }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
function submit() { form.post(route('bs.fpb.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Buat FPB (Permintaan Barang)</h1>
            <Link :href="route('bs.fpb.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.request_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dibutuhkan</label><input v-model="form.needed_date" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center</label><select v-model="form.cost_center_id" :class="inputCls"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Project</label><select v-model="form.project_id" :class="inputCls"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Departemen</label><input v-model="form.department" :class="inputCls" /></div>
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.notes" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Item yang Diminta</h2>
                    <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">Item</th><th class="w-24 py-2 text-right">Qty</th><th class="w-32 py-2">Jenis</th><th class="py-2">Spesifikasi</th><th class="w-10"></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5 pr-2"><select v-model="l.item_id" required :class="inputCls"><option value="">— pilih item —</option><option v-for="it in items" :key="it.id" :value="it.id">{{ it.code }} - {{ it.name }}</option></select></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.qty" type="number" step="0.01" min="0.01" required :class="[inputCls,'text-right']" /></td>
                            <td class="py-1.5 pr-2"><select v-model="l.kind" :class="inputCls"><option value="expense">Expense</option><option value="asset">Asset</option></select></td>
                            <td class="py-1.5 pr-2"><input v-model="l.spec" :class="inputCls" /></td>
                            <td class="py-1.5 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length===1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.errors.lines" class="mt-2 text-xs text-danger-500">{{ form.errors.lines }}</p>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan FPB</Button>
                <Link :href="route('bs.fpb.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
