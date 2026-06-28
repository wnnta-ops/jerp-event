<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Upload, FileSpreadsheet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ template: string }>();
// Parse template CSV → tabel (baris pertama = header).
const tpl = computed(() => {
    const lines = (props.template || '').trim().split(/\r?\n/).filter((l) => l.length);
    const rows = lines.map((l) => l.split(','));
    return { header: rows[0] ?? [], body: rows.slice(1) };
});
const form = useForm<any>({ file: null as File | null, dry_run: true });
function onFile(e: Event) { form.file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function submit() { form.post(route('bs.gl.journal.import.store'), { forceFormData: true }); }
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileSpreadsheet class="h-6 w-6 text-primary-600" /> Import Jurnal (CSV)</h1>
            <Link :href="route('bs.gl.journal.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Jurnal</Link>
        </div>
        <form @submit.prevent="submit" class="card space-y-4 p-5">
            <p class="text-sm text-neutral-500">Upload CSV jurnal massal. Baris dengan <b>REFERENSI</b> sama digabung menjadi satu jurnal (harus balance: total Debit = total Kredit).</p>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">File CSV *</label>
                <input type="file" accept=".csv,.txt" required @change="onFile" class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white dark:text-neutral-300" />
                <p v-if="form.errors.file" class="mt-1 text-xs text-danger-500">{{ form.errors.file }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.dry_run" type="checkbox" class="rounded" /> Dry-run (cek dulu, belum diposting)</label>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Format / Template kolom <span class="text-neutral-400">(baris pertama = header)</span></label>
                <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <table class="w-full text-xs">
                        <thead class="bg-neutral-100 dark:bg-neutral-800"><tr><th v-for="(h, i) in tpl.header" :key="i" class="border border-neutral-200 px-3 py-1.5 text-left font-semibold dark:border-neutral-700">{{ h }}</th></tr></thead>
                        <tbody><tr v-for="(row, r) in tpl.body" :key="r"><td v-for="(c, ci) in row" :key="ci" class="border border-neutral-200 px-3 py-1.5 font-mono text-neutral-500 dark:border-neutral-700">{{ c }}</td></tr></tbody>
                    </table>
                </div>
                <p class="mt-1 text-xs text-neutral-400">Salin header & isi sesuai contoh di Excel, lalu simpan sebagai CSV.</p>
            </div>
            <Button type="submit" :disabled="form.processing || !form.file"><template #icon-left><Upload class="h-4 w-4" /></template>{{ form.dry_run ? 'Cek (Dry-run)' : 'Import Sekarang' }}</Button>
        </form>
    </div>
</template>
