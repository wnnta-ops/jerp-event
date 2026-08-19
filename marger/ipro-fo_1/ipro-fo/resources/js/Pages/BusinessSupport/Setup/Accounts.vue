<script setup lang="ts">
import { ref, computed } from 'vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, BookOpen, ArrowLeft, Search, Upload, Download, FileSpreadsheet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    accounts: Array<any>;
    types: Record<string, string>;
    cfDirect: Array<{ id: number; code: string; name: string; activity: string }>;
    cfIndirect: Array<{ id: number; code: string; name: string; activity: string }>;
    filters: Record<string, any>;
    stats: { total: number; postable: number };
    levels?: Record<number, string>;
    parentOptions?: Array<{ id: number; code: string; name: string; level: number }>;
}>();

const search = ref(props.filters.search ?? '');
const typeFilter = ref(props.filters.type ?? '');
function display() {
    router.get(route('bs.accounts.index'), { search: search.value || undefined, type: typeFilter.value || undefined }, { preserveState: true, replace: true });
}

const typeColor: Record<string, any> = { asset: 'info', liability: 'warning', equity: 'primary', income: 'success', expense: 'danger' };
const levelName = computed<Record<number, string>>(() => props.levels ?? { 1: 'Kategori', 2: 'Sub Kategori', 3: 'Ledger', 4: 'Detil', 5: 'Detil Transaksi', 6: 'Detil Sub Transaksi' });
const daftarLevel = computed(() => Object.entries(levelName.value).map(([n, label]) => ({ n: Number(n), label })));

const showModal = ref(false);

/* ── Impor Chart of Account ───────────────────────────────────────────────────
   Kolomnya mengikuti lampiran COA apa adanya, sehingga berkas yang sudah ada di tangan
   operator bisa langsung dipakai tanpa disusun ulang.

   Level ditentukan oleh KOLOM level, dan kodenya wajib sepanjang 2 x level - 1 digit —
   aturan yang sama dengan form Tambah Akun, ditegakkan server lewat CoaImporter.
   Seluruh berkas diperiksa dulu: bila ada satu baris pun yang salah, tidak ada yang
   ditulis. */
const showImport = ref(false);
const berkas = ref<File | null>(null);
const imporForm = useForm<any>({ berkas: null });
const KOLOM_IMPOR = [
    { nama: 'level', ket: 'Angka 1–6. Menentukan tingkatan akun.', contoh: '6' },
    { nama: 'kode', ket: 'Penanda unik. Panjangnya harus 2 × level − 1 digit.', contoh: '10101010101' },
    { nama: 'akun', ket: 'Nama akun.', contoh: 'KAS OPERASIONAL' },
    { nama: 'nama_level', ket: 'Nama tingkatan. Boleh kosong; bila diisi harus cocok.', contoh: 'Detil Sub Transaksi' },
    { nama: 'tipe', ket: 'Aktiva / Kewajiban / Ekuitas / Pendapatan / Beban. Kosong = ikut induk.', contoh: 'Aktiva' },
    { nama: 'normal', ket: 'D atau K. Kosong = ikut akun turunannya bila seragam, kalau tidak ikut induk.', contoh: 'D' },
    { nama: 'postable', ket: 'Diabaikan — hanya level 6 yang bisa dijurnal.', contoh: '\u221a' },
    { nama: 'cost_center', ket: 'Diterima tapi tidak disimpan (belum ada kolomnya).', contoh: '' },
    { nama: 'cash_flow_direct', ket: 'KODE kategori cash flow direct. Boleh kosong.', contoh: '' },
    { nama: 'cash_flow_indirect', ket: 'KODE kategori cash flow indirect. Boleh kosong.', contoh: '' },
];
function pilihBerkas(e: Event) { berkas.value = (e.target as HTMLInputElement).files?.[0] ?? null; }
function kirimImpor() {
    if (!berkas.value) return;
    imporForm.berkas = berkas.value;
    imporForm.post(route('bs.accounts.import'), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; berkas.value = null; imporForm.reset(); },
    });
}
const editingId = ref<number | null>(null);
// Induk yang sedang ditambahi anak (dari tombol "+" per baris) — hanya untuk petunjuk di modal.
const parentAcc = ref<any | null>(null);
const form = useForm<any>({ code: '', name: '', level: 1, parent_id: null, type: 'asset', normal_balance: 'D', is_postable: false, is_active: true, cf_direct_category_id: null, cf_indirect_category_id: null });

/** Panjang kode yang dituntut level: tiap turun satu tingkat menambah dua digit. */
const panjangKode = computed(() => 2 * Number(form.level || 1) - 1);
/** Calon induk = akun satu tingkat DI ATAS level yang dipilih. Level 1 tidak punya induk. */
const calonInduk = computed(() => Number(form.level) <= 1
    ? []
    : (props.parentOptions ?? []).filter((a: any) => Number(a.level) === Number(form.level) - 1));
const indukTerpilih = computed(() => (props.parentOptions ?? []).find((a: any) => String(a.id) === String(form.parent_id)) ?? null);

/**
 * Ganti level → isian induk & kode disesuaikan.
 *
 * Induk dibuang bila levelnya tidak lagi cocok: menyisakannya akan mengirim pasangan
 * level/induk yang pasti ditolak server, dan operator harus menebak sendiri mana yang salah.
 * Kode diisi ulang dengan kode induk sebagai awalan supaya tinggal melanjutkan dua digit.
 */
function gantiLevel() {
    if (indukTerpilih.value && Number(indukTerpilih.value.level) !== Number(form.level) - 1) {
        form.parent_id = null;
    }
    // Level 6 (Detil Sub Transaksi) adalah satu-satunya tingkat yang dijurnal; usulkan itu
    // sebagai bawaan, tetapi tetap biarkan bisa diubah — datanya boleh punya perkecualian.
    form.is_postable = Number(form.level) === 6;
    ikutiInduk();
}
/** Kode selalu MELANJUTKAN kode induk — awalannya diisikan, ekornya milik operator. */
function ikutiInduk() {
    const kode = String(indukTerpilih.value?.code ?? '');
    if (! kode) return;
    form.code = String(form.code ?? '').startsWith(kode) ? form.code : kode;
}

function openCreate() {
    editingId.value = null;
    parentAcc.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

/**
 * Tambah COA dari baris — form & endpoint yang SAMA dengan tombol "Tambah Akun" di pojok
 * kanan atas, hanya saja level, induk, dan awalan kodenya sudah terisi dari baris ini.
 * Level 6 sudah paling bawah → tombolnya tidak menawarkan anak.
 */
function openCreateChild(a: any) {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    const bisaBeranak = Number(a.level ?? 6) < 6;
    parentAcc.value = bisaBeranak ? a : null;
    if (bisaBeranak) {
        form.level = Number(a.level) + 1;
        form.parent_id = a.id;
        form.code = a.code;                       // awalan induk — operator melanjutkan 2 digit
        form.type = a.type;                       // anak lazimnya mewarisi tipe & saldo normal induk
        form.normal_balance = a.normal_balance;
        form.is_postable = Number(a.level) + 1 === 6;
    }
    showModal.value = true;
}
function openEdit(a: any) {
    editingId.value = a.id;
    parentAcc.value = null;
    form.clearErrors();
    form.code = a.code; form.name = a.name; form.type = a.type; form.normal_balance = a.normal_balance;
    form.level = Number(a.level ?? 1);
    // parent_id lama bisa kosong pada akun yang dibuat sebelum induknya diwajibkan — dicari
    // ulang dari parent_code supaya form tidak memaksa operator memilih ulang tanpa sebab.
    form.parent_id = a.parent_id
        ?? (props.parentOptions ?? []).find((p: any) => String(p.code) === String(a.parent_code))?.id
        ?? null;
    form.is_postable = !!a.is_postable; form.is_active = !!a.is_active;
    form.cf_direct_category_id = a.cf_direct_category_id ?? null;
    form.cf_indirect_category_id = a.cf_indirect_category_id ?? null;
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.accounts.update', editingId.value), opts) : form.post(route('bs.accounts.store'), opts);
}
function destroy(a: any) {
    if (!confirm(`Hapus akun ${a.code} - ${a.name}?`)) return;
    router.delete(route('bs.accounts.destroy', a.id), { preserveScroll: true });
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><BookOpen class="h-6 w-6 text-primary-600" /> Chart of Account</h1>
                <p class="text-sm text-neutral-500">Struktur 6 level eProperty: Kategori › Sub Kategori › Ledger › Detil › Detil Transaksi › Detil Sub Transaksi (postable). <span class="font-medium">{{ stats.total }} akun · {{ stats.postable }} postable.</span></p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="showImport = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Upload class="h-4 w-4" /> Import</button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Akun</Button>
            </div>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="relative flex-1 min-w-[220px]">
                <Search class="pointer-events-none absolute left-3 top-[34px] h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
                <input v-model="search" type="text" placeholder="Kode / Nama akun" :class="[inputCls,'pl-9']" @keyup.enter="display" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Tipe</label>
                <select v-model="typeFilter" :class="inputCls"><option value="">Semua</option><option v-for="(label, val) in types" :key="val" :value="val">{{ label }}</option></select>
            </div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kode / Akun</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Tipe</th>
                        <th class="px-4 py-3 text-center">Normal</th><th class="px-4 py-3 text-center">Postable</th>
                        <th class="px-4 py-3">Cash Flow Direct</th><th class="px-4 py-3">Cash Flow Indirect</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                    <tr v-for="a in accounts" :key="a.id" :class="!a.is_postable ? 'bg-neutral-50/60 dark:bg-neutral-800/30' : ''">
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2" :style="{ paddingLeft: ((a.level || 6) - 1) * 18 + 'px' }">
                                <span class="font-mono text-xs" :class="a.is_postable ? 'text-neutral-500' : 'font-semibold text-neutral-400'">{{ a.code }}</span>
                                <span :class="a.is_postable ? 'font-medium' : 'font-bold uppercase tracking-wide text-neutral-700 dark:text-neutral-200'">{{ a.name }}</span>
                                <span v-if="!a.is_active" class="rounded bg-neutral-200 px-1.5 text-[10px] text-neutral-500 dark:bg-neutral-700">nonaktif</span>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs text-neutral-400">{{ levelName[a.level] ?? a.level }}</td>
                        <td class="px-4 py-2"><Badge v-if="a.is_postable" :variant="typeColor[a.type] ?? 'neutral'" size="sm">{{ types[a.type] }}</Badge></td>
                        <td class="px-4 py-2 text-center font-mono text-xs">{{ a.is_postable ? a.normal_balance : '' }}</td>
                        <td class="px-4 py-2 text-center">{{ a.is_postable ? '✓' : '—' }}</td>
                        <td class="px-4 py-2 text-xs">
                            <span v-if="a.cf_direct_category" class="inline-flex items-center gap-1 whitespace-nowrap rounded bg-sky-50 px-1.5 py-0.5 text-sky-700 dark:bg-sky-900/25 dark:text-sky-300"><span class="font-mono">{{ a.cf_direct_category.code }}</span> {{ a.cf_direct_category.name }}</span>
                            <span v-else class="text-neutral-300 dark:text-neutral-600">—</span>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <span v-if="a.cf_indirect_category" class="inline-flex items-center gap-1 whitespace-nowrap rounded bg-amber-50 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/25 dark:text-amber-300"><span class="font-mono">{{ a.cf_indirect_category.code }}</span> {{ a.cf_indirect_category.name }}</span>
                            <span v-else class="text-neutral-300 dark:text-neutral-600">—</span>
                        </td>
                        <td class="px-4 py-2"><div class="flex justify-end gap-2">
                            <button @click="openCreateChild(a)" class="text-emerald-600 hover:text-emerald-800"
                                :title="String(a.code).length < 11 ? `Tambah COA di bawah ${a.code} — ${a.name}` : 'Tambah COA baru'"><Plus class="h-4 w-4" /></button>
                            <button @click="openEdit(a)" class="text-primary-600 hover:text-primary-800" title="Edit akun"><Pencil class="h-4 w-4" /></button>
                            <button @click="destroy(a)" class="text-danger-500 hover:text-danger-700" title="Hapus akun"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!accounts?.length" title="Belum ada akun" description="Klik Tambah Akun atau jalankan seeder CoA." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Akun</h2>
                    <p v-if="parentAcc" class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-900/25 dark:text-emerald-300">
                        Menambah COA di bawah <span class="font-mono font-semibold">{{ parentAcc.code }}</span> — {{ parentAcc.name }}.
                        Lanjutkan kode menjadi <span class="font-semibold">{{ String(parentAcc.code).length + 2 }} digit</span>.
                    </p>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Level *</label>
                                <select v-model.number="form.level" :class="inputCls" @change="gantiLevel">
                                    <option v-for="l in daftarLevel" :key="l.n" :value="l.n">{{ l.n }} · {{ l.label }}</option>
                                </select>
                                <p class="mt-1 text-[11px] text-neutral-400">Tingkat 1 paling tinggi, 6 paling rendah.</p>
                                <p v-if="form.errors.level" class="mt-1 text-xs text-danger-500">{{ form.errors.level }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">
                                    Induk<span v-if="form.level > 1"> *</span>
                                </label>
                                <SearchSelect v-if="form.level > 1" v-model="form.parent_id" :options="calonInduk"
                                              placeholder="— pilih induk —" no-truncate @update:model-value="ikutiInduk" />
                                <div v-else class="flex h-9 items-center rounded-lg border border-dashed border-neutral-300 px-3 text-xs text-neutral-400 dark:border-neutral-700">
                                    Level 1 adalah puncak — tanpa induk
                                </div>
                                <p class="mt-1 text-[11px] text-neutral-400">
                                    Harus akun berlevel {{ form.level > 1 ? (form.level - 1) + ' · ' + (levelName[form.level - 1] ?? '') : '—' }}.
                                </p>
                                <p v-if="form.errors.parent_id" class="mt-1 text-xs text-danger-500">{{ form.errors.parent_id }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Kode Akun *</label>
                            <input v-model="form.code" required :class="inputCls" />
                            <p class="mt-1 text-[11px]" :class="String(form.code || '').length === panjangKode ? 'text-neutral-400' : 'text-amber-600'">
                                Level {{ form.level }} ({{ levelName[form.level] }}) → <b>{{ panjangKode }} digit</b>.
                                <template v-if="indukTerpilih">Melanjutkan kode induk <b>{{ indukTerpilih.code }}</b> — tambahkan 2 digit di belakangnya.</template>
                                <span class="ml-1">Terisi {{ String(form.code || '').length }} digit.</span>
                            </p>
                            <p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Akun *</label><input v-model="form.name" required :class="inputCls" /><p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe *</label><select v-model="form.type" :class="inputCls"><option v-for="(label, val) in types" :key="val" :value="val">{{ label }}</option></select></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Saldo Normal *</label><select v-model="form.normal_balance" :class="inputCls"><option value="D">Debit (D)</option><option value="K">Kredit (K)</option></select></div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Cash Flow Direct</label>
                                <select v-model="form.cf_direct_category_id" :class="inputCls">
                                    <option :value="null">— tidak dipetakan —</option>
                                    <option v-for="c in cfDirect" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
                                </select>
                                <p v-if="form.errors.cf_direct_category_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cf_direct_category_id }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Cash Flow Indirect</label>
                                <select v-model="form.cf_indirect_category_id" :class="inputCls">
                                    <option :value="null">— tidak dipetakan —</option>
                                    <option v-for="c in cfIndirect" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
                                </select>
                                <p v-if="form.errors.cf_indirect_category_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cf_indirect_category_id }}</p>
                            </div>
                        </div>
                        <div class="flex gap-5">
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_postable" type="checkbox" class="rounded" /> Bisa dijurnal</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    
        <!-- Impor: formatnya diterangkan DI DALAM dialognya — orang yang sedang mengimpor
             tidak perlu mencari dokumen lain untuk tahu kolom apa saja yang dituntut. -->
        <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showImport = false">
            <div class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <h2 class="mb-1 flex items-center gap-2 text-lg font-bold"><Upload class="h-5 w-5 text-primary-600" /> Import Chart of Account</h2>
                <p class="mb-3 text-sm text-neutral-500">Berkas <b>CSV</b>. Akun dicocokkan lewat <b>kode</b> — yang sudah ada diperbarui, yang belum dibuat. Induk ditentukan dari kodenya sendiri (dua digit terakhir dibuang).</p>

                <div class="mb-3 overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-neutral-50 text-neutral-500 dark:bg-neutral-800"><tr>
                            <th class="px-3 py-2">Kolom</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2">Contoh</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="k in KOLOM_IMPOR" :key="k.nama">
                                <td class="px-3 py-2 font-mono font-semibold">{{ k.nama }}</td>
                                <td class="px-3 py-2 text-neutral-500">{{ k.ket }}</td>
                                <td class="px-3 py-2 font-mono text-neutral-400">{{ k.contoh || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    Panjang kode harus sesuai levelnya (1/3/5/7/9/11 digit) dan induknya harus ada — di berkas yang sama
                    atau sudah di bagan akun. Seluruh berkas diperiksa dulu; bila ada satu baris pun yang salah,
                    <b>tidak ada</b> yang tersimpan.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <a :href="route('bs.accounts.template')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> Unduh Format</a>
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
