<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale, Plus, ChevronRight } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ reconciliations: any; accounts: Array<any> }>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({ account_id: '', statement_date: today, statement_balance: 0, notes: '' });
const selectedAcc = computed(() => props.accounts.find((a: any) => a.id === form.account_id));
function submit() { form.post(route('bs.bankrec.store')); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> Rekonsiliasi Bank</h1>
        <p class="text-sm text-neutral-500">Cocokkan saldo buku (GL kas/bank) dengan rekening koran. Tandai transaksi yang sudah muncul di koran hingga selisih nol.</p>

        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Mulai Rekonsiliasi Baru</h2>
            <form @submit.prevent="submit" class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Kas/Bank *</label><select v-model="form.account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select><p v-if="selectedAcc" class="mt-1 text-[11px] text-neutral-400">Saldo buku saat ini: {{ fmtRp(selectedAcc.book_balance) }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Koran *</label><input v-model="form.statement_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Saldo Koran *</label><input v-model.number="form.statement_balance" type="number" step="1" required :class="[inputCls,'text-right font-mono']" /></div>
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="form.notes" :class="inputCls" /></div>
                <div class="flex items-end"><Button type="submit" :disabled="form.processing || !form.account_id"><template #icon-left><Plus class="h-4 w-4" /></template>Buat</Button></div>
            </form>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Akun</th><th class="px-4 py-3">Tgl Koran</th><th class="px-4 py-3 text-right">Saldo Buku</th><th class="px-4 py-3 text-right">Saldo Koran</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in reconciliations.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.bankrec.show', r.id))">
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ r.account?.code }}</span> {{ r.account?.name }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.statement_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.book_balance) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.statement_balance) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'reconciled' ? 'success' : 'warning'" size="sm">{{ r.status === 'reconciled' ? 'Reconciled' : 'Open' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right"><ChevronRight class="ml-auto h-4 w-4 text-neutral-300" /></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!reconciliations.data?.length" title="Belum ada rekonsiliasi" description="Mulai rekonsiliasi baru dengan memilih akun bank dan saldo koran." />
        </div>
    </div>
</template>
