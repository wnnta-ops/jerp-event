<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, Tags, Search, Upload, Download, FileSpreadsheet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    categories: Array<any>;
    filters: { search?: string };
    accounts: Array<any>;
    defaultPayable: number | null;
}>();

const search = ref(props.filters.search ?? '');
function applyFilter() { router.get(route('bs.ap.categories.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);

/* ── Impor kategori ──────────────────────────────────────────────────────────
   Berkas CSV berkolom: kode, keterangan, akun_debit, akun_hutang, aktif.
   Akun ditulis sebagai KODE akun, bukan id — kode itulah yang terbaca operator di layar
   maupun di bagan akun, sedangkan id hanya bermakna di dalam basis data ini.
   Server memeriksa SELURUH berkas lebih dulu: bila ada satu baris pun yang salah, tidak
   ada yang ditulis. */
const showImport = ref(false);
const berkas = ref<File | null>(null);
const imporForm = useForm<any>({ berkas: null });
const KOLOM = [
    { nama: 'kode', ket: 'Kode kategori, penanda unik. Yang sudah ada akan DIPERBARUI.', contoh: 'BIAYA-LISTRIK' },
    { nama: 'keterangan', ket: 'Nama kategori yang tampil di layar.', contoh: 'BIAYA LISTRIK KANTOR' },
    { nama: 'akun_debit', ket: 'KODE akun beban/aset yang didebit.', contoh: '50302020217' },
    { nama: 'akun_hutang', ket: 'KODE akun hutang yang dikredit.', contoh: '20101010101' },
    { nama: 'aktif', ket: '1 atau kosong = aktif; 0 / tidak = nonaktif.', contoh: '1' },
];
function pilihBerkas(e: Event) { berkas.value = (e.target as HTMLInputElement).files?.[0] ?? null; }
function kirimImpor() {
    if (!berkas.value) return;
    imporForm.berkas = berkas.value;
    imporForm.post(route('bs.ap.categories.import'), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; berkas.value = null; imporForm.reset(); },
    });
}
const editingId = ref<number | null>(null);
const form = useForm<any>({ code: '', name: '', debit_account_id: '', payable_account_id: props.defaultPayable ?? '', is_active: true });
function openCreate() { editingId.value = null; form.reset(); form.payable_account_id = props.defaultPayable ?? ''; showModal.value = true; }
function openEdit(c: any) {
    editingId.value = c.id;
    Object.assign(form, { code: c.code, name: c.name, debit_account_id: c.debit_account_id, payable_account_id: c.payable_account_id, is_active: !!c.is_active });
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.ap.categories.update', editingId.value), opts) : form.post(route('bs.ap.categories.store'), opts);
}
function destroy(c: any) { if (confirm(`Hapus kategori ${c.code}?`)) router.delete(route('bs.ap.categories.destroy', c.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Tags class="h-6 w-6 text-danger-600" /> Setup Kategori Invoice <span class="text-base font-normal text-neutral-400">({{ categories.length }})</span></h1>
            <div class="flex items-center gap-2">
                <button type="button" @click="showImport = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Upload class="h-4 w-4" /> Import</button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Kategori</Button>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Kategori menentukan akun debit otomatis saat input invoice hutang. Akun hutang biasanya Utang Usaha Supplier.</p>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari kategori…" :class="[inputCls,'pl-9']" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Akun Debit</th><th class="px-4 py-3">Akun Hutang</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in categories" :key="c.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ c.code }}</td>
                        <td class="px-4 py-2.5">{{ c.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ c.debit }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ c.payable }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="c.is_active ? 'success' : 'neutral'" size="sm">{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2"><button @click="openEdit(c)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button><button @click="destroy(c)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!categories.length" title="Belum ada kategori" description="Klik Kategori untuk menambah." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kategori Invoice</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required placeholder="mis. JASA-KONSULTAN" :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Akun Debit *</label>
                            <SearchSelect v-model="form.debit_account_id" :options="accounts" placeholder="Ketik kode / nama akun…" no-truncate />
                            <p v-if="form.errors.debit_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.debit_account_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Akun Hutang (Kredit) *</label>
                            <SearchSelect v-model="form.payable_account_id" :options="accounts" placeholder="Ketik kode / nama akun…" no-truncate />
                            <p v-if="form.errors.payable_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.payable_account_id }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        <div class="flex justify-end gap-2 pt-1"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Simpan</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    
        <!-- Impor: formatnya diterangkan DI DALAM dialognya, bukan di dokumen terpisah —
             orang yang sedang mengimpor tidak perlu mencari-cari kolom apa saja yang dituntut. -->
        <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showImport = false">
            <div class="w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <h2 class="mb-1 flex items-center gap-2 text-lg font-bold"><Upload class="h-5 w-5 text-primary-600" /> Import Kategori Invoice</h2>
                <p class="mb-3 text-sm text-neutral-500">Berkas <b>CSV</b>. Kategori dicocokkan lewat <b>kode</b> — yang sudah ada diperbarui, yang belum dibuat.</p>

                <div class="mb-3 overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-neutral-50 text-neutral-500 dark:bg-neutral-800"><tr>
                            <th class="px-3 py-2">Kolom</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2">Contoh</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="k in KOLOM" :key="k.nama">
                                <td class="px-3 py-2 font-mono font-semibold">{{ k.nama }}</td>
                                <td class="px-3 py-2 text-neutral-500">{{ k.ket }}</td>
                                <td class="px-3 py-2 font-mono text-neutral-400">{{ k.contoh }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    Seluruh berkas diperiksa dulu. Bila ada satu baris pun yang salah, <b>tidak ada</b> yang tersimpan —
                    perbaiki berkasnya lalu unggah ulang.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <a :href="route('bs.ap.categories.template')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> Unduh Format</a>
                    <label class="inline-flex h-9 flex-1 cursor-pointer items-center gap-2 rounded-lg border border-dashed border-neutral-300 px-3 text-sm text-neutral-500 hover:bg-neutral-50 dark:border-neutral-700">
                        <FileSpreadsheet class="h-4 w-4" />
                        <span>{{ berkas ? berkas.name : 'Pilih berkas CSV…' }}</span>
                        <input type="file" accept=".csv,text/csv" class="hidden" @change="pilihBerkas" />
                    </label>
                </div>
                <p v-if="imporForm.errors.berkas" class="mt-1 text-xs text-danger-500">{{ imporForm.errors.berkas }}</p>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="showImport = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                    <Button :disabled="!berkas || imporForm.processing" @click="kirimImpor">{{ imporForm.processing ? 'Mengimpor…' : 'Import' }}</Button>
                </div>
            </div>
        </div>
    </div>
</template>
