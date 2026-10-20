<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Trash2, Coins, ArrowLeft, Layers, FileSpreadsheet, Upload, Download } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    openings: any;
    suppliers: Array<{ id: number; code: string; name: string }>;
    accounts: Array<{ id: number; code: string; name: string }>;
    summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const showModal = ref(false);
const form = useForm({ invoice_number: '', invoice_date: '', due_date: '', supplier_id: '' as any, payable_account_id: '' as any, total: 0 as number, description: '' });
function openCreate() { form.reset(); showModal.value = true; }
function submit() { form.post(route('bs.ap.opening.store'), { onSuccess: () => { showModal.value = false; } }); }
function destroy(o: any) { if (!confirm(`Hapus saldo awal ${o.invoice_number}?`)) return; router.delete(route('bs.ap.opening.destroy', o.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// ── Tambah Massal ────────────────────────────────────────────────
type BulkRow = { supplier_id: any; invoice_number: string; invoice_date: string; due_date: string; payable_account_id: any; total: number; description: string };
function emptyRow(): BulkRow { return { supplier_id: '', invoice_number: '', invoice_date: '', due_date: '', payable_account_id: '', total: 0, description: '' }; }
const showBulk = ref(false);
const bulkForm = useForm<{ rows: BulkRow[] }>({ rows: [emptyRow()] });
function openBulk() { bulkForm.reset(); bulkForm.rows = [emptyRow()]; bulkForm.clearErrors(); showBulk.value = true; }
function addRow() { bulkForm.rows.push(emptyRow()); }
function removeRow(i: number) { bulkForm.rows.splice(i, 1); if (!bulkForm.rows.length) bulkForm.rows.push(emptyRow()); }
function bulkError(i: number, field: string): string { return (bulkForm.errors as Record<string, string>)[`rows.${i}.${field}`] ?? ''; }
function submitBulk() { bulkForm.post(route('bs.ap.opening.bulk-store'), { preserveScroll: true, onSuccess: () => { showBulk.value = false; } }); }

// ── Import Excel/CSV ─────────────────────────────────────────────
const showImport = ref(false);
const importForm = useForm<{ file: File | null }>({ file: null });
function openImport() { importForm.reset(); importForm.clearErrors(); showImport.value = true; }
function onImportFile(e: Event) { const t = e.target as HTMLInputElement; importForm.file = t.files?.[0] ?? null; }
function submitImport() { importForm.post(route('bs.ap.opening.import'), { preserveScroll: true, forceFormData: true, onSuccess: () => { showImport.value = false; } }); }
</script>

<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.ap.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Coins class="h-6 w-6 text-primary-600" /> Saldo Awal Hutang</h1>
            <div class="flex items-center gap-2">
                <Button variant="secondary" @click="openImport"><template #icon-left><FileSpreadsheet class="h-4 w-4" /></template>Import Excel</Button>
                <Button variant="secondary" @click="openBulk"><template #icon-left><Layers class="h-4 w-4" /></template>Tambah Massal</Button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah</Button>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Saldo hutang awal per supplier saat migrasi (sub-ledger). Tidak memposting jurnal GL — sisi jurnal ditangani Saldo Awal GL agar tidak dobel.</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Entri</div><div class="mt-1 text-xl font-bold">{{ summary.count }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Saldo Awal</div><div class="mt-1 font-mono text-xl font-bold">{{ fmtRp(summary.total) }}</div></div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No Dokumen</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="o in openings.data" :key="o.id">
                        <td class="px-4 py-2.5 font-mono">{{ o.invoice_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(o.invoice_date) }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(o.due_date) }}</td>
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ o.supplier?.code }}</span> {{ o.supplier?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(o.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="o.status === 'paid' ? 'success' : 'warning'" size="sm">{{ o.status }}</Badge></td>
                        <td class="px-4 py-2.5 text-right"><button v-if="Number(o.paid_amount) < 0.01" @click="destroy(o)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button><span v-else class="text-xs text-neutral-400">terbayar</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!openings.data?.length" title="Belum ada saldo awal hutang" description="Tambah saldo hutang awal per supplier untuk memulai sub-ledger AP." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">Tambah Saldo Awal Hutang</h2>
                    <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier *</label><SearchSelect v-model="form.supplier_id" :options="suppliers" placeholder="— cari supplier —" /><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">No Dokumen *</label><input v-model="form.invoice_number" required :class="inputCls" /><p v-if="form.errors.invoice_number" class="mt-1 text-xs text-danger-500">{{ form.errors.invoice_number }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Total *</label><input v-model.number="form.total" type="number" step="0.01" min="0" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.invoice_date" type="date" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo</label><input v-model="form.due_date" type="date" :class="inputCls" /></div>
                        <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Hutang *</label><SearchSelect v-model="form.payable_account_id" :options="accounts" placeholder="— pilih akun hutang —" /><p v-if="form.errors.payable_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.payable_account_id }}</p></div>
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
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Layers class="h-5 w-5 text-primary-600" /> Tambah Massal Saldo Awal Hutang</h2>
                        <span class="text-sm text-neutral-500">{{ bulkForm.rows.length }} baris</span>
                    </div>
                    <div class="flex-1 overflow-auto p-4">
                        <table class="w-full border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-neutral-500">
                                    <th class="px-2 py-2 font-medium">Supplier *</th>
                                    <th class="px-2 py-2 font-medium">No. Invoice *</th>
                                    <th class="px-2 py-2 font-medium">Tgl Invoice *</th>
                                    <th class="px-2 py-2 font-medium">Jatuh Tempo</th>
                                    <th class="px-2 py-2 font-medium">Akun Hutang *</th>
                                    <th class="px-2 py-2 text-right font-medium">Total *</th>
                                    <th class="px-2 py-2 font-medium">Keterangan</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in bulkForm.rows" :key="i" class="align-top">
                                    <td class="px-2 py-1.5" style="min-width:200px">
                                        <SearchSelect v-model="row.supplier_id" :options="suppliers" placeholder="— supplier —" />
                                        <p v-if="bulkError(i, 'supplier_id')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'supplier_id') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:140px">
                                        <input v-model="row.invoice_number" :class="inputCls" placeholder="No. Invoice" />
                                        <p v-if="bulkError(i, 'invoice_number')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'invoice_number') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:140px">
                                        <input v-model="row.invoice_date" type="date" :class="inputCls" />
                                        <p v-if="bulkError(i, 'invoice_date')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'invoice_date') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:140px">
                                        <input v-model="row.due_date" type="date" :class="inputCls" />
                                        <p v-if="bulkError(i, 'due_date')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'due_date') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:200px">
                                        <SearchSelect v-model="row.payable_account_id" :options="accounts" placeholder="— akun hutang —" />
                                        <p v-if="bulkError(i, 'payable_account_id')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'payable_account_id') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5" style="min-width:130px">
                                        <input v-model.number="row.total" type="number" step="0.01" min="0" :class="[inputCls, 'text-right font-mono']" />
                                        <p v-if="bulkError(i, 'total')" class="mt-1 text-xs text-danger-500">{{ bulkError(i, 'total') }}</p>
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
                    <h2 class="mb-1 flex items-center gap-2 text-lg font-bold"><FileSpreadsheet class="h-5 w-5 text-primary-600" /> Import Saldo Awal Hutang</h2>
                    <p class="mb-4 text-sm text-neutral-500">Unduh template, isi di Excel, lalu unggah kembali. Mendukung file <b>.xlsx</b>, <b>.xls</b>, dan <b>.csv</b>.</p>

                    <div class="mb-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-300">
                        <div class="mb-1 font-semibold uppercase tracking-wide text-neutral-500">Format kolom</div>
                        <ol class="list-decimal space-y-0.5 pl-4">
                            <li><b>Kode Supplier</b> — kode supplier terdaftar (atau nama persis)</li>
                            <li><b>No Invoice</b> — nomor dokumen saldo awal</li>
                            <li><b>Tgl Invoice</b> — format <code>YYYY-MM-DD</code> (mis. 2026-01-01)</li>
                            <li><b>Jatuh Tempo</b> — opsional, <code>YYYY-MM-DD</code></li>
                            <li><b>Kode Akun Hutang</b> — opsional, kode akun liability (kosong → akun hutang default)</li>
                            <li><b>Total</b> — nominal hutang (mis. 1000000)</li>
                            <li><b>Keterangan</b> — opsional</li>
                        </ol>
                        <p class="mt-2 text-neutral-400">Baris duplikat (supplier + no invoice yang sudah ada) otomatis dilewati.</p>
                    </div>

                    <a :href="route('bs.ap.opening.template')" class="mb-4 inline-flex items-center gap-1.5 rounded-lg border border-primary-300 px-3 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300 dark:hover:bg-neutral-800">
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
        <Pagination :data="openings" />
    </div>
</template>
