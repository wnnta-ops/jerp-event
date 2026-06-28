<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Upload, Download, FileSpreadsheet, CheckCircle2, XCircle } from 'lucide-vue-next';

const props = defineProps<{
    columns: Array<string>;
    result: { ok: number; skipped: number; failed: Array<{ row: number; name: string; error: string }> } | null;
}>();

const form = useForm<{ file: File | null; dry_run: boolean }>({ file: null, dry_run: false });

function onFile(e: Event) {
    const t = e.target as HTMLInputElement;
    form.file = t.files?.[0] ?? null;
}
function submit() {
    if (!form.file) return;
    form.post(route('customers.upload.store'), { forceFormData: true });
}
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('customers.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Customer</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileSpreadsheet class="h-6 w-6 text-success-600" /> Upload Customer</h1>
        <p class="text-sm text-neutral-500">Impor banyak customer sekaligus dari file CSV. Unduh template, isi, lalu unggah.</p>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Form upload -->
            <div class="card space-y-3 p-4 lg:col-span-1">
                <a :href="route('customers.upload.template')" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Download class="h-4 w-4" /> Download Template CSV</a>
                <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">File CSV</label>
                    <input type="file" accept=".csv,text/csv" @change="onFile" class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-primary-700" />
                    <p v-if="form.errors.file" class="mt-1 text-xs text-danger-600">{{ form.errors.file }}</p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.dry_run" class="rounded border-neutral-300" /> Dry run (validasi tanpa simpan)</label>
                <button @click="submit" :disabled="form.processing || !form.file" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50"><Upload class="h-4 w-4" /> {{ form.processing ? 'Mengunggah…' : 'Upload' }}</button>
            </div>

            <!-- Spec kolom -->
            <div class="card p-4 lg:col-span-2">
                <div class="mb-2 text-sm font-semibold">Format Kolom</div>
                <div class="flex flex-wrap gap-2">
                    <code v-for="(c, i) in columns" :key="c" class="rounded bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800"><span class="text-neutral-400">{{ i + 1 }}.</span> {{ c }}</code>
                </div>
                <ul class="mt-3 space-y-1 text-xs text-neutral-500">
                    <li>• <b>full_name</b> wajib diisi.</li>
                    <li>• <b>id_type</b>: KTP / Passport / SIM (kosong → KTP).</li>
                    <li>• <b>id_number</b> duplikat akan dilewati otomatis.</li>
                    <li>• Baris pertama file = header (nama kolom).</li>
                </ul>
            </div>
        </div>

        <!-- Hasil import -->
        <div v-if="result" class="card overflow-hidden">
            <div class="flex flex-wrap gap-4 border-b border-neutral-200 px-4 py-3 text-sm dark:border-neutral-800">
                <span class="inline-flex items-center gap-1 text-success-600"><CheckCircle2 class="h-4 w-4" /> Berhasil: <b>{{ result.ok }}</b></span>
                <span class="text-neutral-500">Dilewati (duplikat): <b>{{ result.skipped }}</b></span>
                <span class="inline-flex items-center gap-1 text-danger-600"><XCircle class="h-4 w-4" /> Gagal: <b>{{ result.failed.length }}</b></span>
            </div>
            <table v-if="result.failed.length" class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">Baris</th><th class="px-4 py-2">Nama</th><th class="px-4 py-2">Error</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="f in result.failed" :key="f.row">
                        <td class="px-4 py-1.5">{{ f.row }}</td>
                        <td class="px-4 py-1.5">{{ f.name || '—' }}</td>
                        <td class="px-4 py-1.5 text-danger-600">{{ f.error }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-else class="px-4 py-3 text-sm text-success-600">Semua baris valid berhasil diproses. 🎉</div>
        </div>
    </div>
</template>
