<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save, Scale } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    accounts: Array<{ id: number; code: string; name: string; type: string }>;
    costCenters: Array<{ id: number; code: string; name: string }>;
    editing?: any;
}>();

const today = new Date().toISOString().slice(0, 10);
const e = props.editing;
const form = useForm<any>(e ? {
    journal_date: String(e.journal_date).slice(0, 10), reference: e.reference ?? '', description: e.description ?? '',
    cost_center_id: e.cost_center_id ?? '',
    lines: (e.lines ?? []).map((l: any) => ({ account_id: l.account_id, description: l.description ?? '', debit: Number(l.debit) || 0, credit: Number(l.credit) || 0 })),
} : {
    journal_date: today, reference: '', description: '', cost_center_id: '',
    lines: [{ account_id: '', description: '', debit: 0, credit: 0 }, { account_id: '', description: '', debit: 0, credit: 0 }],
});
function addLine() { form.lines.push({ account_id: '', description: '', debit: 0, credit: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }

const totalDebit = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.debit) || 0), 0));
const totalCredit = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.credit) || 0), 0));
const balanced = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 0.005 && totalDebit.value > 0);
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function submit() {
    if (!balanced.value) return;
    if (props.editing) form.put(route('bs.gl.journal.update', props.editing.id));
    else form.post(route('bs.gl.journal.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ editing ? 'Edit Jurnal ' + editing.journal_number : 'Registrasi Jurnal Memorial' }}</h1>
            <Link :href="route('bs.gl.journal.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <div v-if="form.errors.lines || $page.props.flash?.error" class="rounded-lg bg-danger-50 px-4 py-2.5 text-sm text-danger-700">{{ form.errors.lines || $page.props.flash?.error }}</div>
        <div v-if="editing && editing.source !== 'memorial'" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            ⚠️ Jurnal ini berasal dari modul <b>{{ editing.source?.toUpperCase() }}</b>. Mengedit di sini hanya memperbaiki <b>jurnal GL</b>, <b>tidak</b> mengubah dokumen sumbernya (transaksi kas / invoice). Gunakan untuk koreksi pencatatan bila perlu.
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-4">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.journal_date" type="date" required :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.reference" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Struktur Jurnal (Debit / Kredit)</h2>
                    <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">Akun</th><th class="py-2">Keterangan</th><th class="w-36 py-2 text-right">Debit</th><th class="w-36 py-2 text-right">Kredit</th><th class="w-10"></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5 pr-2"><select v-model="l.account_id" required :class="inputCls"><option value="">— pilih akun —</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></td>
                            <td class="py-1.5 pr-2"><input v-model="l.description" :class="inputCls" /></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.debit" type="number" step="1" min="0" :class="[inputCls,'text-right']" @input="l.debit && (l.credit = 0)" /></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.credit" type="number" step="1" min="0" :class="[inputCls,'text-right']" @input="l.credit && (l.debit = 0)" /></td>
                            <td class="py-1.5 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length<=2" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 text-sm font-bold dark:border-neutral-700">
                        <tr><td colspan="2" class="py-2 text-right text-neutral-500">Total</td><td class="py-2 text-right font-mono">{{ fmtRp(totalDebit) }}</td><td class="py-2 text-right font-mono">{{ fmtRp(totalCredit) }}</td><td></td></tr>
                    </tfoot>
                </table>
                <div class="mt-3 flex items-center gap-2 text-sm" :class="balanced ? 'text-success-600' : 'text-danger-600'">
                    <Scale class="h-4 w-4" />
                    <span v-if="balanced">Balance ✓ (debit = kredit)</span>
                    <span v-else>Belum balance — selisih {{ fmtRp(Math.abs(totalDebit - totalCredit)) }}. Jurnal hanya bisa disimpan bila balance.</span>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || !balanced"><template #icon-left><Save class="h-4 w-4" /></template>{{ editing ? 'Simpan Perubahan' : 'Posting Jurnal' }}</Button>
                <Link :href="route('bs.gl.journal.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
