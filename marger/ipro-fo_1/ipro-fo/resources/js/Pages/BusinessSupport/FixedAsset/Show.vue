<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building, TrendingDown, BookText, Banknote, Trash2, History, Pencil } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ asset: any; now: { year: number; month: number }; today: string; cashAccounts: any[] }>();
const categories: Record<string, string> = { bangunan: 'Bangunan', kendaraan: 'Kendaraan', inventaris: 'Inventaris Kantor', tanah: 'Tanah' };
const statusBadge: Record<string, any> = { active: 'success', fully_depreciated: 'neutral', disposed: 'danger', sold: 'primary' };
const statusLabel: Record<string, string> = { active: 'Aktif', fully_depreciated: 'Habis Susut', disposed: 'Dihapus', sold: 'Terjual' };
const disposalLabel: Record<string, string> = { write_off: 'Penghapusan (write-off)', sale: 'Penjualan' };

const isLive = computed(() => props.asset.status === 'active' || props.asset.status === 'fully_depreciated');
const canOpening = computed(() => isLive.value && props.asset.category !== 'tanah' && (props.asset.entries?.length ?? 0) === 0 && Number(props.asset.accumulated_depreciation) === 0);
const bookValue = computed(() => Number(props.asset.book_value) || 0);

const action = ref<'' | 'depreciate' | 'sell' | 'dispose' | 'opening'>('');
const depForm = useForm<any>({ year: props.now.year, month: props.now.month });
const sellForm = useForm<any>({ disposed_date: props.today, proceeds: 0, cash_account_id: '', note: '' });
const dispForm = useForm<any>({ disposed_date: props.today, note: '' });
// Default "Per Tanggal" saldo awal = Tgl Perolehan aset (Y-m-d dari server), tetap bisa diedit.
const openForm = useForm<any>({ as_of_date: String(props.asset.acquisition_date ?? props.today).slice(0, 10), amount: 0 });

const sellGain = computed(() => (Number(sellForm.proceeds) || 0) - bookValue.value);
function reset() { action.value = ''; }
function doDep() { depForm.post(route('bs.fa.depreciate', props.asset.id), { onSuccess: reset }); }
function doSell() { sellForm.post(route('bs.fa.sell', props.asset.id), { onSuccess: reset }); }
function doDispose() { if (confirm('Hapus aset ini dari pembukuan? Jurnal pelepasan akan terbentuk.')) dispForm.post(route('bs.fa.dispose', props.asset.id), { onSuccess: reset }); }
function doOpening() { openForm.post(route('bs.fa.opening', props.asset.id), { onSuccess: reset }); }

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.fa.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Aset Tetap</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Building class="h-6 w-6 text-primary-600" /> <span class="font-mono">{{ asset.asset_number }}</span> · {{ asset.name }}</h1>
            <div class="flex items-center gap-2">
                <Link :href="route('bs.fa.edit', asset.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"><Pencil class="h-4 w-4" /> Edit</Link>
                <Badge :variant="statusBadge[asset.status] ?? 'neutral'">{{ statusLabel[asset.status] ?? asset.status }}</Badge>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Kategori</div><div class="font-medium">{{ categories[asset.category] }}</div></div>
            <div><div class="text-neutral-500">Tgl Perolehan</div><div class="font-medium">{{ fmtDate(asset.acquisition_date) }}</div></div>
            <div><div class="text-neutral-500">Umur Ekonomis</div><div class="font-medium">{{ asset.useful_life_months }} bulan</div></div>
            <div><div class="text-neutral-500">Harga Perolehan</div><div class="font-mono font-semibold">{{ fmtRp(asset.acquisition_cost) }}</div></div>
            <div><div class="text-neutral-500">Nilai Residu</div><div class="font-mono">{{ fmtRp(asset.residual_value) }}</div></div>
            <div><div class="text-neutral-500">Penyusutan / bulan</div><div class="font-mono">{{ fmtRp(asset.monthly_depreciation) }}</div></div>
            <div><div class="text-neutral-500">Akumulasi Penyusutan</div><div class="font-mono text-danger-600">{{ fmtRp(asset.accumulated_depreciation) }}</div></div>
            <div class="md:col-span-2"><div class="text-neutral-500">Nilai Buku</div><div class="font-mono text-lg font-bold text-success-700">{{ fmtRp(asset.book_value) }}</div></div>
        </div>

        <!-- Ringkasan pelepasan (jika sudah dihapus/terjual) -->
        <div v-if="asset.status === 'disposed' || asset.status === 'sold'" class="card border-l-4 p-5" :class="asset.status === 'sold' ? 'border-l-primary-500' : 'border-l-danger-500'">
            <h2 class="mb-3 flex items-center gap-2 font-semibold"><Banknote class="h-5 w-5" :class="asset.status === 'sold' ? 'text-primary-600' : 'text-danger-600'" /> {{ disposalLabel[asset.disposal_type] ?? 'Pelepasan' }}</h2>
            <div class="grid gap-4 text-sm md:grid-cols-3">
                <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(asset.disposed_date) }}</div></div>
                <div v-if="asset.disposal_type === 'sale'"><div class="text-neutral-500">Harga Jual</div><div class="font-mono font-semibold">{{ fmtRp(asset.disposal_proceeds) }}</div></div>
                <div v-if="asset.disposal_journal_id"><div class="text-neutral-500">Jurnal</div><div class="font-mono text-neutral-500"><BookText class="mr-1 inline h-3.5 w-3.5" />#{{ asset.disposal_journal_id }}</div></div>
                <div v-if="asset.disposal_note" class="md:col-span-3"><div class="text-neutral-500">Catatan</div><div>{{ asset.disposal_note }}</div></div>
            </div>
        </div>

        <!-- Aksi (hanya bila aset masih hidup) -->
        <div v-if="isLive" class="card p-5">
            <div class="flex flex-wrap gap-2">
                <button v-if="asset.category !== 'tanah' && asset.status === 'active'" @click="action = action === 'depreciate' ? '' : 'depreciate'" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium" :class="action === 'depreciate' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'"><TrendingDown class="h-4 w-4" /> Susutkan</button>
                <button @click="action = action === 'sell' ? '' : 'sell'" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium" :class="action === 'sell' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'"><Banknote class="h-4 w-4" /> Jual Aset</button>
                <button @click="action = action === 'dispose' ? '' : 'dispose'" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium" :class="action === 'dispose' ? 'border-danger-500 bg-danger-50 text-danger-700 dark:bg-danger-900/20 dark:text-danger-300' : 'border-neutral-300 dark:border-neutral-700'"><Trash2 class="h-4 w-4" /> Hapus Aset</button>
                <button v-if="canOpening" @click="action = action === 'opening' ? '' : 'opening'" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium" :class="action === 'opening' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'"><History class="h-4 w-4" /> Saldo Awal Penyusutan</button>
            </div>

            <!-- Susutkan periode -->
            <form v-if="action === 'depreciate'" @submit.prevent="doDep" class="mt-4 flex flex-wrap items-end gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahun</label><input v-model.number="depForm.year" type="number" required :class="[inputCls,'w-28']" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bulan</label><input v-model.number="depForm.month" type="number" min="1" max="12" required :class="[inputCls,'w-20']" /></div>
                <Button type="submit" :disabled="depForm.processing">Posting Penyusutan</Button>
                <p class="w-full text-xs text-neutral-400">Jurnal: Dr Beban Penyusutan · Cr Akumulasi Penyusutan — {{ fmtRp(asset.monthly_depreciation) }}</p>
            </form>

            <!-- Jual aset -->
            <form v-else-if="action === 'sell'" @submit.prevent="doSell" class="mt-4 space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Jual *</label><input v-model="sellForm.disposed_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Harga Jual *</label><input v-model.number="sellForm.proceeds" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="sellForm.errors.proceeds" class="mt-1 text-xs text-danger-500">{{ sellForm.errors.proceeds }}</p></div>
                    <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Terima ke Kas/Bank *</label><SearchSelect v-model="sellForm.cash_account_id" :options="cashAccounts" placeholder="— cari kas/bank —" /><p v-if="sellForm.errors.cash_account_id" class="mt-1 text-xs text-danger-500">{{ sellForm.errors.cash_account_id }}</p></div>
                    <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="sellForm.note" :class="inputCls" /></div>
                </div>
                <div class="space-y-1 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
                    <div class="flex justify-between"><span>Nilai Buku saat ini</span><span class="font-mono">{{ fmtRp(bookValue) }}</span></div>
                    <div class="flex justify-between"><span>Harga Jual</span><span class="font-mono">{{ fmtRp(sellForm.proceeds) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 font-semibold dark:border-neutral-700" :class="sellGain >= 0 ? 'text-success-700' : 'text-danger-600'"><span>{{ sellGain >= 0 ? 'Laba Penjualan' : 'Rugi Penjualan' }}</span><span class="font-mono">{{ fmtRp(Math.abs(sellGain)) }}</span></div>
                </div>
                <p class="text-xs text-neutral-400">Jurnal: Dr Kas/Bank + Dr Akumulasi · Cr Aset Tetap + {{ sellGain >= 0 ? 'Cr Laba' : 'Dr Rugi' }} Penjualan Aset.</p>
                <div class="flex gap-2"><Button type="submit" variant="primary" :disabled="sellForm.processing">Catat Penjualan</Button><button type="button" @click="reset" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Hapus aset -->
            <form v-else-if="action === 'dispose'" @submit.prevent="doDispose" class="mt-4 space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Hapus *</label><input v-model="dispForm.disposed_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="dispForm.note" :class="inputCls" placeholder="alasan penghapusan" /></div>
                </div>
                <div class="space-y-1 rounded-lg bg-danger-50 p-3 text-sm dark:bg-danger-900/20">
                    <div class="flex justify-between"><span>Nilai Buku dihapuskan (rugi)</span><span class="font-mono font-semibold text-danger-600">{{ fmtRp(bookValue) }}</span></div>
                </div>
                <p class="text-xs text-neutral-400">Jurnal: Dr Akumulasi Penyusutan + Dr Rugi Penghapusan · Cr Aset Tetap.</p>
                <div class="flex gap-2"><Button type="submit" variant="danger" :disabled="dispForm.processing">Hapus Aset</Button><button type="button" @click="reset" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Saldo awal penyusutan -->
            <form v-else-if="action === 'opening'" @submit.prevent="doOpening" class="mt-4 space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Per Tanggal *</label><input v-model="openForm.as_of_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akumulasi Awal *</label><input v-model.number="openForm.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="openForm.errors.amount" class="mt-1 text-xs text-danger-500">{{ openForm.errors.amount }}</p></div>
                </div>
                <p class="text-xs text-neutral-400">Untuk aset migrasi yang sudah berjalan. Jurnal: Dr Laba Ditahan · Cr Akumulasi Penyusutan. Maks dasar penyusutan {{ fmtRp(asset.depreciable_base) }}.</p>
                <div class="flex gap-2"><Button type="submit" :disabled="openForm.processing">Simpan Saldo Awal</Button><button type="button" @click="reset" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
        </div>

        <!-- Riwayat penyusutan -->
        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Penyusutan</div>
            <table v-if="asset.entries?.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Periode</th><th class="px-4 py-2.5">Tanggal</th><th class="px-4 py-2.5 text-right">Penyusutan</th><th class="px-4 py-2.5 text-right">Nilai Buku</th><th class="px-4 py-2.5 text-center">Jurnal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="e in asset.entries" :key="e.id">
                        <td class="px-4 py-2.5 font-mono">{{ e.period_year }}/{{ String(e.period_month).padStart(2,'0') }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(e.entry_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(e.amount) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(e.book_value_after) }}</td>
                        <td class="px-4 py-2.5 text-center"><span v-if="e.journal_id" class="inline-flex items-center gap-1 text-xs text-neutral-400"><BookText class="h-3.5 w-3.5" /> #{{ e.journal_id }}</span></td>
                    </tr>
                </tbody>
            </table>
            <div v-else class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada penyusutan.</div>
        </div>
    </div>
</template>
