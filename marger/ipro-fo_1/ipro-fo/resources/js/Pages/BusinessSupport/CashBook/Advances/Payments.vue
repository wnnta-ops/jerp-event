<script setup lang="ts">
import { reactive } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowLeft, HandCoins, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ advances: any; cashAccounts: Array<any>; summary: { count: number; total: number } }>();
const today = new Date().toISOString().slice(0, 10);

// State input per-baris (tanggal transfer + sumber bank/kas).
const inputs = reactive<Record<number, { paid_date: string; cash_account_id: any; processing: boolean }>>({});
(props.advances.data ?? []).forEach((a: any) => { inputs[a.id] = { paid_date: today, cash_account_id: '', processing: false }; });

function pay(adv: any) {
    const s = inputs[adv.id];
    if (!s || !s.cash_account_id) return;
    if (!confirm(`Bayar bon ${adv.advance_number} sebesar ${fmtRp(adv.amount)}? Jurnal Uang Muka Operasional akan terbentuk.`)) return;
    s.processing = true;
    router.post(route('bs.advance.pay', adv.id),
        { paid_date: s.paid_date, cash_account_id: s.cash_account_id },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success(`Bon ${adv.advance_number} dibayar — jurnal terbentuk.`); },
            onError: (errors) => {
                const msg = Object.values(errors ?? {})[0] as string | undefined;
                toast.error(msg ?? 'Gagal membayar bon. Muat ulang halaman lalu ulangi.');
            },
            onFinish: () => { s.processing = false; },
        });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.advance.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Bon Sementara</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-amber-600" /> Bayar Bon Sementara <span class="text-base font-normal text-neutral-400">(Dept Finance)</span></h1>
            <Link :href="route('bs.advance.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Ajukan Bon</Button></Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Pengajuan Menunggu Bayar</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.count }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Nominal</div><div class="mt-1 text-xl font-bold">{{ fmtRp(summary.total) }}</div></div>
        </div>

        <p class="text-sm text-neutral-500">Klik transaksi pengajuan, isi <b>tanggal transfer</b> &amp; <b>sumber bank/kas</b>, lalu <b>Bayar</b>. Jurnal <span class="font-mono text-xs">Dr Uang Muka Operasional · Cr Bank/Kas</span> terbentuk saat pembayaran.</p>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3">No.</th><th class="px-3 py-3">Tgl Ajuan</th><th class="px-3 py-3">Pemegang</th><th class="px-3 py-3">Keperluan</th>
                    <th class="px-3 py-3 text-right">Nominal</th><th class="px-3 py-3 text-center">Approval</th><th class="px-3 py-3">Tgl Transfer</th><th class="px-3 py-3" style="min-width:220px">Sumber Bank/Kas</th><th class="px-3 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in advances.data" :key="r.id">
                        <td class="px-3 py-2.5"><Link :href="route('bs.advance.finance.show', r.id)" class="font-mono font-semibold text-primary-600 hover:underline">{{ r.advance_number }}</Link></td>
                        <td class="px-3 py-2.5">{{ fmtDate(r.advance_date) }}</td>
                        <td class="px-3 py-2.5">{{ r.holder }}</td>
                        <td class="px-3 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                        <td class="px-3 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.amount) }}</td>
                        <td class="px-3 py-2.5 text-center">
                            <span v-if="r.is_fully_approved" class="inline-flex items-center gap-1 rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-900/30 dark:text-success-300">Lengkap</span>
                            <Link v-else :href="route('bs.advance.finance.show', r.id)" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 hover:underline dark:bg-amber-900/30 dark:text-amber-300" title="Lengkapi persetujuan dulu">{{ r.approved_count }}/{{ r.required_count }} setuju</Link>
                        </td>
                        <td class="px-3 py-2.5"><input v-if="inputs[r.id]" v-model="inputs[r.id].paid_date" type="date" :disabled="!r.is_fully_approved" :class="[inputCls,'w-36', !r.is_fully_approved ? 'opacity-50' : '']" /></td>
                        <td class="px-3 py-2.5"><SearchSelect v-if="inputs[r.id]" :model-value="inputs[r.id].cash_account_id" :options="cashAccounts" :disabled="!r.is_fully_approved" placeholder="— pilih —" @update:model-value="(v) => inputs[r.id].cash_account_id = v" /></td>
                        <td class="px-3 py-2.5 text-right"><Button size="sm" :disabled="!r.is_fully_approved || !inputs[r.id]?.cash_account_id || inputs[r.id]?.processing" @click="pay(r)"><template #icon-left><HandCoins class="h-3.5 w-3.5" /></template>Bayar</Button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!advances.data?.length" title="Tidak ada pengajuan menunggu pembayaran" description="Semua bon sementara sudah dibayar atau belum ada pengajuan baru." />
        </div>
    </div>
</template>
