<!-- COGS Baru → daftar periode (lokasi + tahap) -->
<script setup lang="ts">
/**
 * Pintu masuk COGS Baru. Satu baris = satu periode (lokasi + tahap), karena tiap lembar di
 * lampiran memang berjudul "LOKASI ….. TAHAP …..".
 *
 * Daftar lokasi & tahap datang dari Front Office — di situlah luas tanah & luas bangunan per unit
 * benar-benar tersimpan. Bila FO tak terjangkau, pilihannya kosong dan sebabnya ditulis; periode
 * yang sudah ada tetap terbuka karena kode & nama lokasinya disalin saat dibuat.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Calculator, Plus, AlertTriangle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { rp, m2, angka } from '@/Components/Cogs2/warna';

const props = defineProps<{
    rows: Array<{
        id: number; lokasi: string; lokasi_id: number; tahap: string;
        luas_lahan: number; saleable: number; luas_bangunan: number;
        unit: number; budget: number; aktual: number;
    }>;
    lokasiFo: Array<{ id: number; kode: string; nama: string }>;
    tahapFo: Array<{ id: number; lokasi_id: number; kode: string; nama: string }>;
    galatFo: string | null;
    bisaUbah: boolean;
}>();

const modal = ref(false);
const lokasiId = ref<number | ''>('');
const tahap = ref('');
const menyimpan = ref(false);
const galat = ref<string | null>(null);

const tahapPilihan = computed(() => props.tahapFo.filter((t) => t.lokasi_id === Number(lokasiId.value)));

function buka() { lokasiId.value = ''; tahap.value = ''; galat.value = null; modal.value = true; }

function simpan() {
    if (! lokasiId.value) { galat.value = 'Pilih lokasi lebih dulu.'; return; }
    menyimpan.value = true;
    router.post(route('bs.cogs2.store'), { lokasi_id: lokasiId.value, tahap: tahap.value }, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = e.lokasi_id ?? e.tahap ?? Object.values(e)[0] ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Cost of Good Sold
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <Calculator class="h-6 w-6 text-sky-600" /> COGS Baru
                </h1>
                <p class="mt-1 text-sm text-neutral-500">
                    Perhitungan COGS · Set Up Kategori · Actual Costing · Rincian COGS — satu rangkaian per lokasi/tahap.
                </p>
            </div>
            <Button v-if="bisaUbah" @click="buka"><Plus class="h-4 w-4" /> Periode Baru</Button>
        </div>

        <div v-if="galatFo" class="card flex items-start gap-2 border-amber-300 bg-amber-50 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ galatFo }} Periode yang sudah ada tetap bisa dibuka.</span>
        </div>

        <div class="card overflow-x-auto p-0">
            <table v-if="rows.length" class="min-w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Lokasi</th>
                        <th class="px-4 py-2.5 text-left">Tahap</th>
                        <th class="px-4 py-2.5 text-right">Luas Lahan (m²)</th>
                        <th class="px-4 py-2.5 text-right">Saleable (m²)</th>
                        <th class="px-4 py-2.5 text-right">Luas Bangunan (m²)</th>
                        <th class="px-4 py-2.5 text-right">Unit</th>
                        <th class="px-4 py-2.5 text-right">Budget COGS</th>
                        <th class="px-4 py-2.5 text-right">Actual Costing</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id"
                        class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/40"
                        @click="router.visit(route('bs.cogs2.perhitungan', r.id))">
                        <td class="px-4 py-2.5 font-medium">{{ r.lokasi || ('Lokasi #' + r.lokasi_id) }}</td>
                        <td class="px-4 py-2.5">
                            <Badge v-if="r.tahap" variant="neutral" size="sm">{{ r.tahap }}</Badge>
                            <span v-else class="text-neutral-400">seluruh tahap</span>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ m2(r.luas_lahan) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ m2(r.saleable) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ m2(r.luas_bangunan) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ angka(r.unit) }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ rp(r.budget) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ rp(r.aktual) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Belum ada periode COGS Baru"
                        description="Buat satu per lokasi atau tahap, lalu isi Perhitungan COGS, kategori, actual costing, dan rincian per unitnya." />
        </div>

        <Teleport to="body">
            <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="modal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <h2 class="text-lg font-semibold">Periode COGS Baru</h2>
                    <p class="mt-1 text-sm text-neutral-500">
                        Satu cakupan hanya boleh punya satu periode — dua periode untuk cakupan yang sama
                        menghasilkan dua angka HPP per unit yang berbeda.
                    </p>

                    <label class="mt-4 block text-sm font-medium">Lokasi</label>
                    <select v-model="lokasiId" :class="inputCls" class="mt-1">
                        <option value="">— pilih lokasi —</option>
                        <option v-for="l in lokasiFo" :key="l.id" :value="l.id">{{ l.kode }} — {{ l.nama }}</option>
                    </select>

                    <label class="mt-3 block text-sm font-medium">Tahap</label>
                    <select v-model="tahap" :class="inputCls" class="mt-1" :disabled="! lokasiId">
                        <option value="">Seluruh tahap</option>
                        <option v-for="t in tahapPilihan" :key="t.id" :value="t.kode">{{ t.kode }} — {{ t.nama }}</option>
                    </select>

                    <p v-if="galat" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galat }}</p>

                    <div class="mt-5 flex justify-end gap-2">
                        <Button variant="ghost" @click="modal = false">Batal</Button>
                        <Button :disabled="menyimpan" @click="simpan">{{ menyimpan ? 'Menyimpan…' : 'Buat' }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
