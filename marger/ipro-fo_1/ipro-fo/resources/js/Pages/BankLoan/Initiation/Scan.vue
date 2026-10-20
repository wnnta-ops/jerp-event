<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { IdCard, Search, ScanLine } from 'lucide-vue-next';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ records: any; filters: { search?: string } }>();
const rows = props.records.data ?? [];
const search = ref(props.filters.search ?? '');

let t: any = null;
watch(search, (v) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('kpr.scan.index'), { search: v || undefined }, { preserveState: true, replace: true }), 350);
});
</script>
<template>
    <div class="max-w-6xl space-y-4">
        <div>
            <h1 class="text-2xl font-bold flex items-center gap-2"><IdCard class="h-6 w-6 text-primary-600" /> Cek Scan KTP &amp; NPWP</h1>
            <p class="text-sm text-neutral-500 mt-0.5">Upload &amp; verifikasi (OCR) KTP/NPWP per inisiasi. OCR hanya berjalan saat tombol <b>Verifikasi &amp; Baca Data</b> diklik.</p>
        </div>

        <div class="card p-4">
            <div class="relative max-w-md">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <Input v-model="search" placeholder="Cari No. Inisiasi / Customer…" class="pl-9" />
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Inisiasi</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">TTS</th>
                    <th class="px-4 py-3 text-center">Status Scan</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 font-mono text-xs">{{ r.number }}</td>
                        <td class="px-4 py-3 font-medium">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ r.tts ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <Badge v-if="r.verdict === 'match'" variant="success" size="sm">✓ Sesuai</Badge>
                            <Badge v-else-if="r.verdict === 'mismatch'" variant="danger" size="sm">✗ Tidak sesuai</Badge>
                            <Badge v-else-if="r.uploaded" variant="warning" size="sm">Terunggah, belum diverifikasi</Badge>
                            <Badge v-else variant="neutral" size="sm">Belum diupload</Badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('kpr.scan.show', r.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><ScanLine class="h-3.5 w-3.5" /> Scan / Verifikasi</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Tidak ada inisiasi" description="Buat inisiasi KPR dulu untuk mengunggah & memverifikasi KTP/NPWP." />
        </div>
        <Pagination :data="records" />
    </div>
</template>
