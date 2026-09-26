<!-- COGS Baru → Set Up Kategori COGS (Tanah / Housing / Infrastruktur / License Permit) -->
<script setup lang="ts">
/**
 * Satu layar untuk keempat komponen; kolom yang tampil diatur oleh KOLOM di bawah. Empat layar
 * terpisah berarti empat tempat yang harus diubah bersamaan, dan yang terlupa akan berperilaku
 * berbeda tanpa terlihat berbeda.
 *
 * ── Yang bisa diketik dan yang tidak ──
 * Hanya angka yang di lampiran memang diketik yang bisa disunting di sini. Nominal TANAH
 * (= luas × harga rata-rata), jumlah unit, budget per unit, budget per meter, dan seluruh angka
 * realisasi adalah TURUNAN — ditampilkan dengan latar bergaris dan tidak bisa diisi. Angka
 * turunan yang bisa diketik akan menyimpang dari rumusnya tanpa ada yang menolak.
 *
 * Housing punya DUA blok: master tipe rumah (nama + luas bangunan) di atas, dan baris SPK di
 * bawah. Keduanya disimpan dalam satu kiriman supaya tidak pernah ada keadaan di mana tipe sudah
 * berubah tetapi baris SPK-nya belum.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Save, Plus, Trash2, Info, AlertTriangle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import Kepala from '@/Components/Cogs2/Kepala.vue';
import { KOMPONEN, VAR, rp, rp2, m2, angka } from '@/Components/Cogs2/warna';

const props = defineProps<{
    periode: any;
    komponen: 'tanah' | 'housing' | 'infra' | 'license';
    kategoriBaris: Record<string, any[]>;
    tipeBaris: Array<{ id: number; nama: string; luas_bangunan: number }>;
    hitung: any;
    bisaUbah: boolean;
}>();

const def = computed(() => KOMPONEN.find((k) => k.kunci === props.komponen)!);

/** Kolom yang diketik per komponen — mengikuti lampiran, tidak lebih dan tidak kurang. */
const KOLOM: Record<string, Array<{ k: string; label: string; jenis: 'teks' | 'uang' | 'luas'; lebar?: string }>> = {
    tanah: [
        { k: 'lokasi', label: 'Lokasi', jenis: 'teks', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', jenis: 'teks', lebar: 'w-28' },
        { k: 'luas_tanah', label: 'Luas Tanah (m²)', jenis: 'luas' },
        { k: 'harga_rata2', label: 'Harga Rata-rata', jenis: 'uang' },
    ],
    housing: [
        { k: 'lokasi', label: 'Lokasi', jenis: 'teks', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', jenis: 'teks', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori', jenis: 'teks' },
        { k: 'no_spk', label: 'Nomor SPK', jenis: 'teks' },
        { k: 'nominal', label: 'Nominal Budget', jenis: 'uang' },
    ],
    infra: [
        { k: 'lokasi', label: 'Lokasi', jenis: 'teks', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', jenis: 'teks', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori', jenis: 'teks' },
        { k: 'no_spk', label: 'Nomor SPK', jenis: 'teks' },
        { k: 'nominal', label: 'Nominal', jenis: 'uang' },
    ],
    license: [
        { k: 'lokasi', label: 'Lokasi', jenis: 'teks', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', jenis: 'teks', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori', jenis: 'teks' },
        { k: 'nominal', label: 'Nominal', jenis: 'uang' },
    ],
};
const kolom = computed(() => KOLOM[props.komponen]);

/* ── Salinan lokal yang bisa disunting ────────────────────────────────────────────── */
const kosong = () => ({
    lokasi: props.periode.lokasi_kode ?? '', tahap: props.periode.tahap ?? '',
    kategori: '', no_spk: '', luas_tanah: 0, harga_rata2: 0, nominal: 0,
});
const baris = ref<any[]>([]);
const tipe = ref<any[]>([]);

// Server pemilik angkanya: setelah simpan, prop berubah dan salinan lokal ikut disegarkan.
// Tanpa ini, baris yang baru dihapus di server tetap tampil sampai halaman dimuat ulang.
function segarkan() {
    baris.value = (props.kategoriBaris?.[props.komponen] ?? []).map((b: any) => ({ ...kosong(), ...b }));
    tipe.value = (props.tipeBaris ?? []).map((t) => ({ ...t }));
}
segarkan();
watch(() => [props.kategoriBaris, props.tipeBaris, props.komponen], segarkan, { deep: true });

function tambah() { baris.value.push(kosong()); }
function hapus(i: number) { baris.value.splice(i, 1); }
function tambahTipe() { tipe.value.push({ nama: '', luas_bangunan: 0 }); }
function hapusTipe(i: number) { tipe.value.splice(i, 1); }

/** Nominal TANAH = luas × harga rata-rata. Dihitung juga di server; ini hanya pratinjau. */
const nominalBaris = (b: any) =>
    props.komponen === 'tanah' ? Math.round((Number(b.luas_tanah) || 0) * (Number(b.harga_rata2) || 0) * 100) / 100 : Number(b.nominal) || 0;
const totalNominal = computed(() => baris.value.reduce((n, b) => n + nominalBaris(b), 0));

const menyimpan = ref(false);
const galat = ref<string | null>(null);
function simpan() {
    menyimpan.value = true; galat.value = null;
    const muatan: any = { baris: baris.value };
    if (props.komponen === 'housing') muatan.tipe = tipe.value;
    router.put(route('bs.cogs2.kategori.simpan', [props.periode.id, props.komponen]), muatan, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = (Object.values(e)[0] as string) ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}

/** Baris SPK Housing beserta angka turunannya, dari server. */
const housingHitung = computed<any[]>(() => props.hitung.housing ?? []);
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const turunanCls = 'rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-2 py-1.5 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900/60';
</script>

<template>
    <Kepala :periode="periode" layar="kategori" :komponen="komponen">
        <!-- ── Blok tipe rumah (hanya Housing) ────────────────────────────────── -->
        <div v-if="komponen === 'housing'" class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Tipe Rumah &amp; Luas Bangunan</p>
                <Button v-if="bisaUbah" variant="ghost" size="sm" @click="tambahTipe"><Plus class="h-4 w-4" /> Tambah Tipe</Button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-3 py-2 text-left">No</th>
                            <th class="px-3 py-2 text-left">Kategori</th>
                            <th class="px-3 py-2 text-right">Luas Bangunan (m²)</th>
                            <th class="px-3 py-2 text-right">Jumlah Unit</th>
                            <th class="px-3 py-2 text-right">Budget HPP per Unit</th>
                            <th class="px-3 py-2 text-right">Budget HPP per Meter</th>
                            <th class="px-3 py-2 text-right">Total Realisasi HPP</th>
                            <th class="px-3 py-2 text-right">Realisasi per Unit</th>
                            <th class="px-3 py-2 text-right">Realisasi per Meter</th>
                            <th v-if="bisaUbah" class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(t, i) in tipe" :key="i">
                            <td class="px-3 py-2 tabular-nums text-neutral-500">{{ i + 1 }}</td>
                            <td class="px-3 py-2"><input v-model="t.nama" :disabled="! bisaUbah" :class="inputCls" placeholder="mis. CICA" /></td>
                            <td class="px-3 py-2"><MoneyInput v-model="t.luas_bangunan" :decimals="2" :disabled="! bisaUbah" /></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ angka(hitung.tipe[i]?.jumlah_unit) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.tipe[i]?.budget_per_unit) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ rp2(hitung.tipe[i]?.budget_per_meter) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.tipe[i]?.realisasi_total) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.tipe[i]?.realisasi_per_unit) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp2(hitung.tipe[i]?.realisasi_per_meter) }}</td>
                            <td v-if="bisaUbah" class="px-3 py-2 text-right">
                                <button type="button" class="text-neutral-400 hover:text-rose-600" :aria-label="'Hapus tipe ' + (t.nama || (i + 1))" @click="hapusTipe(i)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! tipe.length"><td :colspan="bisaUbah ? 10 : 9" class="px-3 py-6 text-center text-neutral-500">Belum ada tipe rumah.</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-3 flex items-start gap-2 rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Hanya <b>Kategori</b> dan <b>Luas Bangunan</b> yang diketik. Jumlah unit dihitung dari Rincian COGS,
                    budget per unit dari baris SPK di bawah, dan angka realisasinya dari Actual Costing COGS-Housing —
                    semuanya turunan, supaya layar ini tidak pernah berbeda dari Rincian COGS.
                </span>
            </p>

            <div v-if="hitung.tipe.some((t: any) => t.alasan)" class="mt-2 space-y-1">
                <p v-for="(t, i) in hitung.tipe.filter((x: any) => x.alasan)" :key="i"
                   class="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span><b>{{ t.nama || 'Tipe tanpa nama' }}</b>: {{ t.alasan }}.</span>
                </p>
            </div>
        </div>

        <!-- ── Baris kategori ─────────────────────────────────────────────────── -->
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: VAR[komponen] }"></span>
                    {{ komponen === 'housing' ? 'Baris SPK Housing' : 'Set Up Kategori COGS — ' + def.label }}
                </p>
                <Button v-if="bisaUbah" variant="ghost" size="sm" @click="tambah"><Plus class="h-4 w-4" /> Tambah Baris</Button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-3 py-2 text-left">No</th>
                            <th v-for="c in kolom" :key="c.k" class="px-3 py-2" :class="c.jenis === 'teks' ? 'text-left' : 'text-right'">{{ c.label }}</th>
                            <th v-if="komponen === 'tanah'" class="px-3 py-2 text-right">Nominal</th>
                            <template v-if="komponen === 'housing'">
                                <th class="px-3 py-2 text-right">Jumlah Unit</th>
                                <th class="px-3 py-2 text-right">Budget per Unit</th>
                                <th class="px-3 py-2 text-right">Nominal Realisasi</th>
                            </template>
                            <th v-if="bisaUbah" class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(b, i) in baris" :key="i">
                            <td class="px-3 py-2 tabular-nums text-neutral-500">{{ i + 1 }}</td>
                            <td v-for="c in kolom" :key="c.k" class="px-3 py-2" :class="c.lebar">
                                <input v-if="c.jenis === 'teks'" v-model="b[c.k]" :disabled="! bisaUbah" :class="inputCls" />
                                <MoneyInput v-else v-model="b[c.k]" :decimals="c.jenis === 'luas' ? 2 : 2" :disabled="! bisaUbah" />
                            </td>
                            <!-- Nominal Tanah: turunan (luas × harga), tidak bisa diketik. -->
                            <td v-if="komponen === 'tanah'" class="px-3 py-2">
                                <div :class="turunanCls">{{ rp(nominalBaris(b)) }}</div>
                            </td>
                            <template v-if="komponen === 'housing'">
                                <td class="px-3 py-2 text-right tabular-nums">{{ angka(housingHitung[i]?.jumlah_unit) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ rp(housingHitung[i]?.per_unit) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ rp(housingHitung[i]?.realisasi) }}</td>
                            </template>
                            <td v-if="bisaUbah" class="px-3 py-2 text-right">
                                <button type="button" class="text-neutral-400 hover:text-rose-600" :aria-label="'Hapus baris ' + (i + 1)" @click="hapus(i)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! baris.length">
                            <td :colspan="kolom.length + (komponen === 'tanah' ? 2 : komponen === 'housing' ? 4 : 1) + (bisaUbah ? 1 : 0)"
                                class="px-3 py-6 text-center text-neutral-500">Belum ada baris.</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <tr>
                            <td class="px-3 py-2.5" :colspan="kolom.length + (komponen === 'tanah' ? 1 : 0)">TOTAL</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalNominal) }}</td>
                            <template v-if="komponen === 'housing'">
                                <td class="px-3 py-2.5"></td><td class="px-3 py-2.5"></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(housingHitung.reduce((n: number, h: any) => n + Number(h.realisasi || 0), 0)) }}</td>
                            </template>
                            <td v-if="bisaUbah"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p v-if="galat" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galat }}</p>
            <div v-if="bisaUbah" class="mt-3 flex justify-end">
                <Button :disabled="menyimpan" @click="simpan"><Save class="h-4 w-4" /> {{ menyimpan ? 'Menyimpan…' : 'Simpan' }}</Button>
            </div>
        </div>

        <!-- ── Ringkasan komponen ini terhadap seluruh COGS ────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Posisi {{ def.label }} pada COGS</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-neutral-500">Budget komponen ini</p><p class="text-lg font-semibold tabular-nums">{{ rp(hitung.perhitungan[komponen].budget) }}</p></div>
                <div><p class="text-xs text-neutral-500">Budget seluruh komponen</p><p class="text-lg font-semibold tabular-nums">{{ rp(hitung.total.budget) }}</p></div>
                <div><p class="text-xs text-neutral-500">Actual Costing komponen ini</p><p class="text-lg font-semibold tabular-nums">{{ rp(hitung.total.aktual_per[komponen]) }}</p></div>
                <div>
                    <p class="text-xs text-neutral-500">Selisih Actual − Budget</p>
                    <p class="text-lg font-semibold tabular-nums"
                       :class="(hitung.total.aktual_per[komponen] - hitung.perhitungan[komponen].budget) < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">
                        {{ rp(hitung.total.aktual_per[komponen] - hitung.perhitungan[komponen].budget) }}
                    </p>
                </div>
            </div>
        </div>
    </Kepala>
</template>
