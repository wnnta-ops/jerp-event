<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, Wallet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ sources: any[]; accounts: any[]; defaultType: string }>();
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({ trx_type: props.defaultType || 'out', trx_date: today, counter_account_id: '', amount: 0, payee: '', description: '' });

const counterOptions = computed(() => form.trx_type === 'in' ? props.sources : props.accounts);
const labels = computed(() => form.trx_type === 'in'
    ? { counter: 'Sumber Dana (Bank/Kas)', payee: 'Diterima dari' }
    : { counter: 'Akun Beban / Tujuan', payee: 'Dibayarkan kepada' });
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.pettycash.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-primary-600" /> Transaksi Kas Kecil</h1>
            <Link :href="route('bs.pettycash.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card p-5">
                <label class="mb-2 block text-xs font-medium text-neutral-500">Jenis</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium" :class="form.trx_type === 'in' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.trx_type" type="radio" value="in" class="sr-only" /> Masuk (Top-up)</label>
                    <label class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium" :class="form.trx_type === 'out' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.trx_type" type="radio" value="out" class="sr-only" /> Keluar (Beban)</label>
                </div>
            </div>
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.trx_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nominal *</label><input v-model.number="form.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">{{ labels.counter }} *</label><SearchSelect v-model="form.counter_account_id" :options="counterOptions" placeholder="— cari akun —" /><p v-if="form.errors.counter_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.counter_account_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">{{ labels.payee }}</label><input v-model="form.payee" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="inputCls" /><p v-if="form.errors.description" class="mt-1 text-xs text-danger-500">{{ form.errors.description }}</p></div>
                <div class="md:col-span-2 flex items-center justify-between rounded-lg bg-primary-50 px-4 py-3 dark:bg-primary-900/20"><span class="font-semibold">Kas Kecil {{ form.trx_type === 'in' ? 'Masuk' : 'Keluar' }}</span><span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(form.amount) }}</span></div>
            </div>
            <div class="flex gap-2"><Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button><Link :href="route('bs.pettycash.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link></div>
        </form>
    </div>
</template>
