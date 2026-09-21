<!-- Cash Book → Transaksi Bon Sementara → Alokasi Bon Sementara: daftar bon yang perlu dialokasikan. -->
<script setup lang="ts">
/**
 * Daftarnya SEMPIT dan itu disengaja: hanya bon Debit VA model baru yang sudah dibayar dan
 * belum direalisasi — persis himpunan yang punya langkah Alokasi Kasbon. Bon reguler membayar
 * langsung dari bank (sudah berjurnal saat dibayar) dan tidak pernah melewati layar ini.
 */
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Wallet, CheckCircle2, CircleDashed } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ rows: any }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
</script>

<template>
    <div class="space-y-4">
        <!-- Pola tautan balik yang sama dengan Payments.vue (sub-modul bersaudara). -->
        <Link :href="route('bs.advance.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Bon Sementara
        </Link>

        <h1 class="flex items-center gap-2 text-2xl font-bold">
            <Wallet class="h-6 w-6 text-primary-600" /> Alokasi Bon Sementara
        </h1>
        <p class="text-sm text-neutral-500">
            Bon Debit VA belum mengeluarkan uang dari bank saat dibayar. Catat pengeluarannya di sini sebagai
            <b>Alokasi Kasbon</b>, lalu tekan <b>Lanjut Realisasikan Bon</b> — bon baru sampai ke
            <b>Realisasi Bon Sementara</b> sesudah tombol itu ditekan.
        </p>

        <div v-if="rows.data?.length" class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
                        <th class="px-3 py-2">No. Bon</th>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2">Pemegang</th>
                        <th class="px-3 py-2">Keperluan</th>
                        <th class="px-3 py-2 text-right">Nominal Bon</th>
                        <th class="px-3 py-2 text-right">Dialokasikan</th>
                        <th class="px-3 py-2 text-right">Belum</th>
                        <th class="px-3 py-2">Tahap</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="whitespace-nowrap px-3 py-2">
                            <Link :href="route('bs.advance.allocation.show', r.id)" class="font-mono text-primary-600 hover:underline">{{ r.nomor }}</Link>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">{{ fmtDate(r.tanggal) }}</td>
                        <td class="px-3 py-2">{{ r.pemegang }}</td>
                        <td class="max-w-[240px] truncate px-3 py-2" :title="r.keperluan">{{ r.keperluan }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ fmtRp(r.nominal) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono text-primary-600">
                            {{ fmtRp(r.dialokasikan) }}
                            <span class="ml-1 text-[10px] text-neutral-400">{{ r.jumlahAlokasi }}×</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ fmtRp(r.belum) }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <Badge v-if="r.sudahLanjut" variant="success" dot>Sudah dilanjutkan</Badge>
                            <Badge v-else variant="warning" dot>Belum dilanjutkan</Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState v-else title="Belum ada bon yang perlu dialokasikan"
                    description="Bon Debit VA yang sudah dibayar dan belum direalisasi akan muncul di sini. Bon reguler tidak melewati tahap alokasi — uangnya sudah keluar dari bank saat dibayar." />

        <div v-if="rows.links?.length > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in rows.links" :key="l.label" :href="l.url" v-html="l.label"
                       class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>
    </div>
</template>
