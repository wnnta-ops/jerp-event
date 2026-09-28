<!-- COGS Baru → Perhitungan COGS -->
<script setup lang="ts">
/**
 * Lembar ringkasan COGS Baru. Susunannya mengikuti lampiran dari atas ke bawah:
 *   Perhitungan Lahan · Realisasi Berjalan · Budget per komponen · TOTAL / per m²
 *
 * SELURUH angka hasil hitungan datang dari server (prop `hitung`) dan tidak pernah dihitung ulang
 * di sini. Menghitungnya dua kali di dua bahasa adalah cara termudah membuat layar dan laporan
 * berbeda beberapa rupiah tanpa ada yang tahu mana yang benar.
 *
 * ── Dua grafik, dan mengapa bentuknya begitu ──
 * Keduanya menjawab "berapa PORSI masing-masing bagian dari satu keseluruhan", jadi bentuknya
 * batang bertumpuk 100% — bukan pai, yang membuat porsi berdekatan mustahil dibandingkan, dan
 * bukan batang terpisah, yang menyembunyikan bahwa semuanya menjumlah menjadi satu. Tiap segmen
 * diberi LABEL LANGSUNG dan tabel angkanya selalu ada di bawahnya, sehingga tidak ada satu nilai
 * pun yang hanya bisa dibaca lewat warna.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Save, Info, AlertTriangle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import Kepala from '@/Components/Cogs2/Kepala.vue';
import { KOMPONEN, VAR, LAHAN, rp, rp2, m2, persen, angka } from '@/Components/Cogs2/warna';

const props = defineProps<{
    periode: any;
    hitung: any;
    bisaUbah: boolean;
}>();

/* ── Perhitungan Lahan ────────────────────────────────────────────────────────────── */
const lahan = ref({
    luas_lahan: props.periode.luas_lahan,
    saleable: props.periode.saleable,
    rth: props.periode.rth,
    luas_bangunan: props.periode.luas_bangunan,
    jumlah_unit: props.periode.jumlah_unit,
    catatan: props.periode.catatan ?? '',
});
const menyimpan = ref(false);
const galat = ref<string | null>(null);

/** Pratinjau Jalan saat mengetik — angka resminya tetap dari server setelah disimpan. */
const jalanPratinjau = computed(() =>
    Math.round((Number(lahan.value.luas_lahan || 0) - Number(lahan.value.saleable || 0) - Number(lahan.value.rth || 0)) * 100) / 100);

function simpan() {
    menyimpan.value = true; galat.value = null;
    router.put(route('bs.cogs2.perhitungan.simpan', props.periode.id), { ...lahan.value }, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = e.saleable ?? e.luas_lahan ?? Object.values(e)[0] ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}

/* ── Grafik 1: komposisi lahan ────────────────────────────────────────────────────── */
const potonganLahan = computed(() => {
    const L = props.hitung.lahan;
    const total = Number(L.luas_lahan) || 0;
    if (total <= 0) return [];
    return [
        { nama: 'Saleable', nilai: Number(L.saleable), warna: LAHAN.saleable, gelap: true },
        { nama: 'RTH', nilai: Number(L.rth), warna: LAHAN.rth, gelap: true },
        { nama: 'Jalan', nilai: Math.max(0, Number(L.jalan)), warna: LAHAN.jalan, gelap: false },
    ].filter((s) => s.nilai > 0).map((s) => ({ ...s, porsi: s.nilai / total }));
});

/* ── Grafik 2: komposisi budget COGS ──────────────────────────────────────────────── */
const potonganCogs = computed(() => {
    const total = Number(props.hitung.total.budget) || 0;
    if (total <= 0) return [];
    return KOMPONEN.map((k) => ({
        nama: k.label, pendek: k.pendek, warna: VAR[k.kunci],
        nilai: Number(props.hitung.perhitungan[k.kunci].budget),
    })).filter((s) => s.nilai > 0).map((s) => ({ ...s, porsi: s.nilai / total }));
});

/** Posisi kiri tiap segmen pada batang 100%, dengan sela 0,2% antar segmen. */
function tata(potongan: Array<{ porsi: number }>) {
    const sela = 0.2;
    const sisa = 100 - sela * Math.max(0, potongan.length - 1);
    let x = 0;
    return potongan.map((s) => {
        const w = s.porsi * sisa;
        const kiri = x;
        x += w + sela;
        return { x: kiri, w };
    });
}
const tataLahan = computed(() => tata(potonganLahan.value));
const tataCogs = computed(() => tata(potonganCogs.value));

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <Kepala :periode="periode" layar="perhitungan">
        <!-- ── Perhitungan Lahan ──────────────────────────────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Perhitungan Lahan</p>

            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <div class="space-y-2.5">
                    <div class="flex items-center gap-3">
                        <label class="w-32 shrink-0 text-sm">Luas Lahan</label>
                        <MoneyInput v-model="lahan.luas_lahan" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-28 text-sm text-neutral-500">m²</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="w-32 shrink-0 text-sm">Saleable</label>
                        <MoneyInput v-model="lahan.saleable" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-28 text-sm tabular-nums text-neutral-500">m² · {{ persen(hitung.lahan.persen_saleable, 3) }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="w-32 shrink-0 text-sm">RTH</label>
                        <MoneyInput v-model="lahan.rth" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-28 text-sm tabular-nums text-neutral-500">m² · {{ persen(hitung.lahan.persen_rth, 3) }}</span>
                    </div>
                    <!-- Jalan: turunan, sengaja tidak bisa diketik. -->
                    <div class="flex items-center gap-3">
                        <label class="w-32 shrink-0 text-sm">Jalan</label>
                        <div class="flex-1 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-3 py-1.5 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900/60"
                             :class="jalanPratinjau < 0 ? 'text-rose-600 dark:text-rose-400' : ''">{{ m2(jalanPratinjau) }}</div>
                        <span class="w-28 text-sm tabular-nums text-neutral-500">m² · {{ persen(hitung.lahan.persen_jalan, 3) }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="w-32 shrink-0 text-sm">Luas Bangunan</label>
                        <MoneyInput v-model="lahan.luas_bangunan" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-28 text-sm text-neutral-500">m²</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="w-32 shrink-0 text-sm">Unit</label>
                        <input v-model.number="lahan.jumlah_unit" type="number" min="0" :disabled="! bisaUbah" :class="inputCls" class="flex-1 text-right tabular-nums" />
                        <span class="w-28 text-sm text-neutral-500">unit</span>
                    </div>
                    <p class="text-xs text-neutral-500">Jalan = Luas Lahan − Saleable − RTH, mengikuti lampiran. Tidak bisa diketik supaya ketiganya tidak pernah menyimpang dari Luas Lahan.</p>
                </div>

                <div class="space-y-4">
                    <!-- Grafik komposisi lahan -->
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Komposisi Lahan</p>
                        <svg v-if="potonganLahan.length" viewBox="0 0 100 9" preserveAspectRatio="none" class="mt-2 h-9 w-full" role="img"
                             :aria-label="'Komposisi lahan: ' + potonganLahan.map(s => s.nama + ' ' + persen(s.porsi)).join(', ')">
                            <rect v-for="(s, i) in potonganLahan" :key="s.nama"
                                  :x="tataLahan[i].x" y="0" :width="tataLahan[i].w" height="9" rx="0.6" :fill="s.warna" />
                        </svg>
                        <div v-if="potonganLahan.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                            <span v-for="s in potonganLahan" :key="s.nama" class="inline-flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: s.warna }"></span>
                                <b>{{ s.nama }}</b>
                                <span class="tabular-nums text-neutral-500">{{ m2(s.nilai) }} m² · {{ persen(s.porsi, 3) }}</span>
                            </span>
                        </div>
                        <p v-else class="mt-2 text-xs text-neutral-500">Luas Lahan belum diisi.</p>
                    </div>

                    <!-- Realisasi Berjalan -->
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Realisasi Berjalan</p>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                                <p class="text-xs text-neutral-500">Saleable Sudah Booked</p>
                                <p class="text-lg font-semibold tabular-nums">{{ m2(hitung.lahan.saleable_booked) }} <span class="text-sm font-normal text-neutral-500">m²</span></p>
                            </div>
                            <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                                <p class="text-xs text-neutral-500">Saleable Belum Booked</p>
                                <p class="text-lg font-semibold tabular-nums">{{ m2(hitung.lahan.saleable_belum) }} <span class="text-sm font-normal text-neutral-500">m²</span></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">Catatan</label>
                        <textarea v-model="lahan.catatan" :disabled="! bisaUbah" rows="2" :class="inputCls" class="mt-1 h-auto py-2"></textarea>
                    </div>
                </div>
            </div>

            <p v-if="galat" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galat }}</p>
            <div v-if="bisaUbah" class="mt-3 flex justify-end">
                <Button :disabled="menyimpan" @click="simpan"><Save class="h-4 w-4" /> {{ menyimpan ? 'Menyimpan…' : 'Simpan Perhitungan Lahan' }}</Button>
            </div>
        </div>

        <!-- ── Budget per komponen ────────────────────────────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Budget HPP per Komponen</p>

            <svg v-if="potonganCogs.length" viewBox="0 0 100 9" preserveAspectRatio="none" class="mt-3 h-9 w-full" role="img"
                 :aria-label="'Komposisi budget COGS: ' + potonganCogs.map(s => s.nama + ' ' + persen(s.porsi)).join(', ')">
                <rect v-for="(s, i) in potonganCogs" :key="s.nama"
                      :x="tataCogs[i].x" y="0" :width="tataCogs[i].w" height="9" rx="0.6" :fill="s.warna" />
            </svg>
            <div v-if="potonganCogs.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <span v-for="s in potonganCogs" :key="s.nama" class="inline-flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: s.warna }"></span>
                    <b>{{ s.nama }}</b>
                    <span class="tabular-nums text-neutral-500">{{ persen(s.porsi) }}</span>
                </span>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-3 py-2 text-left">Komponen</th>
                            <th class="px-3 py-2 text-right">Budget HPP FS</th>
                            <th class="px-3 py-2 text-right">per m² Saleable</th>
                            <th class="px-3 py-2 text-right">Budget HPP Belum Booked</th>
                            <th class="px-3 py-2 text-right">per m² Belum Booked</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="k in KOMPONEN" :key="k.kunci">
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: VAR[k.kunci] }"></span>
                                    <b>{{ k.label }}</b>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.perhitungan[k.kunci].budget) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp2(hitung.perhitungan[k.kunci].budget_per_m2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.perhitungan[k.kunci].realisasi) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp2(hitung.perhitungan[k.kunci].realisasi_per_m2) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <tr>
                            <td class="px-3 py-2.5">TOTAL COGS/HPP</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(hitung.total.budget) }}</td>
                            <td class="px-3 py-2.5"></td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(hitung.ringkas.belum_booked.total) }}</td>
                            <td class="px-3 py-2.5"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Housing tidak punya tarif per m² tunggal: luas bangunan tiap tipe berbeda. -->
            <p class="mt-3 flex items-start gap-2 rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    <b>Housing</b> tidak punya tarif per m² tunggal — tarifnya per TIPE rumah, karena luas bangunan tiap tipe berbeda.
                    Rinciannya ada di <b>Set Up Kategori COGS-Housing</b>. Di lampiran kolom ini pun diisi "-".
                </span>
            </p>
        </div>

        <!-- ── TOTAL per m² ───────────────────────────────────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">COGS per m²</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                    <p class="text-xs text-neutral-500">COGS GROSS</p>
                    <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ rp2(hitung.total.per_lahan) }}</p>
                    <p class="mt-1 text-xs text-neutral-500">÷ Luas Lahan {{ m2(hitung.lahan.luas_lahan) }} m²</p>
                </div>
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                    <p class="text-xs text-neutral-500">COGS Saleable</p>
                    <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ rp2(hitung.total.per_saleable) }}</p>
                    <p class="mt-1 text-xs text-neutral-500">÷ Saleable {{ m2(hitung.lahan.saleable) }} m²</p>
                </div>
                <div class="rounded-lg border border-sky-200 bg-sky-50/40 p-3 dark:border-sky-900 dark:bg-sky-950/20">
                    <p class="text-xs text-neutral-500">COGS Saleable Belum Booked</p>
                    <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ rp2(hitung.total.per_belum) }}</p>
                    <p class="mt-1 text-xs text-neutral-500">÷ Saleable Belum Booked {{ m2(hitung.lahan.saleable_belum) }} m²</p>
                </div>
            </div>
            <!--
                Tiga penyebut yang berbeda, masing-masing disebut namanya. Di lampiran, "COGS GROSS"
                dan "COGS Saleable" sama-sama dibagi Saleable Belum Booked sehingga memulangkan angka
                yang identik di bawah dua nama berbeda — satu angka per m² tanpa keterangan penyebutnya
                adalah sumber salah hitung yang paling mudah terjadi.
            -->
            <p class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Ketiganya memakai <b>penyebut yang berbeda</b> dan tidak boleh saling menggantikan.
                    Angka paling kanan adalah yang tampil di lampiran; dua lainnya ditambahkan supaya
                    penyebutnya tidak pernah tertukar diam-diam.
                </span>
            </p>
        </div>

        <!-- ── Rekonsiliasi luas ──────────────────────────────────────────────── -->
        <div class="card" :class="Math.abs(hitung.unitTotal.selisih_lt) < 0.005 && Math.abs(hitung.unitTotal.selisih_lb) < 0.005 ? '' : 'border-amber-300 dark:border-amber-900'">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Rekonsiliasi Luas</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-neutral-500">Saleable (diketik)</p><p class="text-lg font-semibold tabular-nums">{{ m2(hitung.lahan.saleable) }} m²</p></div>
                <div>
                    <p class="text-xs text-neutral-500">Σ LT unit ({{ angka(hitung.unitTotal.jumlah) }} unit)</p>
                    <p class="text-lg font-semibold tabular-nums" :class="Math.abs(hitung.unitTotal.selisih_lt) < 0.005 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">{{ m2(hitung.unitTotal.lt) }} m²</p>
                </div>
                <div><p class="text-xs text-neutral-500">Luas Bangunan (diketik)</p><p class="text-lg font-semibold tabular-nums">{{ m2(hitung.lahan.luas_bangunan) }} m²</p></div>
                <div>
                    <p class="text-xs text-neutral-500">Σ LB unit</p>
                    <p class="text-lg font-semibold tabular-nums" :class="Math.abs(hitung.unitTotal.selisih_lb) < 0.005 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">{{ m2(hitung.unitTotal.lb) }} m²</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-neutral-500">
                Selisih yang bukan nol berarti tarif per m² memakai pembagi yang berbeda dari luas yang benar-benar dibebani.
                Itu bukan kesalahan hitung, melainkan data luas yang belum cocok dengan angka yang diketik —
                dan satu-satunya tanda bahwa ada yang perlu dibetulkan.
            </p>
        </div>
    </Kepala>
</template>
