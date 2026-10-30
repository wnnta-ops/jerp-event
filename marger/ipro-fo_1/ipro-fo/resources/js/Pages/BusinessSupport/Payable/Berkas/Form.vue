<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type FileRow = { id: number; name: string; jenis: string | null; size: number; mime: string; url: string };
const props = defineProps<{
    today: string;
    jenisLabels: Record<string, string>;
    suppliers: Array<{ id: number; name: string }>;
    jenisBerkas: Array<{ id: number; code: string; name: string; is_mandatory: boolean }>;
    deptHeads: Array<{ id: number; name: string }>;
    costCenters: Array<{ id: number; code: string; name: string }>;
    accounts: Array<{ id: number; code: string; name: string }>;
    maxFiles: number;
    /** null = unggah baru; isi = edit draft / revisi setelah ditolak. */
    berkas: null | { id: number; number: string; status: string; files: FileRow[]; form: Record<string, any> };
}>();

const edit = !!props.berkas;
const awal = props.berkas?.form ?? {};
const form = useForm({
    upload_date: props.today,
    jenis: awal.jenis ?? '',
    document_date: awal.document_date ?? '',
    needed_date: awal.needed_date ?? '',
    supplier_id: awal.supplier_id ?? '',
    supplier_invoice_no: awal.supplier_invoice_no ?? '',
    description: awal.description ?? '',
    dept_head_id: awal.dept_head_id ?? '',
    cost_center_id: awal.cost_center_id ?? null,
    expense_account_id: awal.expense_account_id ?? null,
    subtotal: (awal.subtotal ?? awal.amount ?? null) as number | null,
    discount: Number(awal.discount ?? 0),
    ppn_pct: Number(awal.ppn_pct ?? 0),
    tax_inclusive: !!awal.tax_inclusive,
    files: [] as Array<{ file: File; berkas_id: number | '' }>,
    ajukan: false,
});

// Sama persis dengan ApBerkasService::hitungTotal — server tetap menghitung ulang.
const hitung = computed(() => {
    const sub = form.subtotal == null ? null : Number(form.subtotal);
    if (sub == null) return { ppn: 0, total: null as number | null };
    const net = Math.max(0, sub - Number(form.discount || 0));
    const pct = Number(form.ppn_pct || 0);
    if (form.tax_inclusive) return { ppn: Math.round((net * pct) / (100 + pct)), total: net };
    const ppn = Math.round((net * pct) / 100);
    return { ppn, total: net + ppn };
});

// Checklist berkas wajib: jenis wajib yang sudah tercakup file lama + file baru.
const jenisAda = computed(() => {
    const lama = (props.berkas?.files ?? []).map((f) => f.jenis);
    const baru = form.files.map((f) => props.jenisBerkas.find((j) => j.id === f.berkas_id)?.name ?? null);
    return new Set([...lama, ...baru].filter(Boolean));
});
const wajib = computed(() => props.jenisBerkas.filter((j) => j.is_mandatory).map((j) => ({ name: j.name, ok: jenisAda.value.has(j.name) })));
const jumlahFile = computed(() => (props.berkas?.files.length ?? 0) + form.files.length);

function tambahFile(e: Event) {
    const input = e.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        if (jumlahFile.value >= props.maxFiles) break;
        const belum = props.jenisBerkas.find((j) => j.is_mandatory && !jenisAda.value.has(j.name));
        form.files.push({ file, berkas_id: belum?.id ?? props.jenisBerkas[0]?.id ?? '' });
    }
    input.value = '';
}
const hapusFileLama = (f: FileRow) => router.delete(route('bs.ap.berkas.file.destroy', [props.berkas!.id, f.id]), { preserveScroll: true, preserveState: true });

function simpan(ajukan: boolean) {
    form.ajukan = ajukan;
    form.post(edit ? route('bs.ap.berkas.update', props.berkas!.id) : route('bs.ap.berkas.store'), { forceFormData: true, preserveScroll: true });
}

const rupiah = (v: number) => 'Rp ' + Math.round(v).toLocaleString('id-ID');
const ukuran = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');
const galatFile = (i: number) => (form.errors as Record<string, string>)[`files.${i}.file`];
</script>

<template>
    <Head :title="edit ? `Edit ${berkas!.number}` : 'Unggah Berkas'" />
    <div class="mx-auto max-w-3xl space-y-4">
        <Link :href="edit ? route('bs.ap.berkas.show', berkas!.id) : route('bs.ap.berkas.index', { tab: 'saya' })" class="text-sm text-neutral-500 hover:text-primary-600">← {{ edit ? berkas!.number : 'Upload Berkas' }}</Link>
        <div>
            <h1 class="text-2xl font-bold">{{ edit ? (berkas!.status === 'ditolak' ? 'Revisi Berkas' : 'Edit Berkas') : 'Unggah Berkas' }}</h1>
            <p class="text-sm text-neutral-500">Simpan sebagai draft dulu, atau langsung ajukan ke Dept Head untuk ditandatangani. Setelah TTD, tim AP membuat Invoice Hutang dari berkas ini.</p>
        </div>

        <form @submit.prevent="simpan(true)" class="card space-y-5 p-6">
            <!-- Identitas -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label-base" for="jenis">Jenis transaksi</label>
                    <select id="jenis" v-model="form.jenis" class="input-base">
                        <option value="">— Pilih —</option>
                        <option v-for="(l, k) in jenisLabels" :key="k" :value="k">{{ l }}</option>
                    </select>
                </div>
                <Input v-model="form.document_date" label="Tanggal dokumen / tagihan" type="date" :error="form.errors.document_date" />
                <Input v-model="form.needed_date" label="Rencana bayar" type="date" :error="form.errors.needed_date" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label-base" for="dept-head">Dept Head penanda tangan <span class="text-red-500">*</span></label>
                    <select id="dept-head" v-model="form.dept_head_id" class="input-base">
                        <option value="" disabled>Pilih Dept Head…</option>
                        <option v-for="d in deptHeads" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                    <p v-if="form.errors.dept_head_id" class="mt-1 text-xs text-red-600">{{ form.errors.dept_head_id }}</p>
                </div>
                <div>
                    <label class="label-base" for="supplier">Supplier</label>
                    <select id="supplier" v-model="form.supplier_id" class="input-base">
                        <option value="">— Belum tahu / diisi tim AP —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <Input v-model="form.supplier_invoice_no" label="No. tagihan supplier" :error="form.errors.supplier_invoice_no" />
                <div class="sm:col-span-2"><Input v-model="form.description" label="Keterangan" placeholder="mis. Instalasi listrik booth pameran November" required :error="form.errors.description" /></div>
            </div>

            <!-- Anggaran -->
            <div class="grid gap-4 border-t border-neutral-200 pt-4 sm:grid-cols-2 dark:border-neutral-800">
                <div>
                    <label class="label-base">Cost center</label>
                    <SearchSelect v-model="form.cost_center_id" :options="costCenters" placeholder="Pilih cost center…" clearable />
                </div>
                <div>
                    <label class="label-base">Akun beban</label>
                    <SearchSelect v-model="form.expense_account_id" :options="accounts" placeholder="Pilih akun beban…" clearable />
                </div>
                <p class="text-xs text-neutral-500 sm:col-span-2">Bila cost center, akun beban, dan nilai terisi, sisa anggaran dicek saat diajukan — berkas yang melebihi anggaran tidak bisa diajukan.</p>
            </div>

            <!-- Nilai -->
            <div class="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><label class="label-base">Subtotal</label><MoneyInput v-model="form.subtotal" placeholder="Nilai tagihan" /></div>
                    <div><label class="label-base">Diskon</label><MoneyInput v-model="form.discount" placeholder="0" /></div>
                    <div>
                        <label class="label-base" for="ppn">PPN %</label>
                        <div class="flex gap-1">
                            <input id="ppn" v-model.number="form.ppn_pct" type="number" min="0" max="100" step="0.01" class="input-base" />
                            <button v-for="p in [0, 11, 12]" :key="p" type="button" class="rounded-md border border-neutral-200 px-2 text-xs dark:border-neutral-700" @click="form.ppn_pct = p">{{ p }}</button>
                        </div>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.tax_inclusive" type="checkbox" /> Harga sudah termasuk PPN</label>
                <p v-if="form.errors.discount || form.errors.subtotal" class="text-xs text-red-600">{{ form.errors.discount || form.errors.subtotal }}</p>
                <dl class="grid grid-cols-3 gap-3 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800/50">
                    <div><dt class="text-xs text-neutral-500">Setelah diskon</dt><dd class="font-medium tabular-nums">{{ form.subtotal == null ? '—' : rupiah(Math.max(0, Number(form.subtotal) - Number(form.discount || 0))) }}</dd></div>
                    <div><dt class="text-xs text-neutral-500">PPN{{ form.tax_inclusive ? ' (informasi)' : '' }}</dt><dd class="font-medium tabular-nums">{{ rupiah(hitung.ppn) }}</dd></div>
                    <div><dt class="text-xs text-neutral-500">Total tagihan</dt><dd class="text-base font-bold tabular-nums">{{ hitung.total == null ? '—' : rupiah(hitung.total) }}</dd></div>
                </dl>
            </div>

            <!-- File -->
            <div class="space-y-2 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label class="label-base">File berkas <span class="font-normal text-neutral-500">— PDF / JPG / PNG, maks. 10 MB, maks. {{ maxFiles }} file</span></label>
                    <span class="text-xs text-neutral-500">{{ jumlahFile }}/{{ maxFiles }}</span>
                </div>
                <ul v-if="wajib.length" class="flex flex-wrap gap-2 text-xs" aria-label="Berkas wajib">
                    <li v-for="w in wajib" :key="w.name" :class="['rounded-full px-2 py-0.5', w.ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300']">
                        {{ w.ok ? '✓' : '○' }} {{ w.name }} (wajib)
                    </li>
                </ul>
                <div v-for="f in berkas?.files ?? []" :key="'l' + f.id" class="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 p-2 text-sm dark:border-neutral-800">
                    <a :href="f.url" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-primary-700 hover:underline dark:text-primary-300">📎 {{ f.name }}</a>
                    <span class="text-xs text-neutral-500">{{ f.jenis ?? 'Jenis lain' }} · {{ ukuran(f.size) }}</span>
                    <button type="button" class="text-xs text-red-600 hover:underline" @click="hapusFileLama(f)">Hapus</button>
                </div>
                <div v-for="(f, i) in form.files" :key="'b' + i" class="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-primary-300 p-2 dark:border-primary-800">
                    <span class="min-w-0 flex-1 truncate text-sm">➕ {{ f.file.name }} <span class="text-xs text-neutral-500">({{ ukuran(f.file.size) }})</span></span>
                    <select v-model="f.berkas_id" class="input-base h-9 w-auto text-xs" :aria-label="'Jenis ' + f.file.name">
                        <option value="">Jenis lain</option>
                        <option v-for="j in jenisBerkas" :key="j.id" :value="j.id">{{ j.name }}{{ j.is_mandatory ? ' (wajib)' : '' }}</option>
                    </select>
                    <button type="button" class="text-xs text-red-600 hover:underline" @click="form.files.splice(i, 1)">Batal</button>
                    <p v-if="galatFile(i)" class="w-full text-xs text-red-600">{{ galatFile(i) }}</p>
                </div>
                <input v-if="jumlahFile < maxFiles" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="text-sm" @change="tambahFile" />
                <p v-if="form.errors.files" class="text-xs text-red-600">{{ form.errors.files }}</p>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <Link :href="edit ? route('bs.ap.berkas.show', berkas!.id) : route('bs.ap.berkas.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="button" variant="outline" :loading="form.processing && !form.ajukan" @click="simpan(false)">Simpan Draft</Button>
                <Button type="submit" :loading="form.processing && form.ajukan" :disabled="!jumlahFile || wajib.some((w) => !w.ok)">
                    {{ berkas?.status === 'ditolak' ? 'Simpan & Ajukan Ulang' : 'Simpan & Ajukan' }}
                </Button>
            </div>
            <p v-if="wajib.some((w) => !w.ok)" class="text-right text-xs text-amber-700">Lengkapi berkas wajib untuk mengajukan — draft tetap bisa disimpan.</p>
        </form>
    </div>
</template>
