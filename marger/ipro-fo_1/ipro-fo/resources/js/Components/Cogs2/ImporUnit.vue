<!-- COGS Baru → Rincian COGS: tambah unit massal (pilih dari modul Unit / impor CSV) & ekspor -->
<script setup lang="ts">
/**
 * Tombol "Tambah Unit Massal" beserta dialognya. Dua jalan masuk:
 *
 *   1. PILIH DARI MODUL UNIT (Set Up Sales) — daftar unit sungguhan dari Front Office pada lokasi
 *      & tahap yang dipilih, tinggal dicentang. Ini jalan utamanya.
 *   2. IMPOR CSV — untuk daftar yang datang dari luar sistem.
 *
 * ── Keduanya mengisi DRAF, bukan basis data ──
 * Tabel Rincian COGS disimpan sekaligus lewat satu tombol, dan simpanan itu MENGGANTI seluruh
 * daftar. Yang menulis langsung ke basis data akan tertimpa begitu operator menekan "Simpan
 * Rincian COGS" dengan draf lama di layarnya — tanpa galat apa pun. Karena itu komponen ini
 * memulangkan barisnya lewat `emit`, dan yang menyimpan tetap satu tombol yang sama. Seluruh
 * penjaga di jalur simpan (kode kembar, unit belum booked tidak boleh mengunci costing) pun
 * tetap berlaku tanpa ditulis dua kali.
 *
 * ── Daftar unitnya diambil saat dibuka, bukan saat halaman dimuat ──
 * Satu lokasi bisa berisi ratusan unit, dan hampir setiap kali layar Rincian dibuka daftarnya
 * tidak dipakai sama sekali. Prop-nya `Inertia::lazy`, dan diminta lewat muat-ulang sebagian.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Upload, Download, FileDown, X, TriangleAlert, CircleCheck, Plus, Search, RefreshCw, ListChecks } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import { dariCsv, keCsv, templateCsv, KOLOM, type BarisUnit } from './unitCsv';

const props = defineProps<{
    /** Baris yang sedang ada di draf — dipakai ekspor dan penghitungan kode kembar. */
    baris: Array<any>;
    /** Nama berkas ekspor tanpa akhiran, mis. "JRK3-001". */
    namaBerkas?: string;
    bisaUbah?: boolean;
    /** Periode yang sedang dibuka — dipakai memuat ulang daftar unit. */
    periodeId: number;
    /** Lokasi & tahap dari Front Office untuk penyaring pemilih. */
    foLokasi?: Array<{ id: number; kode: string; nama: string }>;
    foTahap?: Array<{ id: number; lokasi_id: number; kode: string; nama: string }>;
    /** Diisi Inertia hanya saat pemilihnya dibuka (prop lazy). */
    unitFo?: {
        rows: Array<any>;
        filters: { lokasi_id: number; tahap: string; cari: string };
        galat: string | null;
        total: number;
        terpotong: boolean;
    } | null;
    galatFo?: string | null;
    lokasiAwal?: number | null;
    tahapAwal?: string | null;
}>();

const emit = defineEmits<{ (e: 'impor', baris: BarisUnit[], mode: 'ganti' | 'tambah'): void }>();

const buka = ref(false);
const tab = ref<'unit' | 'csv'>('unit');
const mode = ref<'ganti' | 'tambah'>('tambah');

/* ── Pemilih dari modul Unit ──────────────────────────────────────────────────────── */
const fLokasi = ref<number | ''>('');
const fTahap = ref('');
const fCari = ref('');
const memuat = ref(false);
const dipilih = ref<Set<string>>(new Set());

const tahapPilihan = computed(() => (props.foTahap ?? []).filter((t) => t.lokasi_id === Number(fLokasi.value)));
const barisUnit = computed<any[]>(() => props.unitFo?.rows ?? []);
// Unit yang sudah ada di draf tetap DITAMPILKAN tetapi tidak bisa dicentang: menyembunyikannya
// membuat operator mengira unitnya belum pernah ditarik.
const bisaDipilih = computed(() => barisUnit.value.filter((u) => ! u.sudah));
const semuaTercentang = computed(() =>
    bisaDipilih.value.length > 0 && bisaDipilih.value.every((u) => dipilih.value.has(u.kode)));

function muat() {
    memuat.value = true;
    router.reload({
        only: ['unitFo'],
        data: { fo_lokasi: fLokasi.value || undefined, fo_tahap: fTahap.value, fo_cari: fCari.value },
        // `router.reload` sudah mempertahankan keadaan komponen dan posisi gulir dengan
        // sendirinya — dialognya tidak tertutup saat daftarnya dimuat ulang. Keduanya tidak
        // perlu disebut (dan memang ditolak tipenya).
        onFinish: () => { memuat.value = false; },
    });
}

function centangSemua() {
    if (semuaTercentang.value) {
        for (const u of bisaDipilih.value) dipilih.value.delete(u.kode);
    } else {
        for (const u of bisaDipilih.value) dipilih.value.add(u.kode);
    }
    dipilih.value = new Set(dipilih.value);
}
function centang(kode: string) {
    if (dipilih.value.has(kode)) dipilih.value.delete(kode); else dipilih.value.add(kode);
    dipilih.value = new Set(dipilih.value);
}

/**
 * Unit Front Office → baris draf Rincian COGS.
 *
 * LT memakai `luas_tanah` saja — SAMA dengan tombol "Tarik Unit dari Front Office" di atas.
 * Kalau yang satu menambahkan kelebihan tanah dan yang lain tidak, dua jalan masuk yang sama
 * akan menghasilkan luas yang berbeda untuk unit yang sama, dan selisihnya tidak akan pernah
 * muncul sebagai galat. Kelebihan tanahnya tetap DITAMPILKAN di daftar supaya terlihat.
 */
function keDraf(u: any): BarisUnit {
    return {
        no_spr: u.no_spr ?? null,
        nama_customer: u.nama_customer ?? null,
        unit_rumah: String(u.kode),
        lokasi: (props.foLokasi ?? []).find((l) => l.id === Number(fLokasi.value))?.kode ?? null,
        tahap: u.tahap ?? null,
        tipe: u.tipe ?? null,
        lt: Number(u.lt) || 0,
        lb: Number(u.lb) || 0,
        status: 'belum',
        costing: 'belum',
        booked_revenue: null,
    };
}

/* ── Impor CSV ────────────────────────────────────────────────────────────────────── */
const teks = ref('');
const inputRef = ref<HTMLInputElement | null>(null);
const hasilCsv = computed(() => (teks.value.trim() === '' ? null : dariCsv(teks.value)));
const galatCsv = computed(() => hasilCsv.value?.galat ?? []);

const siap = computed(() => (tab.value === 'unit'
    ? dipilih.value.size > 0
    : !! hasilCsv.value && galatCsv.value.length === 0 && hasilCsv.value.baris.length > 0));

const jumlahMasuk = computed(() => (tab.value === 'unit' ? dipilih.value.size : hasilCsv.value?.baris.length ?? 0));

watch(buka, (v) => {
    if (! v) return;
    tab.value = 'unit';
    teks.value = '';
    dipilih.value = new Set();
    mode.value = props.baris.length ? 'tambah' : 'ganti';
    fLokasi.value = props.lokasiAwal ?? '';
    fTahap.value = props.tahapAwal ?? '';
    fCari.value = '';
    muat();
});

const kodeAda = computed(() => new Set(props.baris.map((b: any) => String(b.unit_rumah ?? '').toLowerCase())));
const akanDilewati = computed(() => {
    if (mode.value !== 'tambah') return 0;
    const calon = tab.value === 'unit'
        ? bisaDipilih.value.filter((u) => dipilih.value.has(u.kode)).map((u) => String(u.kode))
        : (hasilCsv.value?.baris ?? []).map((b) => b.unit_rumah);

    return calon.filter((k) => kodeAda.value.has(k.toLowerCase())).length;
});

/* ── Ekspor & template ────────────────────────────────────────────────────────────── */
function unduh(isi: string, nama: string) {
    const blob = new Blob([isi], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nama;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}
const dasarNama = computed(() => (props.namaBerkas ? String(props.namaBerkas).replace(/[^\w.-]+/g, '-') : 'rincian-cogs'));
function unduhTemplate() { unduh(templateCsv(), 'template-rincian-cogs.csv'); }
function ekspor() { unduh(keCsv(props.baris as any), dasarNama.value + '-rincian-cogs.csv'); }

function pilihBerkas(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (! f) return;
    const r = new FileReader();
    r.onload = () => { teks.value = String(r.result ?? ''); };
    r.readAsText(f, 'utf-8');
}

function kirim() {
    if (! siap.value) return;
    const masuk = tab.value === 'unit'
        ? bisaDipilih.value.filter((u) => dipilih.value.has(u.kode)).map(keDraf)
        : (hasilCsv.value?.baris ?? []);
    emit('impor', masuk, mode.value);
    buka.value = false;
    if (inputRef.value) inputRef.value.value = '';
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const rp = (v: number) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <Button v-if="bisaUbah" variant="secondary" size="sm" @click="buka = true">
            <Upload class="h-4 w-4" /> Tambah Unit Massal
        </Button>
        <!-- Ekspor tersedia walau tanpa izin ubah: membaca daftar sendiri bukan perubahan. -->
        <Button variant="ghost" size="sm" :disabled="! baris.length" @click="ekspor">
            <Download class="h-4 w-4" /> Ekspor
        </Button>

        <Teleport to="body">
            <div v-if="buka" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="buka = false">
                <div class="flex max-h-[92vh] w-full max-w-5xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-3 border-b border-neutral-200 p-5 dark:border-neutral-800">
                        <div>
                            <h2 class="text-lg font-semibold">Tambah Unit Massal</h2>
                            <p class="mt-1 text-sm text-neutral-500">
                                Yang masuk adalah <b>draf</b> — angkanya baru tersimpan setelah Anda menekan
                                <b>Simpan Rincian COGS</b>.
                            </p>
                        </div>
                        <button type="button" class="text-neutral-400 hover:text-neutral-600" aria-label="Tutup" @click="buka = false">
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <!-- Dua jalan masuk -->
                    <div class="flex gap-1 border-b border-neutral-200 px-5 pt-3 dark:border-neutral-800">
                        <button v-for="t in [['unit', 'Pilih dari Modul Unit'], ['csv', 'Impor CSV']]" :key="t[0]"
                                type="button" class="rounded-t-lg px-4 py-2 text-sm font-medium transition"
                                :class="tab === t[0] ? 'bg-sky-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                :aria-current="tab === t[0] ? 'page' : undefined"
                                @click="tab = t[0] as any">{{ t[1] }}</button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-5">
                        <!-- ══ Pilih dari Modul Unit (Set Up Sales) ══ -->
                        <div v-if="tab === 'unit'" class="space-y-3">
                            <div v-if="galatFo" class="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" /><span>{{ galatFo }}</span>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-neutral-500">Lokasi</label>
                                    <select v-model="fLokasi" :class="inputCls" @change="fTahap = ''; muat()">
                                        <option value="">— semua lokasi belum dipilih —</option>
                                        <option v-for="l in (foLokasi ?? [])" :key="l.id" :value="l.id">{{ l.kode }} — {{ l.nama }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-neutral-500">Tahap</label>
                                    <select v-model="fTahap" :class="inputCls" :disabled="! fLokasi" @change="muat()">
                                        <option value="">Seluruh tahap</option>
                                        <option v-for="t in tahapPilihan" :key="t.id" :value="t.kode">{{ t.kode }} — {{ t.nama }}</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-neutral-500">Cari kode unit / blok / tipe</label>
                                    <div class="flex gap-2">
                                        <input v-model="fCari" :class="inputCls" placeholder="mis. XIX-23A" @keyup.enter="muat()" />
                                        <Button variant="secondary" size="sm" :disabled="memuat" @click="muat">
                                            <Search class="h-4 w-4" /> Cari
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="unitFo?.galat" class="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" /><span>{{ unitFo.galat }}</span>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-neutral-500">
                                    <b>{{ barisUnit.length }}</b> unit tampil ·
                                    <b>{{ dipilih.size }}</b> dicentang ·
                                    <b>{{ barisUnit.length - bisaDipilih.length }}</b> sudah ada di Rincian COGS
                                </p>
                                <Button variant="ghost" size="sm" :disabled="! bisaDipilih.length" @click="centangSemua">
                                    <ListChecks class="h-4 w-4" /> {{ semuaTercentang ? 'Batalkan semua' : 'Centang semua yang tampil' }}
                                </Button>
                            </div>

                            <!-- Batasnya DISEBUT, tidak dipotong diam-diam. -->
                            <p v-if="unitFo?.terpotong" class="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>Hanya <b>{{ barisUnit.length }}</b> unit pertama yang ditampilkan. Persempit dengan tahap atau kata pencarian supaya tidak ada yang terlewat.</span>
                            </p>

                            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                                        <tr>
                                            <th class="w-10 px-3 py-2"></th>
                                            <th class="px-3 py-2 text-left">No. Unit</th>
                                            <th class="px-3 py-2 text-left">Blok / Nomer</th>
                                            <th class="px-3 py-2 text-left">Tahap</th>
                                            <th class="px-3 py-2 text-left">Tipe</th>
                                            <th class="px-3 py-2 text-right">LT</th>
                                            <th class="px-3 py-2 text-right">LB</th>
                                            <th class="px-3 py-2 text-right">Harga Jual</th>
                                            <th class="px-3 py-2 text-left">Status</th>
                                            <th class="px-3 py-2 text-left">No. SPR / Customer</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                        <tr v-for="u in barisUnit" :key="u.kode"
                                            :class="u.sudah ? 'bg-neutral-50/70 dark:bg-neutral-900/40' : 'cursor-pointer hover:bg-sky-50/60 dark:hover:bg-sky-950/20'"
                                            @click="! u.sudah && centang(u.kode)">
                                            <td class="px-3 py-2">
                                                <input type="checkbox" :disabled="u.sudah" :checked="dipilih.has(u.kode)"
                                                       class="rounded border-neutral-300" :aria-label="'Pilih ' + u.kode"
                                                       @click.stop="centang(u.kode)" />
                                            </td>
                                            <td class="px-3 py-2 font-mono text-xs">
                                                {{ u.kode }}
                                                <span v-if="u.sudah" class="ml-1 rounded bg-neutral-200 px-1 text-[10px] text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">sudah ada</span>
                                            </td>
                                            <td class="px-3 py-2 text-xs">{{ [u.blok, u.no_unit].filter(Boolean).join(' / ') || '—' }}</td>
                                            <td class="px-3 py-2 text-xs">{{ u.tahap || '—' }}</td>
                                            <td class="px-3 py-2 text-xs">{{ u.tipe || '—' }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">
                                                {{ u.lt }}
                                                <!-- Kelebihan tanah TIDAK ikut ke draf (sama dengan tombol tarik di atas), tetapi ditampilkan supaya terlihat. -->
                                                <span v-if="u.lt_lebih > 0" class="text-xs text-amber-700 dark:text-amber-300" title="Kelebihan tanah — tidak ikut dibawa ke Rincian COGS">+{{ u.lt_lebih }}</span>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ u.lb }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-neutral-500">{{ rp(u.harga_jual) }}</td>
                                            <td class="px-3 py-2 text-xs">{{ u.status || '—' }}</td>
                                            <td class="px-3 py-2 text-xs text-neutral-500">
                                                <span v-if="u.no_spr || u.nama_customer">{{ [u.no_spr, u.nama_customer].filter(Boolean).join(' · ') }}</span>
                                                <span v-else>—</span>
                                            </td>
                                        </tr>
                                        <tr v-if="! barisUnit.length">
                                            <td colspan="10" class="px-3 py-8 text-center text-neutral-500">
                                                <span v-if="memuat">Memuat…</span>
                                                <span v-else-if="! fLokasi">Pilih lokasi lebih dulu.</span>
                                                <span v-else>Tidak ada unit pada lokasi &amp; tahap itu.</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <p class="rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                                Daftar ini adalah modul <b>Unit</b> di Set Up Sales. Unit ber-status <b>BATAL</b> tidak ditampilkan —
                                ia bukan lagi bagian dari lahan yang dijual, dan membebaninya mengecilkan tarif per m² seluruh unit lain.
                                Status booked &amp; pilihan costing tidak ikut dibawa: keduanya ditentukan di Rincian COGS.
                            </p>
                        </div>

                        <!-- ══ Impor CSV ══ -->
                        <div v-else class="space-y-4">
                            <div class="flex flex-wrap gap-2">
                                <Button variant="ghost" size="sm" @click="unduhTemplate"><FileDown class="h-4 w-4" /> Unduh Template</Button>
                                <Button variant="ghost" size="sm" :disabled="! baris.length" @click="ekspor"><Download class="h-4 w-4" /> Ekspor Daftar Sekarang</Button>
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700">
                                    <Upload class="h-4 w-4" /> Pilih Berkas CSV
                                    <input ref="inputRef" type="file" accept=".csv,text/csv,text/plain" class="hidden" @change="pilihBerkas" />
                                </label>
                            </div>

                            <p class="rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                                Kolomnya: <b>{{ KOLOM.map((k) => k.judul).join(' · ') }}</b>.
                                Pemisah kolom boleh titik koma, koma, atau TAB — jadi menempel langsung dari Excel juga bisa.
                                Angka ditulis dengan <b>koma desimal tanpa pemisah ribuan</b> (mis. <b>87,5</b>);
                                bentuk seperti <b>1.500</b> ditolak karena benar-benar ambigu — seribu lima ratus atau satu koma lima.
                            </p>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Tempel di sini</label>
                                <textarea v-model="teks" rows="8" spellcheck="false"
                                          placeholder="No. SPR;Nama Customer;Unit Rumah;Lokasi;Tahap;Tipe;LT;LB;Status;Pilihan Costing;Booked Revenue"
                                          class="w-full rounded-lg border border-neutral-300 bg-white p-3 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                            </div>

                            <div v-if="hasilCsv">
                                <div v-if="galatCsv.length" class="rounded-lg border border-danger-300 bg-danger-50 p-3 dark:border-danger-900 dark:bg-danger-950/30">
                                    <p class="flex items-center gap-2 text-sm font-semibold text-danger-700 dark:text-danger-300">
                                        <TriangleAlert class="h-4 w-4" /> {{ galatCsv.length }} baris bermasalah — tidak ada yang diimpor sampai semuanya beres
                                    </p>
                                    <ul class="mt-2 max-h-40 space-y-0.5 overflow-y-auto text-xs text-danger-700 dark:text-danger-300">
                                        <li v-for="(g, i) in galatCsv.slice(0, 50)" :key="i">Baris {{ g.baris }}: {{ g.pesan }}</li>
                                        <li v-if="galatCsv.length > 50" class="opacity-70">… dan {{ galatCsv.length - 50 }} lagi.</li>
                                    </ul>
                                </div>
                                <p v-else class="flex items-center gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">
                                    <CircleCheck class="h-4 w-4" /> {{ hasilCsv.baris.length }} unit terbaca, tidak ada yang bermasalah.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 p-5 dark:border-neutral-800">
                        <div v-if="siap" class="flex flex-wrap items-center gap-2 text-xs">
                            <label class="text-neutral-500">Perlakuan:</label>
                            <select v-model="mode" class="h-8 rounded-lg border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="tambah">Tambahkan — kode yang sudah ada dilewati</option>
                                <option value="ganti">Ganti seluruh daftar</option>
                            </select>
                            <span v-if="mode === 'tambah' && akanDilewati" class="text-amber-700 dark:text-amber-300">
                                {{ akanDilewati }} akan dilewati.
                            </span>
                            <span v-if="mode === 'ganti' && baris.length" class="text-amber-700 dark:text-amber-300">
                                {{ baris.length }} unit yang sekarang diganti seluruhnya — termasuk status booked &amp; pilihan costing-nya.
                            </span>
                        </div>
                        <div v-else />

                        <div class="flex gap-2">
                            <Button variant="ghost" @click="buka = false">Batal</Button>
                            <Button :disabled="! siap" @click="kirim">
                                <Plus class="h-4 w-4" /> Masukkan {{ jumlahMasuk }} Unit ke Draf
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </span>
</template>
