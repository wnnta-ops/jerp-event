<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Upload, FileSpreadsheet, Download } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

defineProps<{ bankAccounts: Array<any> }>();

const form = useForm<any>({ file: null as File | null });
function onFile(e: Event) { form.file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function submit() { form.post(route('bs.cheque-out.import.store'), { forceFormData: true }); }

// Kolom template (statis) untuk ditampilkan sebagai tabel.
const cols = [
    { name: 'NO_BG_CEK', desc: 'Nomor warkat BG/Cek (unik per bank)', example: 'BC-0000001' },
    { name: 'BANK', desc: 'Kode rekening bank penerbit (lihat daftar di bawah)', example: '1010102001' },
    { name: 'JENIS', desc: 'Jenis warkat: cek / bg', example: 'cek' },
];
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileSpreadsheet class="h-6 w-6 text-primary-600" /> Import Data BG/Cek</h1>
            <Link :href="route('bs.cheque-out.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> BG/Cek Keluar</Link>
        </div>
        <form @submit.prevent="submit" class="card space-y-4 p-5">
            <p class="text-sm text-neutral-500">Tambah massal BG/Cek <b>yang dimiliki</b> (stok warkat) lewat file <b>Excel (.xlsx)</b>. Warkat masuk sebagai status <b>Dimiliki</b> tanpa nominal/penerima — nominal diisi saat warkat diterbitkan via <b>Tambah BG/Cek</b>.</p>

            <div>
                <a :href="route('bs.cheque-out.import.template')" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Download class="h-4 w-4" /> Download Template Excel</a>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">File Excel (.xlsx) *</label>
                <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required @change="onFile" class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white dark:text-neutral-300" />
                <p v-if="form.errors.file" class="mt-1 text-xs text-danger-500">{{ form.errors.file }}</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Format kolom <span class="text-neutral-400">(baris pertama = header)</span></label>
                <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <table class="w-full text-xs">
                        <thead class="bg-neutral-100 dark:bg-neutral-800"><tr><th class="border border-neutral-200 px-3 py-1.5 text-left font-semibold dark:border-neutral-700">Kolom</th><th class="border border-neutral-200 px-3 py-1.5 text-left font-semibold dark:border-neutral-700">Keterangan</th><th class="border border-neutral-200 px-3 py-1.5 text-left font-semibold dark:border-neutral-700">Contoh</th></tr></thead>
                        <tbody><tr v-for="c in cols" :key="c.name"><td class="border border-neutral-200 px-3 py-1.5 font-mono font-semibold dark:border-neutral-700">{{ c.name }}</td><td class="border border-neutral-200 px-3 py-1.5 text-neutral-500 dark:border-neutral-700">{{ c.desc }}</td><td class="border border-neutral-200 px-3 py-1.5 font-mono text-neutral-500 dark:border-neutral-700">{{ c.example }}</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Kode rekening bank yang tersedia</label>
                <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <table class="w-full text-xs">
                        <thead class="bg-neutral-100 dark:bg-neutral-800"><tr><th class="border border-neutral-200 px-3 py-1.5 text-left font-semibold dark:border-neutral-700">Kode (kolom BANK)</th><th class="border border-neutral-200 px-3 py-1.5 text-left font-semibold dark:border-neutral-700">Nama Rekening</th></tr></thead>
                        <tbody><tr v-for="b in bankAccounts" :key="b.id"><td class="border border-neutral-200 px-3 py-1.5 font-mono dark:border-neutral-700">{{ b.code }}</td><td class="border border-neutral-200 px-3 py-1.5 text-neutral-500 dark:border-neutral-700">{{ b.name }}</td></tr></tbody>
                    </table>
                </div>
                <p class="mt-1 text-xs text-neutral-400">Duplikat (nomor + bank yang sama) otomatis dilewati. Bila ada baris error, seluruh import dibatalkan.</p>
            </div>

            <Button type="submit" :disabled="form.processing || !form.file"><template #icon-left><Upload class="h-4 w-4" /></template>Import Sekarang</Button>
        </form>
    </div>
</template>
