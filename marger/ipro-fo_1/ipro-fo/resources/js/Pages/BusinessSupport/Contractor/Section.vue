<!-- Contractor: landing per sub-modul (grup BO). Detail item muncul di halaman ini. -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, HardHat } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ section: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

type Item = { label: string; route: string | null; summary?: string | null };
const titles: Record<string, string> = {
    setup: 'Setup',
    master: 'Master',
    query: 'Query',
    spk: 'Surat Perintah Kerja',
};
const sections = computed<Record<string, Item[]>>(() => ({
    setup: [
        { label: 'Project', route: r('bs.spk.project.index') },
        { label: 'Pasangan Unit MUK', route: r('bs.muk.pasangan.index') },
    ],
    master: [
        { label: 'Kontrak SPK (MUK)', route: r('bs.muk.kontrak.index') },
        { label: 'Informasi CTC', route: r('bs.muk.ctc.index') },
        { label: 'Tagihan Progress Project', route: r('bs.muk.tagihan.index') },
        { label: 'Surat Perintah Kerja', route: r('bs.spk.index') },
        { label: 'Kontrol Progress Lapangan', route: r('bs.spk.kpl.index') },
        { label: 'Progress Retensi', route: r('bs.spk.retention.index') },
    ],
    query: [
        { label: 'Rekap SPK', route: r('bs.spk.query.rekap') },
        { label: 'Kartu Hutang Supplier - SPK', route: r('bs.spk.query.kartu-hutang') },
        { label: 'Pemakaian Barang', route: r('bs.spk.query.pemakaian') },
        { label: 'Summary SPK', route: r('bs.spk.query.summary') },
    ],
    spk: [
        { label: 'Registrasi Surat Perintah Kerja', route: r('bs.spk.create') },
        { label: 'Registrasi Kontrol Progress Lapangan', route: r('bs.spk.kpl.create') },
        { label: 'Registrasi Retensi', route: r('bs.spk.retention.create') },
        { label: 'Addendum SPK (Tambah / Kurang)', route: r('bs.spk.addendum.form') },
        { label: 'Adjustment PPN', route: r('bs.spk.adjust-ppn.form') },
        { label: 'Pembatalan Surat Perintah Kerja', route: r('bs.spk.cancel.form') },
    ],
}));
const items = computed<Item[]>(() => sections.value[props.section] ?? []);
const title = computed(() => titles[props.section] ?? 'Sub-Modul');
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="it in items" :key="it.label" class="card flex items-center justify-between gap-2 p-4">
                <a v-if="it.route" :href="it.route" class="flex-1 font-medium text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                <span v-else class="flex-1 font-medium text-neutral-400">{{ it.label }}</span>
                <span class="flex items-center gap-2">
                    <a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a>
                    <Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge>
                    <span v-else class="text-primary-400">→</span>
                </span>
            </div>
        </div>
    </div>
</template>
