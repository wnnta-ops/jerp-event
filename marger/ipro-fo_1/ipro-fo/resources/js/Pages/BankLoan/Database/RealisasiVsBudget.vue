<script setup lang="ts">
// Realisasi vs Budget — target akad KPR per bulan dibandingkan realisasinya.
//
// "Budget" = target akad (unit & nilai) yang disusun manual dan diisi lewat import Excel;
// "realisasi" = akad yang benar-benar terjadi pada bulan itu. Keduanya datang dari
// KprTargetRealisasiController, yang juga memuat alasan kenapa realisasinya dihitung per
// booking terbaru dan kenapa akad yang batal tidak ikut.
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { BarChart3, Upload, Download, X, AlertTriangle } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

interface Baris {
    month: number; label: string;
    target_unit: number; realisasi_unit: number;
    target_value: number; realisasi_value: number;
    pencapaian: number | null; ada_realisasi: boolean;
}

const props = defineProps<{
    year: number;
    month: number;
    years: number[];
    rows: Baris[];
    ytd: {
        sampai: number; label: string;
        target_unit: number; realisasi_unit: number;
        target_value: number; realisasi_value: number; pencapaian: number | null;
    };
    total: {
        target_unit: number; realisasi_unit: number;
        target_value: number; realisasi_value: number;
        pencapaian: number | null; pencapaian_value: number | null;
    };
    tanpaTanggal: number;
}>();

const tahun = ref(props.year);
const bulan = ref(props.month);

function muat() {
    router.get(route('kpr.database.realisasi-budget'), { year: tahun.value, month: bulan.value },
        { preserveState: true, preserveScroll: true, replace: true });
}

/* Rupiah disingkat ke MILIAR hanya untuk DITAMPILKAN. Angka yang tersimpan tetap rupiah penuh —
   menyimpan yang sudah dibulatkan membuat selisih ratusan juta lenyap tanpa jejak. */
function miliar(v: number) {
    const m = Number(v ?? 0) / 1_000_000_000;
    if (m === 0) return '—';
    return m.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
function angka(v: number) { return Number(v ?? 0) === 0 ? '—' : Number(v).toLocaleString('id-ID'); }
function persen(v: number | null) { return v === null ? '—' : v + '%'; }

/* Satu bulan terpilih menyembunyikan sisanya, tetapi baris YTD & TOTAL tetap dihitung dari
   SELURUH tahun — itulah gunanya keduanya. */
const barisTampil = computed(() => bulan.value >= 1 && bulan.value <= 12
    ? props.rows.filter((r) => r.month === bulan.value)
    : props.rows);

const adaTarget = computed(() => props.total.target_unit > 0 || props.total.target_value > 0);

// ── Import target ──
const bukaImport = ref(false);
const formImport = useForm<{ year: number; file: File | null }>({ year: props.year, file: null });
function pilihBerkas(e: Event) { formImport.file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function kirimImport() {
    formImport.year = tahun.value;
    formImport.post(route('kpr.database.realisasi-budget.import'), {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { bukaImport.value = false; formImport.reset('file'); },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><BarChart3 class="h-6 w-6 text-primary-600" /> Realisasi vs Budget</h1>
                <p class="mt-1 text-sm text-neutral-500">Target akad KPR per bulan dibandingkan realisasinya.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select v-model.number="tahun" @change="muat" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model.number="bulan" @change="muat" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option :value="0">Semua Bulan (Analisa Tahunan)</option>
                    <option v-for="r in rows" :key="r.month" :value="r.month">{{ r.label }}</option>
                </select>
                <button type="button" @click="bukaImport = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-medium text-white hover:bg-primary-700"><Upload class="h-4 w-4" /> Import Target</button>
            </div>
        </div>

        <div class="card p-4">
            <p class="text-xs leading-relaxed text-neutral-500">
                <b>Analisa Tahunan {{ year }}:</b> Target <b>{{ angka(total.target_unit) }} unit / Rp {{ miliar(total.target_value) }} M</b> ·
                Realisasi {{ ytd.sampai ? ytd.label : 'YTD' }}
                <b>{{ angka(total.realisasi_unit) }} unit / Rp {{ miliar(total.realisasi_value) }} M</b>
                ({{ persen(total.pencapaian) }} unit · {{ persen(total.pencapaian_value) }} value).
                <span v-if="bulan === 0">Menampilkan seluruh 12 bulan.</span>
                <span v-else>Menampilkan bulan {{ rows.find((r) => r.month === bulan)?.label }} saja.</span>
            </p>
            <!-- Akad yang sudah terjadi tetapi tanggalnya kosong tidak bisa ditempatkan pada bulan
                 mana pun. Disebutkan, bukan didiamkan — kalau tidak, ketiadaannya terbaca sebagai
                 realisasi yang memang kurang. -->
            <p v-if="tanpaTanggal > 0" class="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{{ tanpaTanggal }} akad sudah terjadi tetapi <b>tanggal akadnya kosong</b>, sehingga tidak masuk bulan mana pun di tabel ini. Lengkapi tanggal akadnya agar ikut terhitung.</span>
            </p>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Bulan</th>
                        <th class="px-4 py-3 text-right">Target Unit</th>
                        <th class="px-4 py-3 text-right">Realisasi Unit</th>
                        <th class="px-4 py-3 text-right">Pencapaian</th>
                        <th class="px-4 py-3 text-right">Target Value (Rp M)</th>
                        <th class="px-4 py-3 text-right">Realisasi Value (Rp M)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="r in barisTampil" :key="r.month">
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-2.5">{{ r.label }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ angka(r.target_unit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ angka(r.realisasi_unit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono" :class="r.pencapaian !== null && r.pencapaian >= 100 ? 'font-semibold text-emerald-600 dark:text-emerald-400' : ''">{{ persen(r.pencapaian) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ miliar(r.target_value) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ miliar(r.realisasi_value) }}</td>
                        </tr>
                        <!-- Baris YTD disisipkan TEPAT sesudah bulan terakhir yang punya realisasi,
                             persis seperti rincian yang diminta. -->
                        <tr v-if="bulan === 0 && ytd.sampai === r.month" class="bg-primary-50/60 font-bold dark:bg-primary-900/15">
                            <td class="px-4 py-2.5">{{ ytd.label }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ angka(ytd.target_unit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ angka(ytd.realisasi_unit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ persen(ytd.pencapaian) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ miliar(ytd.target_value) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ miliar(ytd.realisasi_value) }}</td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800">
                    <tr>
                        <td class="px-4 py-3">TOTAL {{ year }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ angka(total.target_unit) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ angka(total.realisasi_unit) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ persen(total.pencapaian) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ miliar(total.target_value) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ miliar(total.realisasi_value) }}</td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-if="!adaTarget" title="Target belum diisi"
                description="Tabel ini membandingkan target akad dengan realisasinya. Unggah target setahun lewat tombol Import Target — realisasinya sudah terbaca otomatis dari akad KPR." />
        </div>

        <p class="px-1 text-[11px] leading-relaxed text-neutral-400">
            Realisasi dihitung dari <b>akad KPR</b> pada bulan tanggal akadnya, satu unit per booking
            (booking yang berganti bank hanya dihitung sekali, memakai aplikasi terbarunya). Akad yang
            kemudian <b>batal/ditolak tidak ikut</b>. Nilainya memakai plafon yang disetujui —
            angka yang sama dengan layar Proses KPR. Baris <b>{{ ytd.label }}</b> berhenti di bulan terakhir
            yang sudah ada realisasinya; baris <b>TOTAL {{ year }}</b> memakai target seluruh 12 bulan.
        </p>

        <!-- Modal Import Target -->
        <Teleport to="body">
            <div v-if="bukaImport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="bukaImport = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Upload class="h-5 w-5 text-primary-600" /> Import Target Akad {{ tahun }}</h2>
                        <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="bukaImport = false"><X class="h-4 w-4" /></button>
                    </div>

                    <p class="mb-3 text-sm text-neutral-600 dark:text-neutral-300">
                        Kolom: <b>BULAN</b> · <b>TARGET_UNIT</b> · <b>TARGET_VALUE</b>. Bulan boleh ditulis
                        nama (<i>Januari</i>), singkatan (<i>Jan</i>), atau angka (<i>1</i>).
                    </p>

                    <ul class="mb-3 space-y-1 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <li><b>TARGET_VALUE diisi rupiah penuh</b> (mis. 50600000000), bukan angka miliar. Layar yang menyingkatnya ke "Rp M".</li>
                        <li>Import ulang <b>memperbarui</b> target bulan yang sama, tidak menumpuk.</li>
                        <li>Satu baris bermasalah membatalkan seluruh berkas.</li>
                        <li>Berkas <b>.xlsx</b> atau <b>.csv</b>; format .xls lama tidak didukung.</li>
                    </ul>

                    <a :href="route('kpr.database.realisasi-budget.template')" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline"><Download class="h-4 w-4" /> Unduh Template (.xlsx)</a>

                    <input type="file" accept=".xlsx,.csv" class="mb-1 block w-full rounded-lg border border-neutral-300 p-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" @change="pilihBerkas" />
                    <p v-if="formImport.errors.file" class="mb-2 text-xs text-danger-600">{{ formImport.errors.file }}</p>

                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700" @click="bukaImport = false">Batal</button>
                        <button type="button" :disabled="! formImport.file || formImport.processing"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                            @click="kirimImport">{{ formImport.processing ? 'Mengimpor…' : 'Import' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
