<script setup lang="ts">
// Posting ke Barang Siap Dijual — memindahkan nilai satu rumah dari Persediaan Dalam Proses
// ke Persediaan Bangunan Siap Jual.
//
// Yang dipindahkan adalah yang SUDAH DITAGIHKAN, bukan nilai kontrak: nilai kontrak itu
// rencana, dan memindahkannya menaruh biaya yang belum terjadi ke dalam neraca.
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, PackageCheck, Search, AlertTriangle } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    rows: Array<any>;
    filters: { cari?: string; unit?: number | null };
    bisaPosting: boolean;
    akunSiapJual: { id: number; code: string; name: string } | null;
}>();

const cari = ref(props.filters.cari ?? '');

function fmtRp(v: number | null) {
    if (v === null || v === undefined) return '—';
    return 'Rp ' + Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
}

let jeda: ReturnType<typeof setTimeout>;
watch(cari, () => {
    clearTimeout(jeda);
    jeda = setTimeout(() => {
        router.get(route('bs.ap.barang-siap-jual.index'), { cari: cari.value || undefined },
            { preserveState: true, replace: true });
    }, 300);
});

function pindah(r: any) {
    if (!confirm(
        `Pindahkan unit ${r.unit_code} ke Barang Siap Dijual sebesar ${fmtRp(r.wip)}?\n\n`
        + 'Jurnalnya terbit sekarang dan hanya bisa sekali untuk tiap rumah.',
    )) return;
    router.post(route('bs.ap.barang-siap-jual.posting', r.unit_id), {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Account Payable
        </Link>

        <h1 class="flex items-center gap-2 text-2xl font-bold">
            <PackageCheck class="h-6 w-6 text-amber-600" /> Posting ke Barang Siap Dijual
        </h1>
        <p class="text-sm text-neutral-500">
            Memindahkan nilai satu rumah dari <b>Persediaan Dalam Proses</b> ke
            <b>Persediaan — Bangunan Siap Jual</b><span v-if="akunSiapJual"> ({{ akunSiapJual.code }})</span>.
            Yang dipindahkan adalah nilai yang <b>sudah ditagihkan</b> dari seluruh SPK rumah itu —
            bukan nilai kontraknya.
        </p>

        <p v-if="!akunSiapJual" class="flex items-start gap-2 rounded-lg border border-danger-200 bg-danger-50 px-4 py-2.5 text-sm text-danger-700 dark:border-danger-900/40 dark:bg-danger-900/20 dark:text-danger-300">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Akun “Persediaan — Bangunan Siap Jual” belum ada di Chart of Account. Posting akan ditolak sampai akunnya dibuat.</span>
        </p>

        <div class="card flex flex-wrap items-center gap-3 p-3">
            <div class="relative min-w-[16rem] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="cari" type="search" placeholder="Cari kode unit rumah…"
                       class="h-10 w-full rounded-lg border border-neutral-300 pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3">Unit Rumah</th>
                    <th class="px-3 py-3">Lokasi MUK</th>
                    <th class="px-3 py-3 text-center">Jml SPK</th>
                    <th class="px-3 py-3 text-right">Persediaan Dalam Proses</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.unit_id"
                        :class="[filters.unit === r.unit_id ? 'bg-amber-50 dark:bg-amber-900/10' : '',
                                 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50']">
                        <td class="px-3 py-2.5 font-mono font-semibold">{{ r.unit_code }}</td>
                        <td class="px-3 py-2.5 font-mono text-xs text-neutral-500">{{ r.lokasi_kode }}</td>
                        <td class="px-3 py-2.5 text-center tabular-nums text-neutral-500">{{ r.jml_spk }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.wip) }}</td>
                        <td class="px-3 py-2.5">
                            <template v-if="r.dipindah_pada">
                                <Badge variant="success" size="sm">Dipindah {{ r.dipindah_pada }}</Badge>
                                <div class="text-xs text-neutral-400">dijurnal {{ fmtRp(r.nilai_dipindah) }}</div>
                                <!-- Biaya yang datang SESUDAH perpindahan. Ditampilkan, bukan
                                     disembunyikan dengan menghitung ulang: ia keputusan akuntansi
                                     tersendiri, dan yang berbahaya adalah tidak terlihat. -->
                                <div v-if="r.selisih_setelah_pindah" class="text-xs text-amber-600">
                                    + {{ fmtRp(r.selisih_setelah_pindah) }} ditagihkan sesudahnya
                                </div>
                            </template>
                            <span v-else class="text-xs text-neutral-400">belum dipindah</span>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <Button v-if="bisaPosting && !r.dipindah_pada && r.wip > 0" variant="primary" size="sm" @click="pindah(r)">
                                Pindah ke Barang Siap Dijual
                            </Button>
                            <span v-else class="text-xs text-neutral-400">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <EmptyState v-if="!rows.length" title="Belum ada rumah dengan persediaan dalam proses"
                        description="Rumah muncul di sini setelah lokasinya dipasangkan ke unit kita dan MUK menagihkan sebagian pekerjaannya." />
        </div>
    </div>
</template>
