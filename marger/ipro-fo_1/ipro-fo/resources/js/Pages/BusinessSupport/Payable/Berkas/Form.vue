<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

type FileRow = { id: number; name: string; jenis: string | null; size: number; mime: string; url: string };
const props = defineProps<{
    today: string;
    suppliers: Array<{ id: number; name: string; code: string }>;
    jenisBerkas: Array<{ id: number; code: string; name: string; is_mandatory: boolean }>;
    /** Atasan langsung pengunggah di Employee Center; null = belum diatur. */
    deptHead: { id: number; name: string } | null;
    maxFiles: number;
    /** null = unggah baru; isi = edit draft / revisi setelah ditolak. */
    berkas: null | { id: number; number: string; status: string; files: FileRow[]; form: Record<string, any> };
}>();

const edit = !!props.berkas;
const awal = props.berkas?.form ?? {};
const form = useForm({
    upload_date: props.today,
    document_date: awal.document_date ?? props.today,
    needed_date: awal.needed_date ?? '',
    supplier_id: awal.supplier_id ?? '',
    supplier_invoice_no: awal.supplier_invoice_no ?? '',
    description: awal.description ?? '',
    amount: (awal.amount ?? null) as number | null,
    files: [] as Array<{ file: File; berkas_id: number | '' }>,
    ajukan: false,
});

// Pratinjau nomor — format sama dengan ApBerkasService::nextNumber; urutan akhirnya ditentukan server.
const pratinjauNomor = computed(() => {
    const tgl = (form.document_date || props.today).replaceAll('-', '');
    const kode = (props.suppliers.find((s) => s.id === Number(form.supplier_id))?.code ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 16) || 'NA';
    return `DOC-${tgl}-${kode}-`;
});

// Checklist berkas: jenis yang sudah tercakup file lama + file baru (wajib menahan pengajuan).
const jenisAda = computed(() => {
    const lama = (props.berkas?.files ?? []).map((f) => f.jenis);
    const baru = form.files.map((f) => props.jenisBerkas.find((j) => j.id === f.berkas_id)?.name ?? null);
    return new Set([...lama, ...baru].filter(Boolean));
});
const checklist = computed(() => props.jenisBerkas.map((j) => ({ name: j.name, wajib: j.is_mandatory, ok: jenisAda.value.has(j.name) })));
const wajib = computed(() => checklist.value.filter((c) => c.wajib));
const jumlahFile = computed(() => (props.berkas?.files.length ?? 0) + form.files.length);

function tambahFile(e: Event) {
    const input = e.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        if (jumlahFile.value >= props.maxFiles) break;
        const belum = props.jenisBerkas.find((j) => j.is_mandatory && !jenisAda.value.has(j.name))
            ?? props.jenisBerkas.find((j) => !jenisAda.value.has(j.name));
        form.files.push({ file, berkas_id: belum?.id ?? props.jenisBerkas[0]?.id ?? '' });
    }
    input.value = '';
}
const hapusFileLama = (f: FileRow) => router.delete(route('bs.ap.berkas.file.destroy', [props.berkas!.id, f.id]), { preserveScroll: true, preserveState: true });

function simpan(ajukan: boolean) {
    form.ajukan = ajukan;
    form.post(edit ? route('bs.ap.berkas.update', props.berkas!.id) : route('bs.ap.berkas.store'), { forceFormData: true, preserveScroll: true });
}

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
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-neutral-50 px-3 py-2 text-sm dark:bg-neutral-800/50">
                <span class="text-neutral-500">No. berkas</span>
                <span class="font-mono font-semibold">{{ edit ? berkas!.number : pratinjauNomor + '····' }}</span>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.document_date" label="Tanggal tagihan" type="date" required :error="form.errors.document_date" />
                <Input v-model="form.needed_date" label="Jatuh tempo" type="date" :error="form.errors.needed_date" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <span class="label-base">Dept Head penanda tangan</span>
                    <div v-if="deptHead" class="input-base flex items-center bg-neutral-50 dark:bg-neutral-800/50">{{ deptHead.name }}</div>
                    <p v-else class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                        Atasan langsung Anda belum diatur di Data Center › Employee Center. Draft tetap bisa disimpan, tapi belum bisa diajukan.
                    </p>
                    <p v-if="deptHead" class="mt-1 text-xs text-neutral-500">Otomatis dari atasan langsung Anda di Employee Center.</p>
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
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label-base">Total tagihan</label>
                    <MoneyInput v-model="form.amount" placeholder="Nilai tagihan" />
                    <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                </div>
            </div>

            <!-- File -->
            <div class="space-y-2 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label class="label-base">File berkas <span class="font-normal text-neutral-500">— PDF / JPG / PNG, maks. 10 MB, maks. {{ maxFiles }} file</span></label>
                    <span class="text-xs text-neutral-500">{{ jumlahFile }}/{{ maxFiles }}</span>
                </div>
                <ul v-if="checklist.length" class="grid gap-1.5 rounded-lg border border-neutral-200 p-3 text-sm sm:grid-cols-2 dark:border-neutral-800" aria-label="Checklist berkas">
                    <li v-for="c in checklist" :key="c.name" class="flex items-center gap-2">
                        <span :class="['flex h-4 w-4 flex-shrink-0 items-center justify-center rounded border text-[10px] font-bold',
                            c.ok ? 'border-emerald-500 bg-emerald-500 text-white' : c.wajib ? 'border-amber-400' : 'border-neutral-300 dark:border-neutral-600']">{{ c.ok ? '✓' : '' }}</span>
                        <span :class="c.ok ? 'text-neutral-900 dark:text-neutral-100' : 'text-neutral-500'">{{ c.name }}</span>
                        <span v-if="c.wajib" class="text-xs text-amber-700 dark:text-amber-400">wajib</span>
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
                <Button type="submit" :loading="form.processing && form.ajukan" :disabled="!jumlahFile || !deptHead || wajib.some((w) => !w.ok)">
                    {{ berkas?.status === 'ditolak' ? 'Simpan & Ajukan Ulang' : 'Simpan & Ajukan' }}
                </Button>
            </div>
            <p v-if="wajib.some((w) => !w.ok)" class="text-right text-xs text-amber-700">Lengkapi berkas wajib untuk mengajukan — draft tetap bisa disimpan.</p>
            <p v-else-if="!deptHead" class="text-right text-xs text-amber-700">Atasan (Dept Head) belum diatur — draft tetap bisa disimpan.</p>
        </form>
    </div>
</template>
