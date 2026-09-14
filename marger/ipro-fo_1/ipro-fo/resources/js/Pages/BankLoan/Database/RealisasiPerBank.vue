<script setup lang="ts">
// Realisasi KPR per Bank — unit akad per cluster per bank (kumulatif), plus komposisi bank.
//
// Angkanya DIHITUNG dari data akad, bukan diunggah: definisinya sama persis dengan layar
// Realisasi vs Budget (aplikasi terbaru per booking, akad batal tidak ikut). Alasannya ada di
// KprRealisasiBankController.
import { computed } from 'vue';
import { Landmark } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

interface Baris { cluster: string; total: number; bank: Record<string, number> }
interface Komposisi { bank: string; unit: number; persen: number }

const props = defineProps<{
    banks: string[];
    rows: Baris[];
    totalBank: Record<string, number>;
    grand: number;
    komposisi: Komposisi[];
}>();

/* Nol ditampilkan sebagai tanda pisah, bukan "0". Pada tabel silang yang sebagian besar selnya
   kosong, deretan angka nol membuat yang benar-benar berisi jadi sulit ditemukan. */
function n(v: number) { return Number(v ?? 0) === 0 ? '—' : Number(v).toLocaleString('id-ID'); }
/* Persentase ditulis BULAT, kecuali yang di bawah 1% yang memakai satu desimal. Bank dengan 7
   dari 915 akad adalah 0,8%: dibulatkan ia menjadi 1% — menyamakannya dengan bank yang punya
   sembilan unit — atau, bila dibulatkan ke bawah, 0% yang terbaca "tidak ada sama sekali". */
function pct(v: number) {
    const d = Math.abs(Number(v ?? 0)) < 1 ? 1 : 0;

    return Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d }) + '%';
}

const adaData = computed(() => props.grand > 0);
/* Lebar bar relatif terhadap bank TERBESAR, bukan terhadap 100%. Bank dengan 0,8% akan
   menghasilkan garis yang tak terlihat sama sekali bila diskalakan ke 100 — dan garis yang tak
   terlihat terbaca sebagai "tidak ada", padahal angkanya tertulis di sebelahnya. */
const terbesar = computed(() => Math.max(1, ...props.komposisi.map((k) => k.unit)));
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-primary-600" /> Realisasi KPR per Bank</h1>
            <p class="mt-1 text-sm text-neutral-500">Unit akad per cluster per bank, kumulatif seluruh periode.</p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-4">
                <h2 class="mb-3 text-sm font-bold">Realisasi KPR per Bank per Cluster <span class="font-normal text-neutral-400">(Unit Akad — Kumulatif)</span></h2>

                <div v-if="adaData" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 dark:bg-neutral-800">
                            <tr class="text-left text-xs uppercase text-neutral-500">
                                <th class="px-4 py-3">Cluster</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th v-for="b in banks" :key="b" class="px-4 py-3 text-right">{{ b }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="r in rows" :key="r.cluster" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                <td class="px-4 py-2.5">{{ r.cluster }}</td>
                                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ n(r.total) }}</td>
                                <td v-for="b in banks" :key="b" class="px-4 py-2.5 text-right font-mono">{{ n(r.bank[b]) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800">
                            <tr>
                                <td class="px-4 py-3">TOTAL</td>
                                <td class="px-4 py-3 text-right font-mono">{{ n(grand) }}</td>
                                <td v-for="b in banks" :key="b" class="px-4 py-3 text-right font-mono">{{ n(totalBank[b]) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <EmptyState v-else title="Belum ada akad KPR"
                    description="Tabel ini terisi sendiri begitu ada akad KPR yang tercatat — tidak perlu diunggah." />
            </section>

            <section class="card p-4">
                <h2 class="mb-3 text-sm font-bold">Komposisi Bank <span class="font-normal text-neutral-400">(Total Akad)</span></h2>

                <div v-if="adaData" class="space-y-3.5">
                    <div v-for="k in komposisi" :key="k.bank">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-sm font-bold">{{ k.bank }}</span>
                            <span class="font-mono text-xs font-bold text-primary-600 dark:text-primary-400">{{ pct(k.persen) }}</span>
                        </div>
                        <div class="mb-1 text-xs text-neutral-500">{{ n(k.unit) }} KPR</div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-primary-100 dark:bg-primary-900/30">
                            <div class="h-full rounded-full bg-primary-500" :style="{ width: Math.max(1, Math.round(k.unit / terbesar * 100)) + '%' }" />
                        </div>
                    </div>
                </div>
                <EmptyState v-else title="Belum ada akad KPR" description="Komposisinya muncul setelah ada akad yang tercatat." />
            </section>
        </div>

        <p class="px-1 text-[11px] leading-relaxed text-neutral-400">
            Dihitung dari <b>akad KPR</b>, satu unit per booking — booking yang berganti bank hanya dihitung
            sekali, memakai aplikasi terbarunya, sehingga satu unit tidak pernah muncul di dua kolom bank.
            Akad yang kemudian <b>batal/ditolak tidak ikut</b>. Definisinya sama persis dengan layar
            <b>Realisasi vs Budget</b>. Bar komposisi diskalakan terhadap bank terbesar agar bank bernilai kecil
            tetap terlihat; persentasenya tetap terhadap total.
        </p>
    </div>
</template>
