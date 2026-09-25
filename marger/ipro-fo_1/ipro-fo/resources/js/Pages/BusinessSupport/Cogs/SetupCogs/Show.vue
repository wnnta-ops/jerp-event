<!-- COGS → Set Up COGS: Perhitungan Lahan, pos biaya, dan budget per unit -->
<script setup lang="ts">
/**
 * Tata letaknya mengikuti lampiran, dari atas ke bawah:
 *
 *   1. Perhitungan Lahan  — Luas Lahan, Saleable, RTH, Jalan, Unit (+ persentasenya)
 *   2. Pos biaya          — nilai & tarif per m2, lalu TOTAL COGS / COGS GROSS / COGS Saleable
 *   3. Rekonsiliasi       — angka yang DIKETIK diadu dengan luas yang benar-benar terbaca
 *   4. Budget per unit    — satu baris per unit, satu kolom per pos biaya
 *
 * SELURUH angka hasil hitungan datang dari server (prop `hitung`) dan tidak pernah dihitung ulang
 * di sini. Menghitungnya dua kali di dua bahasa adalah cara paling mudah membuat layar dan
 * laporan berbeda beberapa rupiah tanpa ada yang tahu yang mana yang benar.
 *
 * Jalan ditampilkan sebagai kolom TERKUNCI: di lampiran ia rumus (Luas Lahan − Saleable − RTH),
 * bukan angka yang diketik. Membuatnya bisa diketik berarti jumlah ketiganya bisa berbeda dari
 * Luas Lahan tanpa ada yang menolak.
 */
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, LandPlot, Plus, Trash2, AlertTriangle, Info, Save } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

type Komponen = { id?: number | null; nama: string; dasar: 'tanah' | 'bangunan'; nilai: number };

const props = defineProps<{
    setup: {
        id: number; lokasi_id: number; lokasi_kode: string | null; lokasi_nama: string | null;
        tahap: string; cakupan: string;
        luas_lahan: number; saleable: number; rth: number; jumlah_unit: number | null;
        dasar_tanah: 'saleable' | 'unit'; termasuk_lebih_tanah: boolean; catatan: string | null;
    };
    komponen: Komponen[];
    hitung: {
        lahan: { luas_lahan: number; saleable: number; rth: number; jalan: number; persen_saleable: number | null; persen_rth: number | null; persen_jalan: number | null; jumlah_unit: number | null };
        komponen: Array<{ nama: string; dasar: string; nilai: number; pembagi: number | null; tarif: number | null; terbagi: number; selisih: number; alasan: string | null }>;
        pembagi: Record<string, { sumber: string; nilai: number; bobot: number; alasan: string | null }>;
        total: { total_cogs: number; cogs_gross: number | null; cogs_saleable: number | null; tarif_tanah: number; tarif_bangunan: number };
        unit: Array<{ no: number; kode: string; tipe: string | null; blok: string | null; no_unit: string | null; lt: number; lt_polos: number; lt_lebih: number; lb: number; bagian: number[]; total: number }>;
        unitKolom: number[];
        unitTotal: { lt: number; lt_polos: number; lt_lebih: number; lb: number; total: number; jumlah: number };
        rekon: { saleable: number; sigma_lt: number; selisih_m2: number; selisih_rp: number; jumlah_unit_input: number | null; jumlah_unit_baca: number; cocok: boolean };
    };
    dasarOpsi: Record<string, string>;
    galatFo: string | null;
    bisaUbah: boolean;
}>();

const rp = (v: number | null) => (v === null || v === undefined ? '—' : 'Rp ' + Math.round(Number(v)).toLocaleString('id-ID'));
/** Tarif per m2 ditampilkan dengan sen: angkanya kecil dan dikalikan luas, jadi pembulatan ke rupiah penuh terlihat di totalnya. */
const rp2 = (v: number | null) => (v === null || v === undefined ? '—' : 'Rp ' + Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const m2 = (v: number | null) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const persen = (v: number | null) => (v === null || v === undefined ? '—' : (Number(v) * 100).toFixed(3) + '%');

/* ── 1. Perhitungan Lahan ─────────────────────────────────────────────────────────── */
const lahan = ref({
    luas_lahan: props.setup.luas_lahan,
    saleable: props.setup.saleable,
    rth: props.setup.rth,
    jumlah_unit: props.setup.jumlah_unit,
    dasar_tanah: props.setup.dasar_tanah,
    termasuk_lebih_tanah: props.setup.termasuk_lebih_tanah,
    catatan: props.setup.catatan ?? '',
});
const simpanLahan = ref(false);
const galatLahan = ref<string | null>(null);

/** Pratinjau Jalan saat mengetik — angka resminya tetap dari server setelah disimpan. */
const jalanPratinjau = computed(() =>
    Math.round((Number(lahan.value.luas_lahan || 0) - Number(lahan.value.saleable || 0) - Number(lahan.value.rth || 0)) * 100) / 100);

function kirimLahan() {
    simpanLahan.value = true; galatLahan.value = null;
    router.put(route('bs.cogs.setupcogs.update', props.setup.id), { ...lahan.value }, {
        preserveScroll: true,
        onError: (e: any) => { galatLahan.value = e.saleable ?? e.luas_lahan ?? Object.values(e)[0] ?? 'Gagal menyimpan.'; },
        onFinish: () => { simpanLahan.value = false; },
    });
}

/* ── 2. Pos biaya ─────────────────────────────────────────────────────────────────── */
const baris = ref<Komponen[]>(props.komponen.map((k) => ({ ...k })));
const simpanPos = ref(false);
const galatPos = ref<string | null>(null);

// Server adalah pemilik angkanya: setelah simpan, prop berubah dan salinan lokal ikut disegarkan.
// Tanpa ini, baris yang baru dihapus di server akan tetap tampil di layar sampai halaman dimuat ulang.
watch(() => props.komponen, (v) => { baris.value = v.map((k) => ({ ...k })); }, { deep: true });

function tambahPos() { baris.value.push({ nama: '', dasar: 'tanah', nilai: 0 }); }
function hapusPos(i: number) { baris.value.splice(i, 1); }
function kirimPos() {
    simpanPos.value = true; galatPos.value = null;
    router.put(route('bs.cogs.setupcogs.komponen', props.setup.id), { komponen: baris.value }, {
        preserveScroll: true,
        onError: (e: any) => { galatPos.value = (Object.values(e)[0] as string) ?? 'Gagal menyimpan.'; },
        onFinish: () => { simpanPos.value = false; },
    });
}

function hapusSetup() {
    if (! confirm('Hapus Set Up COGS untuk ' + props.setup.cakupan + ' beserta seluruh pos biayanya?')) return;
    router.delete(route('bs.cogs.setupcogs.destroy', props.setup.id));
}

/* ── 4. Budget per unit ───────────────────────────────────────────────────────────── */
const adaBangunan = computed(() => props.hitung.komponen.some((k) => k.dasar === 'bangunan'));
const adaAlasan = computed(() => props.hitung.komponen.some((k) => k.alasan));

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cogs.setupcogs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Set Up COGS
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <LandPlot class="h-6 w-6 text-primary-600" /> {{ setup.lokasi_nama || ('Lokasi #' + setup.lokasi_id) }}
                </h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-neutral-500">
                    <Badge variant="primary">{{ setup.lokasi_kode || '—' }}</Badge>
                    <Badge v-if="setup.tahap" variant="neutral">Tahap {{ setup.tahap }}</Badge>
                    <span v-else>seluruh tahap</span>
                </p>
            </div>
            <Button v-if="bisaUbah" variant="danger" size="sm" @click="hapusSetup"><Trash2 class="h-4 w-4" /> Hapus Set Up</Button>
        </div>

        <div v-if="galatFo" class="card flex items-start gap-2 border-amber-300 bg-amber-50 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ galatFo }}</span>
        </div>

        <!-- ── 1. Perhitungan Lahan ─────────────────────────────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Perhitungan Lahan</p>

            <div class="mt-3 grid gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <label class="w-36 shrink-0 text-sm">Luas Lahan</label>
                        <MoneyInput v-model="lahan.luas_lahan" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-24 text-sm text-neutral-500">m²</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="w-36 shrink-0 text-sm">Saleable</label>
                        <MoneyInput v-model="lahan.saleable" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-24 text-sm tabular-nums text-neutral-500">m² · {{ persen(hitung.lahan.persen_saleable) }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="w-36 shrink-0 text-sm">RTH</label>
                        <MoneyInput v-model="lahan.rth" :decimals="2" :disabled="! bisaUbah" class="flex-1" />
                        <span class="w-24 text-sm tabular-nums text-neutral-500">m² · {{ persen(hitung.lahan.persen_rth) }}</span>
                    </div>
                    <!-- Jalan: turunan, sengaja tidak bisa diketik. -->
                    <div class="flex items-center gap-3">
                        <label class="w-36 shrink-0 text-sm">Jalan</label>
                        <div class="flex-1 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-3 py-1.5 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900/60"
                             :class="jalanPratinjau < 0 ? 'text-rose-600 dark:text-rose-400' : ''">
                            {{ m2(jalanPratinjau) }}
                        </div>
                        <span class="w-24 text-sm tabular-nums text-neutral-500">m² · {{ persen(hitung.lahan.persen_jalan) }}</span>
                    </div>
                    <p class="text-xs text-neutral-500">Jalan = Luas Lahan − Saleable − RTH, mengikuti lampiran. Tidak bisa diketik supaya ketiganya tidak pernah menyimpang dari Luas Lahan.</p>
                    <div class="flex items-center gap-3">
                        <label class="w-36 shrink-0 text-sm">Unit</label>
                        <input v-model.number="lahan.jumlah_unit" type="number" min="0" :disabled="! bisaUbah" :class="inputCls" class="flex-1 text-right tabular-nums" />
                        <span class="w-24 text-sm text-neutral-500">unit</span>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium">Pembagi pos biaya berdasar tanah</label>
                        <select v-model="lahan.dasar_tanah" :disabled="! bisaUbah" :class="inputCls" class="mt-1">
                            <option value="saleable">Saleable yang diketik (seperti lampiran)</option>
                            <option value="unit">Σ luas tanah unit (jumlah bagian = nilai, persis)</option>
                        </select>
                        <p class="mt-1 text-xs text-neutral-500">
                            Lampiran membagi dengan Saleable yang diketik. Bila Σ luas tanah unit berbeda dari angka itu,
                            jumlah seluruh bagian tidak akan sama dengan TOTAL COGS — selisihnya dilaporkan di bawah, bukan ditambal.
                        </p>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="lahan.termasuk_lebih_tanah" type="checkbox" :disabled="! bisaUbah" class="rounded border-neutral-300" />
                            Hitung <b>kelebihan tanah</b> sebagai bagian luas tanah unit
                        </label>
                        <p class="mt-1 text-xs text-neutral-500">
                            Σ luas tanah tanpa kelebihan: <b class="tabular-nums">{{ m2(hitung.unitTotal.lt_polos) }}</b> m² ·
                            kelebihan: <b class="tabular-nums">{{ m2(hitung.unitTotal.lt_lebih) }}</b> m².
                            Pilih yang jumlahnya cocok dengan Saleable.
                        </p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">Catatan</label>
                        <textarea v-model="lahan.catatan" :disabled="! bisaUbah" rows="2" :class="inputCls" class="mt-1 h-auto py-2"></textarea>
                    </div>
                </div>
            </div>

            <p v-if="galatLahan" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galatLahan }}</p>
            <div v-if="bisaUbah" class="mt-3 flex justify-end">
                <Button :disabled="simpanLahan" @click="kirimLahan"><Save class="h-4 w-4" /> {{ simpanLahan ? 'Menyimpan…' : 'Simpan Perhitungan Lahan' }}</Button>
            </div>
        </div>

        <!-- ── 2. Pos biaya ─────────────────────────────────────────────────────── -->
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Pos Biaya</p>
                <Button v-if="bisaUbah" variant="ghost" size="sm" @click="tambahPos"><Plus class="h-4 w-4" /> Tambah Pos</Button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-3 py-2 text-left">Pos Biaya</th>
                            <th class="px-3 py-2 text-left">Dibagi menurut</th>
                            <th class="px-3 py-2 text-right">Nilai</th>
                            <th class="px-3 py-2 text-right">Pembagi (m²)</th>
                            <th class="px-3 py-2 text-right">Tarif /m²</th>
                            <th v-if="bisaUbah" class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(b, i) in baris" :key="i">
                            <td class="px-3 py-2">
                                <input v-model="b.nama" :disabled="! bisaUbah" :class="inputCls" placeholder="mis. LAC Tanah" />
                            </td>
                            <td class="px-3 py-2">
                                <select v-model="b.dasar" :disabled="! bisaUbah" :class="inputCls">
                                    <option v-for="(lbl, k) in dasarOpsi" :key="k" :value="k">{{ lbl }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2"><MoneyInput v-model="b.nilai" :decimals="2" :disabled="! bisaUbah" /></td>
                            <td class="px-3 py-2 text-right tabular-nums text-neutral-500">{{ m2(hitung.komponen[i]?.pembagi ?? null) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">
                                <span v-if="hitung.komponen[i]?.tarif != null">{{ rp2(hitung.komponen[i].tarif) }}</span>
                                <span v-else class="text-amber-700 dark:text-amber-300">—</span>
                            </td>
                            <td v-if="bisaUbah" class="px-3 py-2 text-right">
                                <button type="button" class="text-neutral-400 hover:text-rose-600" @click="hapusPos(i)" :aria-label="'Hapus ' + (b.nama || 'pos')">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! baris.length">
                            <td :colspan="bisaUbah ? 6 : 5" class="px-3 py-6 text-center text-neutral-500">Belum ada pos biaya.</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 text-sm font-semibold dark:border-neutral-700">
                        <tr>
                            <td class="px-3 py-2" colspan="2">TOTAL COGS</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.total.total_cogs) }}</td>
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2"></td>
                            <td v-if="bisaUbah"></td>
                        </tr>
                        <tr class="font-normal">
                            <td class="px-3 py-1.5 text-neutral-500" colspan="2">COGS GROSS <span class="text-xs">(TOTAL ÷ Luas Lahan {{ m2(hitung.lahan.luas_lahan) }})</span></td>
                            <td class="px-3 py-1.5"></td><td class="px-3 py-1.5"></td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ rp2(hitung.total.cogs_gross) }}</td>
                            <td v-if="bisaUbah"></td>
                        </tr>
                        <tr class="font-normal">
                            <td class="px-3 py-1.5 text-neutral-500" colspan="2">COGS Saleable <span class="text-xs">(TOTAL ÷ Saleable {{ m2(hitung.lahan.saleable) }})</span></td>
                            <td class="px-3 py-1.5"></td><td class="px-3 py-1.5"></td>
                            <td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ rp2(hitung.total.cogs_saleable) }}</td>
                            <td v-if="bisaUbah"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Dua penyebut yang berbeda: sumber salah hitung yang paling mudah terjadi. -->
            <p class="mt-3 flex items-start gap-2 rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    <b>COGS GROSS</b> dibagi <b>Luas Lahan</b>, <b>COGS Saleable</b> dibagi <b>Saleable</b> — keduanya bukan angka yang sama
                    dan tidak boleh saling menggantikan. Jumlah tarif pos berdasar tanah:
                    <b class="tabular-nums">{{ rp2(hitung.total.tarif_tanah) }}</b>/m² tanah<span v-if="adaBangunan">,
                    berdasar bangunan: <b class="tabular-nums">{{ rp2(hitung.total.tarif_bangunan) }}</b>/m² bangunan</span>.
                </span>
            </p>

            <div v-if="adaAlasan" class="mt-2 space-y-1">
                <p v-for="(k, i) in hitung.komponen.filter((x) => x.alasan)" :key="i"
                   class="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span><b>{{ k.nama || 'Pos tanpa nama' }}</b> belum bisa dibagi ke unit: {{ k.alasan }}. Nilainya tetap dihitung ke TOTAL COGS.</span>
                </p>
            </div>

            <p v-if="galatPos" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galatPos }}</p>
            <div v-if="bisaUbah" class="mt-3 flex justify-end">
                <Button :disabled="simpanPos" @click="kirimPos"><Save class="h-4 w-4" /> {{ simpanPos ? 'Menyimpan…' : 'Simpan Pos Biaya' }}</Button>
            </div>
        </div>

        <!-- ── 3. Rekonsiliasi ──────────────────────────────────────────────────── -->
        <div class="card" :class="hitung.rekon.cocok ? '' : 'border-amber-300 dark:border-amber-900'">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Rekonsiliasi</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-neutral-500">Saleable (diketik)</p><p class="text-lg font-semibold tabular-nums">{{ m2(hitung.rekon.saleable) }} m²</p></div>
                <div><p class="text-xs text-neutral-500">Σ luas tanah unit</p><p class="text-lg font-semibold tabular-nums">{{ m2(hitung.rekon.sigma_lt) }} m²</p></div>
                <div>
                    <p class="text-xs text-neutral-500">Selisih luas</p>
                    <p class="text-lg font-semibold tabular-nums" :class="hitung.rekon.cocok ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">{{ m2(hitung.rekon.selisih_m2) }} m²</p>
                </div>
                <div>
                    <p class="text-xs text-neutral-500">TOTAL COGS − Σ budget unit</p>
                    <p class="text-lg font-semibold tabular-nums" :class="Math.abs(hitung.rekon.selisih_rp) < 0.005 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">{{ rp(hitung.rekon.selisih_rp) }}</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-neutral-500">
                Unit yang diketik: <b>{{ hitung.rekon.jumlah_unit_input ?? '—' }}</b> ·
                unit terbaca dari Front Office: <b>{{ hitung.rekon.jumlah_unit_baca }}</b>.
                Selisih rupiah yang bukan nol berarti tarif per m² memakai pembagi yang berbeda dari luas yang benar-benar dibebani —
                bukan kesalahan hitung, melainkan data luas yang belum cocok dengan angka yang diketik.
            </p>
        </div>

        <!-- ── 4. Budget per unit ───────────────────────────────────────────────── -->
        <div class="card overflow-x-auto p-0">
            <div class="flex flex-wrap items-center justify-between gap-2 p-4 pb-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    Budget per Unit — {{ hitung.unitTotal.jumlah }} unit
                </p>
                <p class="text-xs text-neutral-500">Luas per unit dibaca dari Front Office. Unit berstatus BATAL tidak ikut dibebani.</p>
            </div>

            <table v-if="hitung.unit.length" class="min-w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-3 py-2.5 text-left">No</th>
                        <th class="px-3 py-2.5 text-left">Kode Unit</th>
                        <th class="px-3 py-2.5 text-left">Blok / No</th>
                        <th class="px-3 py-2.5 text-left">Tipe</th>
                        <th class="px-3 py-2.5 text-right">LT (m²)</th>
                        <th class="px-3 py-2.5 text-right">LB (m²)</th>
                        <th v-for="(k, i) in hitung.komponen" :key="i" class="px-3 py-2.5 text-right">
                            {{ k.nama || 'Pos ' + (i + 1) }}
                            <span class="block text-[10px] font-normal normal-case opacity-70">per m² {{ k.dasar === 'bangunan' ? 'bangunan' : 'tanah' }}</span>
                        </th>
                        <th class="px-3 py-2.5 text-right">Total Budget</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="u in hitung.unit" :key="u.kode" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                        <td class="px-3 py-2 tabular-nums text-neutral-500">{{ u.no }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ u.kode }}</td>
                        <td class="px-3 py-2">{{ [u.blok, u.no_unit].filter(Boolean).join(' / ') || '—' }}</td>
                        <td class="px-3 py-2">{{ u.tipe ?? '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ m2(u.lt) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ m2(u.lb) }}</td>
                        <td v-for="(k, i) in hitung.komponen" :key="i" class="px-3 py-2 text-right tabular-nums">{{ rp(u.bagian[i] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ rp(u.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                    <tr>
                        <td class="px-3 py-2.5" colspan="4">Jumlah</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ m2(hitung.unitTotal.lt) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ m2(hitung.unitTotal.lb) }}</td>
                        <td v-for="(k, i) in hitung.komponen" :key="i" class="px-3 py-2.5 text-right tabular-nums">{{ rp(hitung.unitKolom[i] ?? 0) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(hitung.unitTotal.total) }}</td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-else title="Belum ada unit pada cakupan ini"
                        description="Budget per unit baru bisa dihitung setelah unit beserta luas tanah & luas bangunannya ada di Front Office." />
        </div>
    </div>
</template>
