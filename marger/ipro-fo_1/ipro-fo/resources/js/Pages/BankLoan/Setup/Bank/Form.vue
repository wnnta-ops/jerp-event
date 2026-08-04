<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save, Landmark, TriangleAlert, CheckCircle2, Wand2, Upload, FileText, Eye, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Acc = { id: number; code: string; name: string; is_bank: boolean };
const props = defineProps<{
    bank: any | null;
    projects: Array<{ id: number; name: string }>;
    categories: Array<{ id: number; code: string; name: string; percentage: number; amount: number | null; notes: string | null; enabled: boolean; gl_account_id: number | null }>;
    accounts: Acc[];
    // Format SI (Surat Instruksi) per bank.
    siFormats: Array<{ id: string; label: string }>;
    siPlaceholders: Array<{ key: string; desc: string }>;
}>();

const isEdit = !!props.bank;
const tab = ref<'info' | 'retensi' | 'si'>('info');

const form = useForm({
    project_id: props.bank?.project_id ?? '',
    code: props.bank?.code ?? '',
    name: props.bank?.name ?? '',
    branch: props.bank?.branch ?? '',
    account_no: props.bank?.account_no ?? '',
    account_name: props.bank?.account_name ?? '',
    address: props.bank?.address ?? '',
    city: props.bank?.city ?? '',
    notes: props.bank?.notes ?? '',
    contact_person: props.bank?.contact_person ?? '',
    phone: props.bank?.phone ?? '',
    email: props.bank?.email ?? '',
    is_active: props.bank ? !!props.bank.is_active : true,
    si_format: props.bank?.si_format ?? '',
    si_template: props.bank?.si_template ?? '',
    retentions: props.categories.map(c => ({
        category_id: c.id,
        code: c.code,
        name: c.name,
        enabled: c.enabled,
        percentage: c.percentage,
        amount: c.amount,
        notes: c.notes,
        gl_account_id: c.gl_account_id ?? null,
    })),
});

function submit() {
    if (isEdit) form.put(route('kpr.setup.bank.update', props.bank.id));
    else form.post(route('kpr.setup.bank.store'));
}

// ── COA Bank per kategori ────────────────────────────────────────────────
// Akun yang dipilih di sini menjadi DEFAULT akun Debit saat pencairan/retensi kategori
// bersangkutan masuk ke Approval Retensi (Cash Book) — operator tinggal periksa & setujui.

// Akun bertema Kas/Bank didahulukan di daftar agar mudah ditemukan; sisanya tetap tersedia.
const accountOptions = computed(() => [
    ...props.accounts.filter(a => a.is_bank),
    ...props.accounts.filter(a => !a.is_bank),
]);
const accountById = computed(() => new Map(props.accounts.map(a => [a.id, a])));
function accLabel(id: number | null) {
    const a = id != null ? accountById.value.get(id) : null;
    return a ? `${a.code} - ${a.name}` : null;
}

const usedRows = computed(() => form.retentions.filter(r => r.enabled));
const withCoa = computed(() => usedRows.value.filter(r => r.gl_account_id).length);
const missingCoa = computed(() => usedRows.value.length - withCoa.value);

// Terapkan satu COA ke semua kategori yang dicentang — kebanyakan bank memakai satu
// rekening untuk seluruh tahap, jadi tidak perlu memilih berulang.
const bulkAccount = ref<any>('');
function applyBulk() {
    if (!bulkAccount.value) return;
    for (const r of form.retentions) {
        if (r.enabled) r.gl_account_id = Number(bulkAccount.value);
    }
}

// ── Format SI (Surat Instruksi) ──────────────────────────────────────────
// Format bawaan dipakai saat kotak sunting kosong. Begitu diisi (impor berkas / ketik sendiri),
// format suntingan itulah yang tercetak — bawaan hanya jadi titik awal.
const siFileInput = ref<HTMLInputElement | null>(null);
const siNotice = ref('');
const siCustom = computed(() => String(form.si_template ?? '').trim() !== '');
const siTokenCount = computed(() => (String(form.si_template ?? '').match(/\{\{\s*[a-z_]+\s*\}\}/gi) ?? []).length);
// Placeholder yang ditulis operator tapi tidak dikenal — biar salah ketik ketahuan sejak di form.
const siUnknownTokens = computed(() => {
    const known = new Set(props.siPlaceholders.map(p => p.key));
    const found = String(form.si_template ?? '').match(/\{\{\s*([a-z_]+)\s*\}\}/gi) ?? [];
    return Array.from(new Set(found.map(t => t.replace(/[{}\s]/g, '')).filter(t => !known.has(t))));
});

function importSiFile(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
        form.si_template = String(reader.result ?? '');
        siNotice.value = `Format diimpor dari ${file.name}.`;
        if (siFileInput.value) siFileInput.value.value = '';
    };
    reader.readAsText(file);
}
async function muatContohFormat() {
    if (siCustom.value && !confirm('Ganti isi format saat ini dengan contoh format?')) return;
    const res = await fetch(route('kpr.setup.bank.si-starter'), { headers: { Accept: 'text/plain' } });
    form.si_template = await res.text();
    siNotice.value = 'Contoh format dimuat — silakan sesuaikan.';
}
function kosongkanFormat() {
    if (!confirm('Kosongkan format suntingan? Cetak SI akan kembali memakai format bawaan.')) return;
    form.si_template = '';
    siNotice.value = 'Format suntingan dikosongkan — kembali memakai format bawaan.';
}
// Pratinjau dibuka di tab baru: perlu POST (isi format bisa panjang) → form tersembunyi.
function pratinjauSi() {
    if (!isEdit) { alert('Simpan banknya dulu sebelum bisa dipratinjau.'); return; }
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = route('kpr.setup.bank.si-preview', props.bank.id);
    f.target = '_blank';
    f.style.display = 'none';
    const add = (n: string, v: string) => { const el = document.createElement('input'); el.type = 'hidden'; el.name = n; el.value = v; f.appendChild(el); };
    add('_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '');
    add('si_template', String(form.si_template ?? ''));
    document.body.appendChild(f);
    f.submit();
    window.setTimeout(() => f.remove(), 2000);
}
function sisipkanToken(key: string) {
    form.si_template = String(form.si_template ?? '') + `{{${key}}}`;
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <!-- Tab Retensi butuh ruang lebih (kolom COA); tab identitas tetap ramping. -->
    <div class="space-y-4 transition-[max-width] duration-300" :class="tab === 'retensi' ? 'max-w-7xl' : 'max-w-3xl'">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ isEdit ? 'Edit' : 'Tambah' }} Bank</h1>
            <Link :href="route('kpr.setup.bank.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                <ArrowLeft class="h-4 w-4" /> Kembali
            </Link>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-800">
            <button @click="tab = 'info'" :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px', tab === 'info' ? 'border-primary-600 text-primary-600' : 'border-transparent text-neutral-500']">Basic Information</button>
            <button @click="tab = 'retensi'" :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px', tab === 'retensi' ? 'border-primary-600 text-primary-600' : 'border-transparent text-neutral-500']">Retensi</button>
            <button @click="tab = 'si'" :class="['inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px', tab === 'si' ? 'border-primary-600 text-primary-600' : 'border-transparent text-neutral-500']">
                Format SI
                <span v-if="siCustom" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">custom</span>
                <span v-else-if="form.si_format" class="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ form.si_format }}</span>
                <span v-else class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">belum</span>
            </button>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Basic Information -->
            <div v-show="tab === 'info'" class="card space-y-4 p-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
                        <select v-model="form.project_id" :class="inputCls">
                            <option value="">— (semua project) —</option>
                            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kode Bank *</label>
                        <input v-model="form.code" type="text" required :class="inputCls" />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label>
                        <input v-model="form.name" type="text" required :class="inputCls" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Cabang</label>
                        <input v-model="form.branch" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">No. Rekening</label>
                        <input v-model="form.account_no" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Atas Nama</label>
                        <input v-model="form.account_name" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kota</label>
                        <input v-model="form.city" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label>
                        <input v-model="form.notes" type="text" maxlength="255" :class="inputCls" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Alamat</label>
                        <textarea v-model="form.address" rows="2" maxlength="255" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kontak / PIC</label>
                        <input v-model="form.contact_person" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Telepon</label>
                        <input v-model="form.phone" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Email</label>
                        <input v-model="form.email" type="email" :class="inputCls" />
                    </div>
                    <label class="mt-6 flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif
                    </label>
                </div>
            </div>

            <!-- Retensi -->
            <div v-show="tab === 'retensi'" class="card p-5">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-semibold">Ketentuan Retensi per Kategori</h2>
                        <p class="text-sm text-neutral-500">Centang kategori yang berlaku untuk bank ini, isi persentase retensinya, dan pilih <b>COA Bank</b> yang di-link-kan.</p>
                    </div>
                    <!-- Ringkasan cepat kesiapan COA -->
                    <div v-if="form.retentions.length" class="flex shrink-0 flex-wrap gap-1.5">
                        <span class="inline-flex items-center gap-1 rounded-md bg-neutral-100 px-2 py-1 text-[11px] font-semibold text-neutral-600 ring-1 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-700">
                            {{ usedRows.length }} kategori dipakai
                        </span>
                        <span v-if="withCoa" class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800/60">
                            <CheckCircle2 class="h-3 w-3" /> {{ withCoa }} ber-COA
                        </span>
                        <span v-if="missingCoa > 0" class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60">
                            <TriangleAlert class="h-3 w-3" /> {{ missingCoa }} belum ber-COA
                        </span>
                    </div>
                </div>

                <div v-if="!form.retentions.length" class="text-sm text-neutral-500">
                    Belum ada Kategori Retensi. Tambahkan dulu di menu <strong>Kategori Retensi</strong>.
                </div>

                <template v-else>
                    <!-- Keterangan + terapkan sekaligus -->
                    <div class="mb-3 rounded-xl bg-primary-50/60 p-3 ring-1 ring-primary-100 dark:bg-primary-950/25 dark:ring-primary-900/50">
                        <div class="flex items-start gap-2.5">
                            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-100 text-primary-600 dark:bg-primary-900/60 dark:text-primary-300"><Landmark class="h-4 w-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs leading-relaxed text-neutral-600 dark:text-neutral-300">
                                    COA Bank yang dipilih di sini menjadi <b>akun Debit otomatis</b> saat pencairan/retensi kategori tersebut masuk ke <b>Approval Retensi</b> (Cash Book) — operator tinggal memeriksa lalu menyetujui.
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <div class="w-full sm:w-80"><SearchSelect v-model="bulkAccount" :options="accountOptions" placeholder="Pilih COA untuk semua kategori…" /></div>
                                    <button type="button" @click="applyBulk" :disabled="!bulkAccount || !usedRows.length"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary-300 bg-white px-3 text-xs font-semibold text-primary-700 transition-colors duration-200 hover:bg-primary-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-primary-800 dark:bg-neutral-900 dark:text-primary-300 dark:hover:bg-primary-950/40">
                                        <Wand2 class="h-3.5 w-3.5" /> Terapkan ke semua yang dicentang
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[62rem] text-sm">
                            <thead class="bg-neutral-50 dark:bg-neutral-800">
                                <tr class="text-left text-xs uppercase text-neutral-500">
                                    <th class="px-3 py-2 w-12">Pakai</th>
                                    <th class="px-3 py-2 w-56">Kategori</th>
                                    <th class="px-3 py-2 w-28 text-right">Retensi (%)</th>
                                    <th class="px-3 py-2 w-40 text-right">Nominal (opsional)</th>
                                    <th class="px-3 py-2 w-80">COA Bank (akun Debit)</th>
                                    <th class="px-3 py-2">Catatan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 align-top dark:divide-neutral-800">
                                <tr v-for="r in form.retentions" :key="r.category_id"
                                    class="transition-colors duration-200"
                                    :class="r.enabled ? 'bg-primary-50/40 dark:bg-primary-950/15' : ''">
                                    <td class="px-3 py-2.5 text-center"><input v-model="r.enabled" type="checkbox" class="rounded" /></td>
                                    <td class="px-3 py-2.5">
                                        <div class="font-semibold" :class="r.enabled ? '' : 'text-neutral-400'">{{ r.code }}</div>
                                        <div class="text-xs text-neutral-400">{{ r.name }}</div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <input v-model.number="r.percentage" type="number" step="0.01" min="0" max="100" :disabled="!r.enabled" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-2 text-right text-sm disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900" />
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <input v-model.number="r.amount" type="number" step="1" min="0" :disabled="!r.enabled" placeholder="—" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-2 text-right text-sm disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900" />
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <SearchSelect v-model="r.gl_account_id" :options="accountOptions" :disabled="!r.enabled" placeholder="Cari COA bank…" />
                                        <p v-if="r.enabled && r.gl_account_id" class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                            <CheckCircle2 class="h-3 w-3 shrink-0" /> Debit otomatis: {{ accLabel(r.gl_account_id) }}
                                        </p>
                                        <p v-else-if="r.enabled" class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                            <TriangleAlert class="h-3 w-3 shrink-0" /> Belum di-link — akun Debit ditebak dari nama/nomor rekening.
                                        </p>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <input v-model="r.notes" type="text" :disabled="!r.enabled" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-2 text-sm disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>

            <!-- Format SI (Surat Instruksi) -->
            <div v-show="tab === 'si'" class="card space-y-4 p-5">
                <div>
                    <h2 class="font-semibold">Format SI (Surat Instruksi)</h2>
                    <p class="text-sm text-neutral-500">Menentukan surat yang keluar saat <b>Cetak SI</b> pada tahap <b>SI</b> di Proses KPR — otomatis mengikuti bank pada KPR ybs.</p>
                </div>

                <!-- Format bawaan -->
                <div class="rounded-xl p-3 ring-1 ring-neutral-200 dark:ring-neutral-700">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-neutral-500">1 · Format Bawaan</label>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="f in siFormats" :key="f.id" type="button" @click="form.si_format = form.si_format === f.id ? '' : f.id"
                            class="rounded-lg border px-3 py-2 text-sm font-semibold transition-all duration-200"
                            :class="form.si_format === f.id
                                ? 'border-primary-500 bg-primary-50 text-primary-700 ring-1 ring-primary-500 dark:bg-primary-950/40 dark:text-primary-300'
                                : 'border-neutral-200 text-neutral-600 hover:border-primary-300 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'">
                            {{ f.label }}
                        </button>
                    </div>
                    <p class="mt-1.5 text-[11px] text-neutral-500">Surat siap pakai bawaan sistem. Dipakai selama kotak sunting di bawah masih kosong.</p>
                    <p v-if="!form.si_format && !siCustom" class="mt-1.5 inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60">
                        <TriangleAlert class="h-3 w-3 shrink-0" /> Belum ada format — Cetak SI untuk bank ini akan memakai surat SI generik.
                    </p>
                </div>

                <!-- Format suntingan -->
                <div class="rounded-xl p-3 ring-1" :class="siCustom ? 'ring-emerald-300 dark:ring-emerald-800/60' : 'ring-neutral-200 dark:ring-neutral-700'">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-neutral-500">2 · Format Suntingan (impor / ketik sendiri)</label>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" @click="siFileInput?.click()" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-2.5 text-xs font-semibold text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"><Upload class="h-3.5 w-3.5" /> Impor Berkas</button>
                            <button type="button" @click="muatContohFormat" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-2.5 text-xs font-semibold text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"><FileText class="h-3.5 w-3.5" /> Muat Contoh</button>
                            <button type="button" @click="pratinjauSi" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-primary-300 bg-white px-2.5 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-800 dark:bg-neutral-900 dark:text-primary-300"><Eye class="h-3.5 w-3.5" /> Pratinjau</button>
                            <button v-if="siCustom" type="button" @click="kosongkanFormat" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-danger-300 bg-white px-2.5 text-xs font-semibold text-danger-600 hover:bg-danger-50 dark:border-danger-800 dark:bg-neutral-900"><X class="h-3.5 w-3.5" /> Kosongkan</button>
                        </div>
                    </div>
                    <input ref="siFileInput" type="file" accept=".html,.htm,.txt" class="hidden" @change="importSiFile" />
                    <textarea v-model="form.si_template" rows="14" spellcheck="false"
                        placeholder="Tempel/impor HTML surat di sini. Kosongkan untuk memakai format bawaan."
                        class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 font-mono text-xs leading-relaxed dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
                        <span :class="siCustom ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-neutral-400'">
                            {{ siCustom ? `Aktif — ${siTokenCount} placeholder terpakai` : 'Kosong — memakai format bawaan' }}
                        </span>
                        <span v-if="siNotice" class="text-neutral-500">{{ siNotice }}</span>
                        <span v-if="siUnknownTokens.length" class="font-semibold text-danger-600">Placeholder tak dikenal: {{ siUnknownTokens.join(', ') }}</span>
                    </div>
                    <p v-if="form.errors.si_template" class="mt-1 text-xs text-danger-500">{{ form.errors.si_template }}</p>
                </div>

                <!-- Daftar placeholder -->
                <div class="rounded-xl p-3 ring-1 ring-neutral-200 dark:ring-neutral-700">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-neutral-500">3 · Placeholder Tersedia</label>
                    <p class="mb-2 text-[11px] text-neutral-500">Klik untuk menyisipkan ke akhir format. Ditulis <code class="rounded bg-neutral-100 px-1 dark:bg-neutral-800">&#123;&#123;nama&#125;&#125;</code>, diganti otomatis saat dicetak.</p>
                    <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                        <button v-for="p in siPlaceholders" :key="p.key" type="button" @click="sisipkanToken(p.key)"
                            class="rounded-lg border border-neutral-200 px-2 py-1.5 text-left transition-colors duration-200 hover:border-primary-300 hover:bg-primary-50 dark:border-neutral-700 dark:hover:bg-primary-950/30">
                            <div class="font-mono text-[11px] font-bold text-primary-700 dark:text-primary-300">&#123;&#123;{{ p.key }}&#125;&#125;</div>
                            <div class="text-[10.5px] leading-snug text-neutral-500">{{ p.desc }}</div>
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Save</Button>
                <Link :href="route('kpr.setup.bank.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Cancel</Link>
            </div>
        </form>
    </div>
</template>
