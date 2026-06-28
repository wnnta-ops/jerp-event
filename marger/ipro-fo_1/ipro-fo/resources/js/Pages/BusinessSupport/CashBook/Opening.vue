<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Trash2, Landmark, ArrowLeft, Layers, FileSpreadsheet, Upload, Download } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    openings: any;
    cashAccounts: Array<{ id: number; code: string; name: string }>;
    accounts: Array<{ id: number; code: string; name: string }>;
    summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const showModal = ref(false);
const form = useForm({ cash_account_id: '' as any, counter_account_id: '' as any, trx_date: '', amount: 0 as number, description: '' });
function openCreate() { form.reset(); showModal.value = true; }
function submit() { form.post(route('bs.cashbook.opening.store'), { onSuccess: () => { showModal.value = false; } }); }
function destroy(o: any) { if (!confirm(`Hapus saldo awal rekening ${o.cash_account?.code}?`)) return; router.delete(route('bs.cashbook.opening.destroy', o.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// ── Tambah Massal ────────────────────────────────────────────────
type BulkRow = { cash_account_id: any; counter_account_id: any; trx_date: string; amount: number; description: string };
function emptyRow(): BulkRow { return { cash_account_id: '', counter_account_id: '', trx_date: '', amount: 0, description: '' }; }
const showBulk = ref(false);
const bulkForm = useForm<{ rows: BulkRow[] }>({ rows: [emptyRow()] });
function openBulk() { bulkForm.reset(); bulkForm.rows = [emptyRow()]; bulkForm.clearErrors(); showBulk.value = true; }
function addRow() { bulkForm.rows.push(emptyRow()); }
function removeRow(i: number) { bulkForm.rows.splice(i, 1); if (!bulkForm.rows.length) bulkForm.rows.push(emptyRow()); }
function bulkError(i: number, field: string): string { return (bulkForm.errors as Record<string, string>)[`rows.${i}.${field}`] ?? ''; }
function submitBulk() { bulkForm.post(route('bs.cashbook.opening.bulk-store'), { preserveScroll: true, onSuccess: () => { showBulk.value = false; } }); }

// ── Import Excel/CSV ─────────────────────────────────────────────
const showImport = ref(false);
const importForm = useForm<{ file: File | null }>({ file: null });
function openImport() { importForm.reset(); importForm.clearErrors(); showImport.value = true; }
function onImportFile(e: Event) { const t = e.target as HTMLInputElement; importForm.file = t.files?.[0] ?? null; }
function submitImport() { importForm.post(route('bs.cashbook.opening.import'), { preserveScroll: true, forceFormData: true, onSuccess: () => { showImport.value = false; } }); }
</script>

<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-primary-600" /> Saldo Awal Rekening</h1>
            <div class="flex items-center gap-2">
                <Button variant="secondary" @click="openImport"><template #icon-left><FileSpreadsheet class="h-4 w-4" /></template>Import Excel</Button>
                <Button variant="secondary" @click="openBulk"><template #icon-left><Layers class="h-4 w-4" /></template>Tambah Massal</Button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah</Button>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Saldo awal tiap rekening kas/bank saat migrasi. Otomatis membentuk jurnal: Dr rekening / Cr akun lawan (mis. Modal/Saldo Awal).</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Entri</div><div class="mt-1 text-xl font-bold">{{ summary.count }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Saldo Awal</div><div class="mt-1 font-mono text-xl font-bold">{{ fmtRp(summary.total) }}</div></div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Rekening</th><th class="px-4 py-3">Akun Lawan</th><th class="px-4 py-3 text-right">Saldo Awal</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="o in openings.data" :key="o.id">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ o.trx_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(o.trx_date) }}</td>
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ o.cash_account?.code }}</span> {{ o.cash_account?.name }}</td>
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ o.counter_account?.code }}</span> {{ o.counter_account?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(o.amount) }}</td>
                        <td class="px-4 py-2.5 text-right"><button @click="destroy(o)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!openings.data?.length" title="Belum ada saldo awal rekening" description="Tambah saldo awal tiap rekening kas/bank untuk memulai." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">Tambah Saldo Awal Rekening</h2>
                    <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Rekening Kas/Bank *</label><SearchSelect v-model="form.cash_account_id" :options="cashAccounts" placeholder="— cari rekening —" /><p v-if="form.errors.cash_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cash_account_id }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.trx_date" type="date" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Saldo Awal (Rp) *</label><input v-model.number="form.amount" type="number" step="0.01" required :class="inputCls" /></div>
                        <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Lawan (Modal/Saldo Awal) *</label><SearchSelect v-model="form.counter_account_id" :options="accounts" placeholder="— pilih akun lawan —" /><p v-if="form.errors.counter_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.counter_account_id }}</p></div>
                        <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.description" :class="inputCls" /></div>
                        <div class="sm:col-span-2 flex justify-end gap-2 pt-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showBulk" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showBulk = false">
                <div class="flex max-h-[90vh] w-full max-w-6xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4 dark:border-neutral-800">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Layers class="h-5 w-5 text-primary-600" /> Tambah Massal Saldo Awal Rekening</h2>
                        <span class="text-sm text-neutral-500">{{ bulkForm.rows.length }} baris</span>
                    </div>
                    <div class="flex-1 overflow-auto p-4">
                        <table class="w-full border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-neutral-500">
                                    <th class="px-2 py-2 font-medium">Rekening Kas/Bank *</th>
                                    <th class="px-2 py-2 font-medium">Tanggal *</th>
                                    <th class="px-2 py-2 text-right font-medium">Saldo Awal *</th>
                                    <th class="px-2 py-2 font-medium">Akun Lawan *</th>
                                    <th class="px-2 py-2 font-medium">Keterangan</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in bulkForm.rows" :key="i" class="align-top">
                                    <td class="px-2 py-1.5" style="min-width:200px">
                                        <SearchSelect v-model="row.cash_account_id" :options="cashAccounts" placeholder="— rekening —" />
                                        <p v-if="bulkError(i, 'cash_account_id')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'cash_account_id') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:140px">
                                        <input v-model="row.trx_date" type="date" :class="inputCls" />
                                        <p v-if="bulkError(i, 'trx_date')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'trx_date') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:140px">
                                        <input v-model.number="row.amount" type="number" step="0.01" :class="[inputCls, 'text-right font-mono']" />
                                        <p v-if="bulkError(i, 'amount')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'amount') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:200px">
                                        <SearchSelect v-model="row.counter_account_id" :options="accounts" placeholder="— akun lawan —" />
                                        <p v-if="bulkError(i, 'counter_account_id')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'counter_account_id') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:160px">
                                        <input v-model="row.description" :class="inputCls" placeholder="Keterangan" />
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        <button type="button" @click="removeRow(i)" class="mt-1 text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <button type="button" @click="addRow" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-neutral-300 px-3 py-2 text-sm text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <Plus class="h-4 w-4" /> Tambah Baris
                        </button>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-neutral-200 px-6 py-4 dark:border-neutral-800">
                        <button type="button" @click="showBulk = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        <Button variant="success" :disabled="bulkForm.processing" @click="submitBulk"><template #icon-left><Plus class="h-4 w-4" /></template>Simpan Semua</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showImport = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-1 flex items-center gap-2 text-lg font-bold"><FileSpreadsheet class="h-5 w-5 text-primary-600" /> Import Saldo Awal Rekening</h2>
                    <p class="mb-4 text-sm text-neutral-500">Unduh template, isi di Excel, lalu unggah kembali. Mendukung file <b>.xlsx</b>, <b>.xls</b>, dan <b>.csv</b>.</p>

                    <div class="mb-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-300">
                        <div class="mb-1 font-semibold uppercase tracking-wide text-neutral-500">Format kolom</div>
                        <ol class="list-decimal space-y-0.5 pl-4">
                            <li><b>Kode Rekening</b> — kode akun kas/bank (mis. 10101010101)</li>
                            <li><b>Kode Akun Lawan</b> — kode akun lawan (mis. Modal/Saldo Awal)</li>
                            <li><b>Tgl</b> — format <code>YYYY-MM-DD</code> (mis. 2026-01-01)</li>
                            <li><b>Saldo Awal</b> — nominal (boleh negatif untuk saldo kredit)</li>
                            <li><b>Keterangan</b> — opsional</li>
                        </ol>
                        <p class="mt-2 text-neutral-400">Rekening yang sudah punya saldo awal otomatis dilewati. Tiap baris membentuk 1 jurnal (Dr rekening / Cr akun lawan).</p>
                    </div>

                    <a :href="route('bs.cashbook.opening.template')" class="mb-4 inline-flex items-center gap-1.5 rounded-lg border border-primary-300 px-3 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300 dark:hover:bg-neutral-800">
                        <Download class="h-4 w-4" /> Unduh Template (.xlsx)
                    </a>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Pilih file</label>
                        <input type="file" accept=".xlsx,.xls,.csv" @change="onImportFile" class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-700 dark:text-neutral-300" />
                        <p v-if="importForm.errors.file" class="mt-1 text-xs text-danger-500">{{ importForm.errors.file }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" @click="showImport = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        <Button variant="success" :disabled="!importForm.file || importForm.processing" @click="submitImport"><template #icon-left><Upload class="h-4 w-4" /></template>Import</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
