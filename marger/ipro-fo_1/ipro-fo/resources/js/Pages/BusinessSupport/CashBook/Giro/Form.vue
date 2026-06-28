<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ counterAccounts: Array<any>; defaultCounter: number | null }>();
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    receive_date: today, due_date: '', bank_name: '', cheque_number: '', drawer: '', amount: 0,
    counter_account_id: props.defaultCounter ?? '',
});
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.giro.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> Terima BG/Cek Masuk</h1>
            <Link :href="route('bs.giro.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Terima *</label><input v-model="form.receive_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Jatuh Tempo *</label><input v-model="form.due_date" type="date" required :class="inputCls" /><p v-if="form.errors.due_date" class="mt-1 text-xs text-danger-500">{{ form.errors.due_date }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bank Penerbit *</label><input v-model="form.bank_name" required placeholder="mis. BCA" :class="inputCls" /><p v-if="form.errors.bank_name" class="mt-1 text-xs text-danger-500">{{ form.errors.bank_name }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Warkat *</label><input v-model="form.cheque_number" required :class="inputCls" /><p v-if="form.errors.cheque_number" class="mt-1 text-xs text-danger-500">{{ form.errors.cheque_number }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Penerbit / Customer *</label><input v-model="form.drawer" required :class="inputCls" /><p v-if="form.errors.drawer" class="mt-1 text-xs text-danger-500">{{ form.errors.drawer }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nominal *</label><input v-model.number="form.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Piutang Asal (Lawan) *</label><SearchSelect :model-value="form.counter_account_id" :options="counterAccounts" placeholder="— cari akun (kode / nama) —" @update:model-value="(v) => form.counter_account_id = v" /><p v-if="form.errors.counter_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.counter_account_id }}</p></div>
                <div class="md:col-span-2 rounded-lg bg-primary-50 px-4 py-2 text-xs text-primary-700 dark:bg-primary-900/20 dark:text-primary-300">Jurnal: Dr Piutang Giro · Cr Piutang Usaha — {{ fmtRp(form.amount) }}</div>
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
                <Link :href="route('bs.giro.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
