<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save, Landmark, TriangleAlert, CheckCircle2, Wand2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Acc = { id: number; code: string; name: string; is_bank: boolean };
const props = defineProps<{
    bank: any | null;
    projects: Array<{ id: number; name: string }>;
    categories: Array<{ id: number; code: string; name: string; percentage: number; amount: number | null; notes: string | null; enabled: boolean; gl_account_id: number | null }>;
    accounts: Acc[];
}>();

const isEdit = !!props.bank;
const tab = ref<'info' | 'retensi'>('info');

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

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Save</Button>
                <Link :href="route('kpr.setup.bank.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Cancel</Link>
            </div>
        </form>
    </div>
</template>
