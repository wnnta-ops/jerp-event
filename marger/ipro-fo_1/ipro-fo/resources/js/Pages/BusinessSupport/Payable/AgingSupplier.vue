<script setup lang="ts">
// Aging Hutang Supplier — outstanding per supplier, 20 terbesar + rangkuman sisanya.
//
// Angkanya dari computeAging() yang SAMA dengan layar Aging Hutang, PDF, dan Excel-nya —
// alasannya ada di ApInvoiceController::agingSupplier(). Lima embernya dirangkum jadi tiga
// (0–30 sudah termasuk yang belum jatuh tempo).
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Layers, Search } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

interface Baris { name: string; b0: number; b31: number; b60: number; total: number }
interface Others { jumlah: number; b0: number; b31: number; b60: number; total: number }

const props = defineProps<{
    rows: Baris[];
    others: Others | null;
    total: Others;
    dominan: Baris | null;
    topN: number;
    dateFrom: string | null;
    dateTo: string;
}>();

const cari = ref('');
const dari = ref(props.dateFrom ?? '');
const sampai = ref(props.dateTo);

function muat() {
    router.get(route('bs.ap.aging-supplier'), { date_from: dari.value, date_to: sampai.value },
        { preserveState: true, preserveScroll: true, replace: true });
}

/* Pencarian hanya menyaring 20 baris yang TAMPIL. Baris Others & TOTAL tetap utuh — keduanya
   menjawab "berapa seluruhnya", dan menyaringnya ikut membuat jumlahnya berubah-ubah mengikuti
   apa yang sedang diketik. */
const tampil = computed(() => {
    const q = cari.value.trim().toLowerCase();

    return q === '' ? props.rows : props.rows.filter((r) => r.name.toLowerCase().includes(q));
});

/* Rupiah disingkat seperti pada laporan ringkas: miliar di atas 1 M, juta di atas 1 jt. Angka
   penuh tetap ada di title supaya yang butuh ketelitian tidak kehilangannya. */
function rp(v: number) {
    const n = Number(v ?? 0);
    if (n === 0) return '—';
    if (Math.abs(n) >= 1_000_000_000) return 'Rp ' + (n / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' M';
    if (Math.abs(n) >= 1_000_000) return 'Rp ' + (n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';

    return 'Rp ' + n.toLocaleString('id-ID');
}
function penuh(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> Aging Hutang Supplier</h1>
                <p class="mt-1 text-sm text-neutral-500">Outstanding per supplier — {{ topN }} terbesar, sisanya dirangkum.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Dari tgl invoice</label>
                    <input v-model="dari" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
                <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Per tanggal</label>
                    <input v-model="sampai" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
                <button type="button" @click="muat" class="h-9 rounded-lg bg-primary-600 px-4 text-sm font-medium text-white hover:bg-primary-700">Tampilkan</button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 class="text-sm font-bold">Outstanding AP per Supplier <span class="font-normal text-neutral-400">(Top {{ topN }})</span></h2>
                <div class="relative">
                    <Search class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                    <input v-model="cari" placeholder="Cari supplier…" class="h-8 w-56 rounded-lg border border-neutral-300 bg-white pl-8 pr-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
            </div>

            <div v-if="total.jumlah" class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3 text-right">Outstanding</th>
                            <th class="px-4 py-3 text-right" title="Belum jatuh tempo + 1–30 hari lewat">0–30</th>
                            <th class="px-4 py-3 text-right">31–60</th>
                            <th class="px-4 py-3 text-right">&gt; 60</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in tampil" :key="r.name" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-2.5 text-primary-700 dark:text-primary-300">{{ r.name }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold" :title="penuh(r.total)">{{ rp(r.total) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :title="penuh(r.b0)">{{ rp(r.b0) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :title="penuh(r.b31)">{{ rp(r.b31) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :class="r.b60 > 0 ? 'text-danger-600 dark:text-danger-400' : ''" :title="penuh(r.b60)">{{ rp(r.b60) }}</td>
                        </tr>
                        <tr v-if="!tampil.length">
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-400">Tidak ada supplier yang cocok dengan pencarian.</td>
                        </tr>

                        <!-- Others: seluruh supplier ke-21 dan seterusnya. Tanpa baris ini, tabel
                             yang memuat 20 dari puluhan supplier terbaca seolah itulah seluruh
                             hutangnya. Sengaja TIDAK ikut tersaring pencarian. -->
                        <tr v-if="others" class="bg-neutral-50/70 italic dark:bg-neutral-800/40">
                            <td class="px-4 py-2.5 text-neutral-600 dark:text-neutral-300">Others ({{ others.jumlah }} supplier lainnya)</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold" :title="penuh(others.total)">{{ rp(others.total) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :title="penuh(others.b0)">{{ rp(others.b0) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :title="penuh(others.b31)">{{ rp(others.b31) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :title="penuh(others.b60)">{{ rp(others.b60) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800">
                        <tr>
                            <td class="px-4 py-3">TOTAL ({{ total.jumlah }} supplier)</td>
                            <td class="px-4 py-3 text-right font-mono" :title="penuh(total.total)">{{ rp(total.total) }}</td>
                            <td class="px-4 py-3 text-right font-mono" :title="penuh(total.b0)">{{ rp(total.b0) }}</td>
                            <td class="px-4 py-3 text-right font-mono" :title="penuh(total.b31)">{{ rp(total.b31) }}</td>
                            <td class="px-4 py-3 text-right font-mono" :title="penuh(total.b60)">{{ rp(total.b60) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <p class="border-t border-neutral-200 px-4 py-2.5 text-[11px] leading-relaxed text-neutral-500 dark:border-neutral-800">
                    Total seluruh <b>{{ total.jumlah }} supplier</b>: <b>{{ penuh(total.total) }}</b>
                    (0–30: {{ rp(total.b0) }} · 31–60: {{ rp(total.b31) }} · &gt;60: {{ rp(total.b60) }}).
                    <span v-if="dominan">Didominasi <b>{{ dominan.name }}</b> ({{ rp(dominan.total) }}).</span>
                    Kolom <b>0–30</b> sudah termasuk hutang yang <b>belum jatuh tempo</b>, sehingga jumlah ketiga
                    kolomnya selalu sama dengan Outstanding. Angkanya dihitung dari invoice hutang per
                    {{ dateTo }} — sama dengan layar <b>Aging Hutang</b>, tidak perlu diunggah.
                </p>
            </div>
            <EmptyState v-else title="Tidak ada hutang outstanding"
                :description="'Tidak ada invoice hutang yang masih bersaldo per ' + dateTo + '. Ubah rentang tanggalnya bila perlu.'" />
        </div>
    </div>
</template>
