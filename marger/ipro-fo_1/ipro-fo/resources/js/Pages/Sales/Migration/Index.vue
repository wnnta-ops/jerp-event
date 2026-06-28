<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { DatabaseZap, Download, Upload, FileSpreadsheet, CheckCircle2, XCircle, CalendarClock, Wallet, FileSignature } from 'lucide-vue-next';

const props = defineProps<{
    columns: Record<string, Array<string>>;
    result: { ok: number; skipped: number; failed: Array<{ row: number; ref: string; error: string }> } | null;
    lastType: string | null;
}>();

const sections = [
    { type: 'jadwal',     title: 'Jadwal Pembayaran', desc: 'Impor termin jadwal ke booking (match booking_number).', icon: CalendarClock },
    { type: 'pembayaran', title: 'Pembayaran',        desc: 'Impor riwayat pembayaran (menambah paid_amount termin).', icon: Wallet },
    { type: 'kontrak',    title: 'Kontrak',           desc: 'Impor nomor & status kontrak ke booking.', icon: FileSignature },
];

const forms: Record<string, any> = {
    jadwal: useForm<{ file: File | null; dry_run: boolean }>({ file: null, dry_run: false }),
    pembayaran: useForm<{ file: File | null; dry_run: boolean }>({ file: null, dry_run: false }),
    kontrak: useForm<{ file: File | null; dry_run: boolean }>({ file: null, dry_run: false }),
};
function onFile(type: string, e: Event) { forms[type].file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function submit(type: string) { if (!forms[type].file) return; forms[type].post(route('sales.migrasi.upload', type), { forceFormData: true }); }
</script>
<template>
    <div class="space-y-5">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><DatabaseZap class="h-6 w-6 text-primary-600" /> Migrasi Data</h1>
            <p class="text-sm text-neutral-500">Impor data legacy (kontrak, jadwal, pembayaran) dari CSV untuk cutover. Cocokkan ke booking lewat <code>booking_number</code>.</p>
        </div>

        <!-- Hasil import terakhir -->
        <div v-if="result" class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-4 border-b border-neutral-200 px-4 py-3 text-sm dark:border-neutral-800">
                <span class="font-semibold">Hasil migrasi {{ lastType }}</span>
                <span class="inline-flex items-center gap-1 text-success-600"><CheckCircle2 class="h-4 w-4" /> Diproses: <b>{{ result.ok }}</b></span>
                <span class="text-neutral-500">Dilewati: <b>{{ result.skipped }}</b></span>
                <span class="inline-flex items-center gap-1 text-danger-600"><XCircle class="h-4 w-4" /> Gagal: <b>{{ result.failed.length }}</b></span>
            </div>
            <table v-if="result.failed.length" class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">Baris</th><th class="px-4 py-2">Ref</th><th class="px-4 py-2">Error</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="f in result.failed" :key="f.row"><td class="px-4 py-1.5">{{ f.row }}</td><td class="px-4 py-1.5 font-mono text-xs">{{ f.ref || '—' }}</td><td class="px-4 py-1.5 text-danger-600">{{ f.error }}</td></tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div v-for="s in sections" :key="s.type" class="card space-y-3 p-4">
                <div class="flex items-center gap-2 text-sm font-semibold"><component :is="s.icon" class="h-4 w-4 text-primary-600" /> {{ s.title }}</div>
                <p class="text-xs text-neutral-500">{{ s.desc }}</p>
                <div class="flex flex-wrap gap-1">
                    <code v-for="c in columns[s.type]" :key="c" class="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] dark:bg-neutral-800">{{ c }}</code>
                </div>
                <a :href="route('sales.migrasi.template', s.type)" class="inline-flex items-center gap-2 text-xs text-primary-600 hover:underline"><Download class="h-3.5 w-3.5" /> Download template</a>
                <input type="file" accept=".csv,text/csv" @change="(e) => onFile(s.type, e)" class="block w-full text-xs file:mr-2 file:rounded file:border-0 file:bg-primary-50 file:px-2 file:py-1.5 file:text-xs file:font-semibold file:text-primary-700" />
                <p v-if="forms[s.type].errors.file" class="text-xs text-danger-600">{{ forms[s.type].errors.file }}</p>
                <label class="inline-flex items-center gap-2 text-xs"><input type="checkbox" v-model="forms[s.type].dry_run" class="rounded border-neutral-300" /> Dry run (validasi saja)</label>
                <button @click="submit(s.type)" :disabled="forms[s.type].processing || !forms[s.type].file" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50"><Upload class="h-4 w-4" /> {{ forms[s.type].processing ? 'Mengunggah…' : 'Upload ' + s.title }}</button>
            </div>
        </div>
    </div>
</template>
