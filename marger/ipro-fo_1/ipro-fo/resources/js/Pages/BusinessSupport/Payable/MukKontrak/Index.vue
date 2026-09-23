<script setup lang="ts">
// Kontrak SPK dari MUK ERP — daftar beserta total yang sudah ditagih.
//
// Angkanya dari SALINAN lokal, bukan panggilan langsung ke MUK tiap halaman dibuka:
// kalau API mereka terganggu, layar ini tetap tampil dengan hasil tarikan terakhir.
// Karena itu "ditarik" ditampilkan di kanan atas — orang berhak tahu angka yang
// dilihatnya setua apa sebelum memakainya menyiapkan pembayaran.
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileSpreadsheet, Search, AlertTriangle, RefreshCw } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    kontrak: any;
    filters: { cari?: string; status?: string };
    ringkas: Record<string, number>;
    ditarik_pada: string | null;
}>();

const cari = ref(props.filters.cari ?? '');
const status = ref(props.filters.status ?? '');

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }

let jeda: ReturnType<typeof setTimeout>;
watch([cari, status], () => {
    clearTimeout(jeda);
    jeda = setTimeout(() => {
        router.get(route('bs.muk.kontrak.index'),
            { cari: cari.value || undefined, status: status.value || undefined },
            { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Contractor Management
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <FileSpreadsheet class="h-6 w-6 text-amber-600" /> Kontrak SPK (MUK)
            </h1>
            <span v-if="ditarik_pada" class="ml-auto inline-flex items-center gap-1.5 text-xs text-neutral-500">
                <RefreshCw class="h-3.5 w-3.5" /> Ditarik {{ ditarik_pada }}
            </span>
        </div>

        <p class="text-sm text-neutral-500">
            Kontrak owner beserta progres penagihannya, diambil dari MUK ERP. Angka di sini
            <b>baca-saja</b> — sumbernya dokumen MUK, supaya tidak diketik ulang dan tidak berbeda koma.
            Klik satu baris untuk melihat rincian penagihannya.
        </p>

        <!-- Ringkasan seluruh kontrak -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-neutral-500">Kontrak</p>
                <p class="mt-1 text-xl font-bold">{{ ringkas.jumlah }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-neutral-500">Nilai Kontrak</p>
                <p class="mt-1 text-lg font-bold">{{ fmtRp(ringkas.nilai_kontrak) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-neutral-500">Diopname</p>
                <p class="mt-1 text-lg font-bold">{{ fmtRp(ringkas.diopname) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-neutral-500">Sudah Ditagih</p>
                <p class="mt-1 text-lg font-bold text-amber-600">{{ fmtRp(ringkas.ditagih) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase tracking-wide text-neutral-500">Retensi Ditahan</p>
                <p class="mt-1 text-lg font-bold">{{ fmtRp(ringkas.retensi) }}</p>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="cari" placeholder="Cari no. kontrak / SPK / proyek…"
                           class="h-9 w-72 rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <select v-model="status" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="">Semua status</option>
                    <option value="approved">Approved</option>
                    <option value="closed">Closed</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/50">
                        <tr>
                            <th class="px-4 py-3">No. Kontrak</th>
                            <th class="px-4 py-3">Proyek</th>
                            <th class="px-4 py-3 text-right">Nilai Kontrak</th>
                            <th class="px-4 py-3 text-right">Diopname</th>
                            <th class="px-4 py-3 text-right">Sudah Ditagih</th>
                            <th class="px-4 py-3 text-right">Belum Ditagih</th>
                            <th class="px-4 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="k in kontrak.data" :key="k.id"
                            class="cursor-pointer border-t border-neutral-100 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900/40"
                            @click="router.visit(route('bs.muk.kontrak.show', k.id))">
                            <td class="px-4 py-2.5">
                                <span class="font-mono font-semibold">{{ k.no_kontrak || '—' }}</span>
                                <span v-if="k.jenis === 'si'" class="ml-2 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">SI</span>
                                <!-- Ditandai, bukan disembunyikan: angkanya dari MUK dan yang bisa
                                     membetulkannya hanya mereka. Menyembunyikannya berarti orang
                                     memakai angka janggal tanpa tahu. -->
                                <span v-if="k.janggal" class="ml-2 inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700"
                                      title="Menurut angka MUK sendiri: ditagih melebihi opname, atau opname melebihi nilai kontrak.">
                                    <AlertTriangle class="h-3 w-3" /> perlu dicek
                                </span>
                                <p class="text-xs text-neutral-500">{{ k.pelanggan || '—' }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ k.proyek || '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(k.nilai_kontrak) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(k.diopname) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(k.ditagih) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(k.belum_ditagih) }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="rounded px-2 py-0.5 text-xs font-semibold"
                                      :class="k.status === 'closed' ? 'bg-neutral-200 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400' : 'bg-emerald-100 text-emerald-700'">
                                    {{ k.status || '—' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState v-if="!kontrak.data.length"
                        title="Belum ada kontrak"
                        desc="Jalankan penarikan dari MUK terlebih dahulu: php artisan muk:tarik-kontrak" />

            <div v-if="kontrak.links && kontrak.last_page > 1" class="flex flex-wrap gap-1 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <Link v-for="l in kontrak.links" :key="l.label" :href="l.url || ''"
                      class="rounded px-3 py-1 text-sm"
                      :class="[l.active ? 'bg-amber-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                               !l.url && 'pointer-events-none opacity-40']"
                      v-html="l.label" />
            </div>
        </div>
    </div>
</template>
