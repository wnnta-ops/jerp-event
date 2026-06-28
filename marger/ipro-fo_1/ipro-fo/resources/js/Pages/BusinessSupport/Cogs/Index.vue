<script setup lang="ts">
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, Calculator, Boxes } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    recognitions: any;
    filters: { search?: string };
    pool: Array<any>;
    poolTotal: number;
    totalCogs: number;
    inventoryAccounts: Array<any>;
    costCenters: Array<any>;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

const showModal = ref(false);
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({ recognition_date: today, reference: '', description: '', inventory_account_id: props.inventoryAccounts[0]?.id ?? '', cost_center_id: '', amount: 0 });
function submit() { form.post(route('bs.cogs.store'), { onSuccess: () => { showModal.value = false; form.reset(); form.recognition_date = today; } }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Calculator class="h-6 w-6 text-primary-600" /> Cost of Good Sold <span class="text-base font-normal text-neutral-400">(HPP)</span></h1>
            <div class="flex items-center gap-2">
                <Link :href="route('bs.cogs.realcost')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium dark:border-neutral-700"><Calculator class="h-4 w-4" /> HPP RealCost</Link>
                <Button @click="showModal = true"><template #icon-left><Plus class="h-4 w-4" /></template>Akui HPP</Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Cost pool -->
            <div class="card p-5 lg:col-span-2">
                <h2 class="mb-3 flex items-center gap-2 font-semibold"><Boxes class="h-5 w-5 text-primary-600" /> Cost Pool — Persediaan &amp; CIP (dari GL)</h2>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="p in pool" :key="p.id"><td class="py-1.5"><span class="font-mono text-xs text-neutral-400">{{ p.code }}</span> {{ p.name }}</td><td class="py-1.5 text-right font-mono">{{ fmtRp(p.balance) }}</td></tr>
                    </tbody>
                    <tfoot class="border-t border-neutral-200 font-bold dark:border-neutral-700"><tr><td class="py-2">Total Tersedia</td><td class="py-2 text-right font-mono">{{ fmtRp(poolTotal) }}</td></tr></tfoot>
                </table>
                <EmptyState v-if="!pool?.length" title="Cost pool kosong" description="Belum ada saldo persediaan/CIP di GL." />
            </div>
            <div class="card p-5">
                <div class="text-xs uppercase text-neutral-400">Total HPP Diakui</div>
                <div class="mt-1 text-2xl font-bold text-danger-600">{{ fmtRp(totalCogs) }}</div>
                <p class="mt-2 text-xs text-neutral-500">Saat unit terjual / serah terima, biaya proyek (CIP / persediaan) diakui sebagai Beban Pokok Penjualan.</p>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Pengakuan HPP</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Dari Persediaan</th><th class="px-4 py-3 text-right">Nilai HPP</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in recognitions.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.cogs_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.recognition_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.reference ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.description }}</td>
                        <td class="px-4 py-2.5">{{ r.inventory_account?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.amount) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!recognitions.data?.length" title="Belum ada pengakuan HPP" description="Klik Akui HPP saat unit terjual." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">Akui HPP (Cost of Good Sold)</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.recognition_date" type="date" required :class="inputCls" /></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Referensi (Booking/Unit)</label><input v-model="form.reference" :class="inputCls" /></div>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required placeholder="mis. HPP unit C3-12 (AJB)" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Persediaan/CIP *</label><select v-model="form.inventory_account_id" required :class="inputCls"><option v-for="a in inventoryAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center</label><select v-model="form.cost_center_id" :class="inputCls"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai HPP *</label><input v-model.number="form.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
                        <div class="rounded-lg bg-primary-50 px-3 py-2 text-xs text-primary-700 dark:bg-primary-900/20 dark:text-primary-300">Jurnal: Dr Beban Pokok Penjualan · Cr Persediaan/CIP — {{ fmtRp(form.amount) }}</div>
                        <div class="flex justify-end gap-2 pt-1"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Akui HPP</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
