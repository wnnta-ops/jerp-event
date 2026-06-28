<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { FileCog, ArrowLeft, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    rule: any | null;
    sources: Record<string, string>;
    amountBases: Record<string, string>;
    accounts: Array<{ id: number; code: string; name: string }>;
}>();

const isEdit = !!props.rule;
const form = useForm({
    code: props.rule?.code ?? '',
    name: props.rule?.name ?? '',
    source: props.rule?.source ?? 'memorial',
    event: props.rule?.event ?? '',
    description: props.rule?.description ?? '',
    sort_order: props.rule?.sort_order ?? 0,
    is_active: props.rule?.is_active ?? true,
    lines: (props.rule?.lines?.length ? props.rule.lines.map((l: any) => ({ ...l })) : [
        { side: 'debit', account_role: '', account_id: '', amount_basis: 'total', description: '' },
        { side: 'credit', account_role: '', account_id: '', amount_basis: 'total', description: '' },
    ]) as any[],
});

function addLine(side: string) { form.lines.push({ side, account_role: '', account_id: '', amount_basis: 'total', description: '' }); }
function removeLine(i: number) { if (form.lines.length > 1) form.lines.splice(i, 1); }
function submit() {
    isEdit ? form.put(route('bs.gl.transaction-rule.update', props.rule.id)) : form.post(route('bs.gl.transaction-rule.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="max-w-5xl space-y-4">
        <Link :href="route('bs.gl.transaction-rule.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Transaction Rule</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCog class="h-6 w-6 text-primary-600" /> {{ isEdit ? 'Edit' : 'Tambah' }} Transaction Rule</h1>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-3 p-4 sm:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="form.name" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber *</label><select v-model="form.source" :class="inputCls"><option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Event</label><input v-model="form.event" placeholder="invoice / payment / …" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Urutan</label><input v-model.number="form.sort_order" type="number" :class="inputCls" /></div>
                <div class="sm:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Deskripsi</label><input v-model="form.description" :class="inputCls" /></div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <h2 class="font-semibold">Template Jurnal</h2>
                    <div class="flex gap-2">
                        <button type="button" @click="addLine('debit')" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1 text-xs dark:border-neutral-700"><Plus class="h-3 w-3" /> Debit</button>
                        <button type="button" @click="addLine('credit')" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1 text-xs dark:border-neutral-700"><Plus class="h-3 w-3" /> Kredit</button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-2 w-24">D/K</th><th class="px-3 py-2">Peran Akun *</th><th class="px-3 py-2 w-56">Akun Default</th><th class="px-3 py-2 w-40">Basis Nilai</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2 w-10"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(l, i) in form.lines" :key="i">
                            <td class="px-3 py-1.5"><select v-model="l.side" :class="inputCls"><option value="debit">Debit</option><option value="credit">Kredit</option></select></td>
                            <td class="px-3 py-1.5"><input v-model="l.account_role" required placeholder="mis. Hutang Usaha (payable)" :class="inputCls" /></td>
                            <td class="px-3 py-1.5"><SearchSelect v-model="l.account_id" :options="accounts" placeholder="— opsional —" /></td>
                            <td class="px-3 py-1.5"><select v-model="l.amount_basis" :class="inputCls"><option v-for="(label, key) in amountBases" :key="key" :value="key">{{ label }}</option></select></td>
                            <td class="px-3 py-1.5"><input v-model="l.description" :class="inputCls" /></td>
                            <td class="px-3 py-1.5 text-center"><button type="button" @click="removeLine(i)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.errors.lines" class="px-4 py-2 text-xs text-danger-500">{{ form.errors.lines }}</p>
            </div>

            <div class="flex justify-end gap-2">
                <Link :href="route('bs.gl.transaction-rule.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
                <Button type="submit" :disabled="form.processing">{{ isEdit ? 'Simpan Perubahan' : 'Simpan' }}</Button>
            </div>
        </form>
    </div>
</template>
