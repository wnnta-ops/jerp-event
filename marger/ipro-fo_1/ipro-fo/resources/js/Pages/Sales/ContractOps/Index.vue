<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileCog, SlidersHorizontal, Percent, ReceiptText, UserRoundCog, ArrowLeftRight, Ban, ChevronRight } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ recent: Array<any>; types: Record<string, string> }>();

const tiles = [
    { label: 'Adjustment Kontrak', desc: 'Revisi harga / diskon kontrak', icon: SlidersHorizontal, href: route('sales.kontrak-ops.operation', 'adjustment'), color: 'text-primary-600' },
    { label: 'Adjustment PPN', desc: 'Transisi tarif PPN massal + recompute', icon: Percent, href: route('sales.kontrak-ops.ppn'), color: 'text-accent-600' },
    { label: 'Tagihan Custom', desc: 'Tambah tagihan manual di luar jadwal', icon: ReceiptText, href: route('sales.kontrak-ops.operation', 'tagihan'), color: 'text-success-600' },
    { label: 'Pengalihan Hak', desc: 'Ganti nama pemilik kontrak', icon: UserRoundCog, href: route('sales.kontrak-ops.operation', 'ganti-nama'), color: 'text-amber-600' },
    { label: 'Pindah Unit', desc: 'Tukar unit kontrak + reprice', icon: ArrowLeftRight, href: route('sales.kontrak-ops.operation', 'pindah-unit'), color: 'text-primary-600' },
    { label: 'Pembatalan Kontrak', desc: 'Batalkan kontrak + penalti/refund', icon: Ban, href: route('sales.kontrak-ops.operation', 'batal'), color: 'text-danger-600' },
];
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const tv: Record<string, any> = { adjustment: 'primary', ppn: 'accent', tagihan_custom: 'success', ganti_nama: 'warning', pindah_unit: 'primary', batal: 'danger' };
</script>
<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCog class="h-6 w-6 text-primary-600" /> Operasi Kontrak</h1>
            <p class="text-sm text-neutral-500">Adjustment, PPN, tagihan custom, pengalihan hak, pindah unit &amp; pembatalan kontrak.</p>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="t in tiles" :key="t.label" :href="t.href" class="card group flex items-start gap-3 p-4 transition hover:ring-2 hover:ring-primary-300">
                <component :is="t.icon" class="h-7 w-7 shrink-0" :class="t.color" />
                <div class="flex-1"><div class="font-semibold leading-tight">{{ t.label }}</div><div class="mt-0.5 text-xs text-neutral-500">{{ t.desc }}</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-primary-500" />
            </Link>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Operasi Terakhir</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">No. Operasi</th><th class="px-4 py-2">Tipe</th><th class="px-4 py-2">Kontrak</th><th class="px-4 py-2 text-right">Nilai</th><th class="px-4 py-2">Alasan</th><th class="px-4 py-2">Oleh</th><th class="px-4 py-2">Waktu</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in recent" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-mono text-xs">{{ r.operation_no }}</td>
                        <td class="px-4 py-2"><Badge :variant="tv[r.type] ?? 'neutral'" size="sm">{{ r.type_label }}</Badge></td>
                        <td class="px-4 py-2 font-mono text-xs">{{ r.contract ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ r.amount ? rb(r.amount) : '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500 truncate max-w-[14rem]">{{ r.reason ?? '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.by ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-neutral-400">{{ r.at ?? '—' }}</td>
                    </tr>
                    <tr v-if="!recent.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Belum ada operasi kontrak.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
