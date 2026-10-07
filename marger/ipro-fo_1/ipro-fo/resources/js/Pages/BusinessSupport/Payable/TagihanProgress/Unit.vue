<script setup lang="ts">
// Rincian per UNIT RUMAH: seluruh SPK yang menyentuhnya + persediaan dalam prosesnya.
//
// BERPOROS PADA RUMAH, bukan pada tagihan yang diklik. Satu rumah disentuh beberapa SPK —
// housing, kanopi, site instruction — dan yang kelak dipindahkan ke Barang Siap Dijual adalah
// RUMAHNYA. Keputusan itu menuntut seluruh SPK pembentuknya terlihat sekaligus.
//
// Angkanya dari MUK per lokasi, dipakai apa adanya — lihat RincianUnitSpk.
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Home, PackageCheck } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    kontrak: { id: number; no_spk: string | null; no_kontrak: string | null };
    daftarUnit: Array<{ id: number; unit_code: string | null; lokasi_kode: string }>;
    unitId: number | null;
    unit: { id: number; unit_code: string | null; cluster: string | null } | null;
    baris: Array<any>;
    total: { alokasi: number; wip: number };
}>();

const unitDipilih = ref<number | null>(props.unitId);

watch(unitDipilih, (v) => {
    if (!v || v === props.unitId) return;
    router.get(route('bs.muk.tagihan.unit', props.kontrak.id), { unit: v }, { preserveState: false });
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
function fmtPersen(v: number) { return Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%'; }
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.muk.tagihan.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Tagihan Progress Project
        </Link>

        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <Home class="h-6 w-6 text-amber-600" />
                Unit Rumah {{ unit?.unit_code ?? '—' }}
            </h1>
            <p class="text-sm text-neutral-500">
                Seluruh SPK yang menyentuh rumah ini. Dibuka dari SPK
                <b class="font-mono">{{ kontrak.no_spk || kontrak.no_kontrak }}</b>.
            </p>
        </div>

        <div class="card space-y-2 p-4">
            <label class="block text-xs font-semibold uppercase tracking-wide text-neutral-500">Unit Rumah</label>
            <SearchSelect
                v-model="unitDipilih"
                :options="daftarUnit.map((u) => ({ id: u.id, code: u.unit_code ?? String(u.id), name: 'lokasi MUK ' + u.lokasi_kode }))"
                placeholder="— pilih unit rumah —"
                class="max-w-xl"
            />
            <!-- Rumah yang lokasinya belum dipasangkan tidak muncul di daftar ini sama sekali.
                 Dikatakan, bukan dibiarkan tampak seperti SPK ini hanya punya sedikit rumah. -->
            <p class="text-xs text-neutral-400">
                {{ daftarUnit.length }} rumah pada SPK ini sudah dipasangkan ke unit kita.
                Yang belum dipasangkan tidak muncul — lengkapi di Setup → Pasangan Unit MUK.
            </p>
        </div>

        <template v-if="unit">
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah SPK</div><div class="mt-1 text-xl font-bold">{{ baris.length }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Alokasi SPK (DPP)</div><div class="mt-1 text-xl font-bold">{{ fmtRp(total.alokasi) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Persediaan Dalam Proses</div><div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(total.wip) }}</div></div>
            </div>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3 w-12">No</th>
                        <th class="px-3 py-3">Lokasi</th>
                        <th class="px-3 py-3">Tahap</th>
                        <th class="px-3 py-3">No. SPK</th>
                        <th class="px-3 py-3">Kategori COGS</th>
                        <th class="px-3 py-3 text-right">Alokasi SPK (DPP)</th>
                        <th class="px-3 py-3 text-right">% Progress</th>
                        <th class="px-3 py-3 text-right">Nominal Persediaan Dalam Proses</th>
                        <th class="px-3 py-3 text-center">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="(r, i) in baris" :key="r.muk_id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-3 py-2.5 text-neutral-400">{{ i + 1 }}</td>
                            <td class="px-3 py-2.5">
                                <span v-if="r.lokasi">{{ r.lokasi }}</span>
                                <span v-else class="text-xs italic text-neutral-400">belum dipetakan</span>
                            </td>
                            <td class="px-3 py-2.5">{{ r.tahap || '—' }}</td>
                            <td class="px-3 py-2.5 font-mono font-semibold">{{ r.no_spk || '—' }}</td>
                            <td class="px-3 py-2.5">
                                <span v-if="r.kategori_cogs">{{ r.kategori_cogs }}</span>
                                <span v-else class="text-xs italic text-neutral-400">belum ditautkan</span>
                            </td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.alokasi_dpp) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ fmtPersen(r.progress_persen) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.wip) }}</td>
                            <td class="px-3 py-2.5 text-center">
                                <!-- MENAUTKAN, bukan memposting di sini. Perpindahan ke Barang Siap
                                     Dijual adalah jurnal, dan jurnal diterbitkan di layarnya sendiri
                                     oleh orang yang berwenang — bukan sebagai efek samping tombol
                                     pada layar baca. -->
                                <Link :href="route('bs.ap.barang-siap-jual.index', { unit: unit.id })"
                                      class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:underline dark:text-amber-400">
                                    <PackageCheck class="h-3.5 w-3.5" /> Pindah ke Barang Siap Dijual
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="baris.length" class="border-t-2 border-neutral-200 bg-neutral-50 text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900/50">
                        <tr>
                            <td colspan="5" class="px-3 py-2.5">Total {{ baris.length }} SPK</td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(total.alokasi) }}</td>
                            <td class="px-3 py-2.5"></td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(total.wip) }}</td>
                            <td class="px-3 py-2.5"></td>
                        </tr>
                    </tfoot>
                </table>

                <EmptyState v-if="!baris.length" title="Belum ada SPK untuk rumah ini"
                            description="Lokasi MUK rumah ini belum muncul di satu pun kontrak, atau kontraknya belum tersalin." />
            </div>
        </template>

        <EmptyState v-else title="Belum ada rumah yang dipasangkan"
                    description="Tidak ada lokasi pada SPK ini yang sudah dipasangkan ke unit kita. Lengkapi di Setup → Pasangan Unit MUK." />
    </div>
</template>
