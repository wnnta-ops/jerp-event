<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Wallet, RotateCcw, Users, User } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Baris = { holder: string; saldo_awal: number; penambahan: number; realisasi: number; outstanding: number };
type Rincian = { date: string; journal_id: number; journal_number: string; reference: string | null; description: string | null; debit: number; credit: number; saldo: number };

const props = defineProps<{
    rows: Baris[];
    detail: Rincian[];
    holders: string[];
    totals: { saldo_awal: number; penambahan: number; realisasi: number; outstanding: number; karyawan: number };
    filters: { from: string; to: string; mode: string; holder: string };
    account: { code: string; name: string } | null;
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const mode = ref(props.filters.mode);
const holder = ref(props.filters.holder);

function terapkan() {
    router.get(route('bs.cashbook.outstanding-kasbon'),
        { from: from.value, to: to.value, mode: mode.value, holder: holder.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true });
}
/* Berpindah ke Detail tanpa memilih karyawan hanya menampilkan tabel kosong yang terbaca seperti
   "tidak ada data". Karyawan pertama dipilihkan supaya layarnya langsung berarti. */
function gantiMode(m: string) {
    mode.value = m;
    if (m === 'detail' && ! holder.value) holder.value = props.holders[0] ?? '';
    terapkan();
}
function pilihKaryawan(nama: string) { holder.value = nama; mode.value = 'detail'; terapkan(); }

function reset() {
    const t = new Date();
    from.value = new Date(t.getFullYear(), t.getMonth(), 1).toISOString().slice(0, 10);
    to.value = new Date(t.getFullYear(), t.getMonth() + 1, 0).toISOString().slice(0, 10);
    mode.value = 'summary';
    holder.value = '';
    terapkan();
}

const opsiKaryawan = computed(() => props.holders.map((h) => ({ id: h, label: h })));
const barisTerpilih = computed(() => props.rows.find((r) => r.holder === props.filters.holder) ?? null);

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: any) { return v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-amber-600" /> Outstanding Kasbon</h1>
            <p class="text-sm text-neutral-500">
                Kasbon yang masih di tangan tiap karyawan, dibaca dari mutasi akun
                <b v-if="account" class="font-mono">{{ account.code }} — {{ account.name }}</b><b v-else>Uang Muka Operasional</b>.
                <b>Penambahan</b> = sisi debit (kasbon diberikan, termasuk lewat Alokasi Kasbon) ·
                <b>Realisasi</b> = sisi kredit (dipertanggungjawabkan / dikembalikan) ·
                <b>Outstanding</b> = Saldo Awal + Penambahan − Realisasi.
            </p>
        </div>

        <!-- Saringan periode -->
        <div class="card p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Periode Awal</label><input v-model="from" type="date" :class="[inputCls, 'w-auto']" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Periode Akhir</label><input v-model="to" type="date" :class="[inputCls, 'w-auto']" /></div>
                <div v-if="mode === 'detail'" class="min-w-64">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Karyawan</label>
                    <SearchSelect :model-value="holder" :options="opsiKaryawan" option-value="id" placeholder="— pilih karyawan —" @update:model-value="(v) => { holder = v ?? ''; terapkan(); }" />
                </div>
                <Button size="sm" @click="terapkan">Tampilkan</Button>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300" @click="reset"><RotateCcw class="h-3.5 w-3.5" /> Reset</button>
                <div class="ml-auto inline-flex overflow-hidden rounded-lg border border-neutral-300 dark:border-neutral-700">
                    <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium"
                            :class="mode === 'summary' ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-50 dark:text-neutral-300'"
                            @click="gantiMode('summary')"><Users class="h-3.5 w-3.5" /> Summary</button>
                    <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium"
                            :class="mode === 'detail' ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-50 dark:text-neutral-300'"
                            @click="gantiMode('detail')"><User class="h-3.5 w-3.5" /> Detail</button>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-neutral-400">
                <b>Saldo Awal Kasbon</b> mengikuti tanggal periode awal — yaitu akumulasi mutasi SEBELUM {{ fmtTgl(filters.from) }}.
                Hanya jurnal ber-status Approved yang dihitung.
            </p>
        </div>

        <!-- Ringkasan angka -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Saldo Awal Kasbon</div><div class="mt-1 text-xl font-bold">{{ fmtRp(totals.saldo_awal) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Penambahan Kasbon</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(totals.penambahan) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Realisasi Kasbon</div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(totals.realisasi) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Outstanding Kasbon</div><div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(totals.outstanding) }}</div><div class="mt-1 text-[11px] text-neutral-400">{{ totals.karyawan }} karyawan</div></div>
        </div>

        <!-- SUMMARY — seluruh karyawan -->
        <div v-if="mode === 'summary'" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800"><h2 class="font-semibold">Summary — Seluruh Karyawan</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nama Karyawan</th>
                        <th class="px-4 py-3 text-right">Saldo Awal Kasbon</th>
                        <th class="px-4 py-3 text-right">Penambahan Kasbon</th>
                        <th class="px-4 py-3 text-right">Realisasi Kasbon</th>
                        <th class="px-4 py-3 text-right">Outstanding Kasbon</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in rows" :key="r.holder" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="pilihKaryawan(r.holder)">
                            <td class="px-4 py-2.5 font-medium text-primary-600 hover:underline dark:text-primary-400">{{ r.holder }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.saldo_awal) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(r.penambahan) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.realisasi) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold" :class="r.outstanding > 0 ? 'text-amber-600' : 'text-neutral-400'">{{ fmtRp(r.outstanding) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                        <td class="px-4 py-2.5">Total ({{ rows.length }} karyawan)</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.saldo_awal) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(totals.penambahan) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(totals.realisasi) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(totals.outstanding) }}</td>
                    </tr></tfoot>
                </table>
            </div>
            <EmptyState v-if="! rows.length" title="Belum ada kasbon" description="Tidak ada mutasi Uang Muka Operasional pada periode ini." />
        </div>

        <!-- DETAIL — satu karyawan -->
        <div v-else class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Detail — {{ filters.holder || '(belum memilih karyawan)' }}</h2>
                <p v-if="barisTerpilih" class="text-xs text-neutral-500">
                    Saldo Awal {{ fmtRp(barisTerpilih.saldo_awal) }} + Penambahan {{ fmtRp(barisTerpilih.penambahan) }}
                    − Realisasi {{ fmtRp(barisTerpilih.realisasi) }} = <b>Outstanding {{ fmtRp(barisTerpilih.outstanding) }}</b>
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">No. Jurnal</th>
                        <th class="px-4 py-3">Keterangan</th>
                        <th class="px-4 py-3 text-right">Penambahan</th>
                        <th class="px-4 py-3 text-right">Realisasi</th>
                        <th class="px-4 py-3 text-right">Saldo Berjalan</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-if="barisTerpilih" class="bg-neutral-50/70 dark:bg-neutral-800/40">
                            <td class="px-4 py-2 text-neutral-500" colspan="5">Saldo Awal per {{ fmtTgl(filters.from) }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ fmtRp(barisTerpilih.saldo_awal) }}</td>
                        </tr>
                        <tr v-for="(d, i) in detail" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-2">{{ fmtTgl(d.date) }}</td>
                            <td class="px-4 py-2 font-mono text-xs">
                                <Link :href="route('bs.gl.journal.show', d.journal_id)" class="text-primary-600 hover:underline">{{ d.journal_number }}</Link>
                            </td>
                            <td class="px-4 py-2 text-neutral-500">{{ d.description }}<span v-if="d.reference" class="block text-[11px] text-neutral-400">Ref: {{ d.reference }}</span></td>
                            <td class="px-4 py-2 text-right font-mono text-success-600">{{ d.debit ? fmtRp(d.debit) : '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono text-danger-600">{{ d.credit ? fmtRp(d.credit) : '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ fmtRp(d.saldo) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="barisTerpilih"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                        <td colspan="3" class="px-4 py-2.5">Outstanding per {{ fmtTgl(filters.to) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(barisTerpilih.penambahan) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(barisTerpilih.realisasi) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(barisTerpilih.outstanding) }}</td>
                    </tr></tfoot>
                </table>
            </div>
            <EmptyState v-if="! detail.length" title="Tidak ada mutasi"
                        :description="filters.holder ? 'Karyawan ini tidak punya mutasi kasbon pada periode ini — saldo awalnya tetap ditampilkan di atas.' : 'Pilih karyawan terlebih dahulu.'" />
        </div>
    </div>
</template>
