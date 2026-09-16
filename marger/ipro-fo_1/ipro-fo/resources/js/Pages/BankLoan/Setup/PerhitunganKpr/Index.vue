<script setup lang="ts">
/**
 * KPR → Set Up → Kategori Perhitungan KPR.
 *
 * Pindahan panel admin aplikasi "Kalkulator KPR Java Residence". Seluruh kendali aslinya ada
 * di sini — bank, plafon & tenor, skema bunga beserta tahapannya, slide promo, foto intro,
 * logo, dan bulan berlaku.
 *
 * SATU KENDALI SENGAJA TIDAK DIBAWA: gerbang "Kata Sandi Admin". Aplikasi aslinya berdiri
 * sendiri tanpa login, jadi kata sandi itu satu-satunya penjaganya. Di sini layarnya sudah
 * dijaga login Back Office + izin bankloan.setup.*; menambahkan pintu kedua berarti satu
 * kata sandi lagi yang harus diingat dan bisa hilang, tanpa menambah keamanan apa pun.
 * Nilai hash lamanya tetap disimpan apa adanya supaya berkas hasil "Unduh config.js" masih
 * bisa dipakai aplikasi aslinya.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus, Trash2, Upload, X, Download, Save, Building2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    config: any;
    bisaUbah: boolean;
    terakhir: string | null;
}>();

/** Salinan dalam supaya perubahan tidak menyentuh prop sampai disimpan. */
const cfg = reactive<any>(JSON.parse(JSON.stringify(props.config ?? { banks: [] })));
if (! Array.isArray(cfg.banks)) cfg.banks = [];

const idx = ref(0);
const bank = computed<any>(() => cfg.banks[idx.value] ?? null);
const menyimpan = ref(false);
const pesan = ref('');
const galat = ref<Record<string, string>>({});

watch(() => cfg.banks.length, () => { if (idx.value >= cfg.banks.length) idx.value = Math.max(0, cfg.banks.length - 1); });

/* ── Bank ─────────────────────────────────────────────────────────────────────────── */
function bankBaru() {
    const nama = window.prompt('Nama bank baru (singkat), mis. "Mandiri":');
    if (! nama) return;
    const lengkap = window.prompt('Nama lengkap bank (opsional):', '') ?? '';
    cfg.banks.push({
        id: 'bank_' + Date.now(),
        name: nama.trim(), fullName: lengkap.trim(), logoImage: '',
        plafon: { min: 100000000, max: 2000000000 },
        dpMinPercent: 0, dpDefaultPercent: 5, utjAmount: 0,
        tenor: { minYears: 1, maxYears: 30 },
        maxInstallmentRatio: 0.35,
        promo: { enabled: false, slide1Text: '', slide2Text: '', slide2Image: '' },
        schemes: [{ id: 'anuitas_' + Date.now(), type: 'anuitas', label: 'Anuitas Tetap', ratePercent: 9.5, minTenorYears: 0 }],
    });
    idx.value = cfg.banks.length - 1;
}

function hapusBank() {
    if (cfg.banks.length <= 1) { window.alert('Tidak bisa menghapus — minimal harus ada 1 bank.'); return; }
    if (! window.confirm(`Yakin hapus bank "${bank.value.name}"? Semua skema dan pengaturannya ikut hilang setelah disimpan.`)) return;
    cfg.banks.splice(idx.value, 1);
    idx.value = Math.max(0, idx.value - 1);
}

/* ── Skema ────────────────────────────────────────────────────────────────────────── */
const TIPE = [
    { nilai: 'fixed_floating', label: 'Fixed lalu Floating' },
    { nilai: 'tiered', label: 'Fixed & Cap Berjenjang' },
    { nilai: 'anuitas', label: 'Anuitas Tetap' },
    { nilai: 'efektif', label: 'Efektif / Menurun' },
];

function skemaBawaan(tipe: string, label?: string, id?: string): any {
    const dasar = { id: id || 'scheme_' + Date.now(), type: tipe, minTenorYears: 0 };
    if (tipe === 'fixed_floating') return { ...dasar, label: label || 'Fixed lalu Floating', fixedYears: 3, fixedRatePercent: 3.65, floatingRatePercent: 11.5 };
    if (tipe === 'tiered') {
        return { ...dasar, label: label || 'Fixed & Cap Berjenjang', floatingRatePercent: 11.5,
            tiers: [{ years: 3, ratePercent: 5.5, tierLabel: 'Fixed' }, { years: 2, ratePercent: 8.5, tierLabel: 'Fixed' }, { years: 3, ratePercent: 9.95, tierLabel: 'Cap' }] };
    }

    return { ...dasar, label: label || (tipe === 'efektif' ? 'Efektif Menurun' : 'Anuitas Tetap'), ratePercent: 9.5 };
}

function tambahSkema() { bank.value.schemes.push(skemaBawaan('anuitas', 'Skema Baru')); }
function hapusSkema(i: number) {
    if (bank.value.schemes.length <= 1) { window.alert('Setiap bank harus punya minimal satu skema bunga.'); return; }
    bank.value.schemes.splice(i, 1);
}
/** Ganti tipe = ganti bentuk field-nya. Nama tampilan & id dipertahankan supaya
 *  skema yang sama tidak kehilangan identitasnya hanya karena tipenya dikoreksi. */
function gantiTipe(i: number, tipe: string) {
    const s = bank.value.schemes[i];
    bank.value.schemes[i] = skemaBawaan(tipe, s.label, s.id);
}
function tambahTahap(i: number) {
    const s = bank.value.schemes[i];
    if (! Array.isArray(s.tiers)) s.tiers = [];
    s.tiers.push({ years: 1, ratePercent: 8, tierLabel: 'Fixed' });
}
function hapusTahap(i: number, t: number) { bank.value.schemes[i].tiers.splice(t, 1); }

/* ── Gambar ───────────────────────────────────────────────────────────────────────── */
const mengunggah = ref('');
async function unggah(e: Event, pasang: (url: string) => void, kunci: string) {
    const input = e.target as HTMLInputElement;
    const berkas = input.files?.[0];
    if (! berkas) return;
    mengunggah.value = kunci;
    galat.value = {};
    try {
        const data = new FormData();
        data.append('gambar', berkas);
        const res = await window.axios.post(route('kpr.setup.perhitungan.gambar'), data);
        pasang(res.data.url);
    } catch (err: any) {
        galat.value.gambar = err?.response?.data?.message ?? 'Gambar gagal diunggah.';
    } finally {
        mengunggah.value = '';
        input.value = '';
    }
}

/* ── Angka berpemisah ribuan ──────────────────────────────────────────────────────── */
const angka = (s: any) => parseInt(String(s).replace(/[^\d]/g, ''), 10) || 0;
const ribuan = (n: any) => (Number(n) ? Number(n).toLocaleString('id-ID') : '');
function ketikPlafon(e: Event, kunci: 'min' | 'max') { bank.value.plafon[kunci] = angka((e.target as HTMLInputElement).value); }

/** Rasio disimpan 0–1 tetapi diisi sebagai persen — persis panel aslinya. */
const rasioPersen = computed({
    get: () => Math.round((Number(bank.value?.maxInstallmentRatio ?? 0)) * 100),
    set: (v: number) => { bank.value.maxInstallmentRatio = (Number(v) || 0) / 100; },
});

/* ── Simpan ───────────────────────────────────────────────────────────────────────── */
function simpan() {
    menyimpan.value = true;
    pesan.value = '';
    galat.value = {};
    router.put(route('kpr.setup.perhitungan.update'), JSON.parse(JSON.stringify(cfg)), {
        preserveScroll: true,
        onSuccess: () => { pesan.value = 'Pengaturan tersimpan. Layar Kalkulator KPR langsung memakai angka ini.'; },
        onError: (e: any) => { galat.value = e; },
        onFinish: () => { menyimpan.value = false; },
    });
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <Building2 class="h-6 w-6 text-primary-600" /> Kategori Perhitungan KPR
                </h1>
                <p class="mt-1 text-sm text-neutral-500">
                    Ketentuan yang dipakai layar <b>Kalkulator KPR</b>: bank, plafon &amp; tenor, skema bunga, slide promo, dan foto tampilan.
                    <span v-if="terakhir"> Terakhir diubah {{ terakhir }}.</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a :href="route('kpr.setup.perhitungan.ekspor')" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                    <Download class="h-4 w-4" /> Unduh config.js
                </a>
                <Button v-if="bisaUbah" :disabled="menyimpan" @click="simpan">
                    <template #icon-left><Save class="h-4 w-4" /></template>
                    {{ menyimpan ? 'Menyimpan…' : 'Simpan Pengaturan' }}
                </Button>
            </div>
        </div>

        <div v-if="pesan" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ pesan }}
        </div>
        <div v-if="Object.keys(galat).length" class="rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-200">
            <p class="font-semibold">Ada isian yang belum benar:</p>
            <ul class="mt-1 list-inside list-disc">
                <li v-for="(g, k) in galat" :key="k">{{ g }}</li>
            </ul>
        </div>
        <div v-if="! bisaUbah" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
            Anda hanya bisa melihat pengaturan ini. Mengubahnya butuh izin <b>bankloan.setup.manage</b>.
        </div>

        <!-- ══ Bank ══ -->
        <div class="card p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide">Bank</h2>
            <p class="mt-1 text-sm text-neutral-500">Setiap bank punya plafon, DP, tenor, skema bunga, dan logo sendiri.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button v-for="(b, i) in cfg.banks" :key="b.id" type="button"
                        class="rounded-lg border px-3 py-2 text-left text-sm transition"
                        :class="i === idx
                            ? 'border-primary-600 bg-primary-600 text-white'
                            : 'border-neutral-300 hover:border-primary-500 dark:border-neutral-700'"
                        @click="idx = i">
                    <span class="block font-semibold">{{ b.name }}</span>
                    <span class="block text-xs opacity-80">{{ b.fullName || '—' }}</span>
                </button>
                <button v-if="bisaUbah" type="button" class="rounded-lg border border-dashed border-neutral-400 px-3 py-2 text-sm font-medium text-neutral-600 hover:border-primary-500 hover:text-primary-600 dark:border-neutral-600 dark:text-neutral-300" @click="bankBaru">
                    <Plus class="mr-1 inline h-4 w-4" />Tambah Bank
                </button>
            </div>
        </div>

        <template v-if="bank">
            <!-- ══ Identitas bank ══ -->
            <div class="card p-5">
                <h2 class="text-sm font-bold uppercase tracking-wide">Identitas Bank — {{ bank.name }}</h2>
                <p class="mt-1 text-sm text-neutral-500">Nama, nama lengkap, dan logo yang tampil di layar pilih bank.</p>
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="pk-label" for="pk-nama">Nama Bank (singkat, mis. BTN)</label>
                        <input id="pk-nama" v-model="bank.name" :disabled="! bisaUbah" type="text" class="pk-input" />
                    </div>
                    <div>
                        <label class="pk-label" for="pk-lengkap">Nama Lengkap</label>
                        <input id="pk-lengkap" v-model="bank.fullName" :disabled="! bisaUbah" type="text" class="pk-input" />
                    </div>
                </div>
                <div class="mt-4">
                    <span class="pk-label">Logo Bank</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <img v-if="bank.logoImage" :src="bank.logoImage" alt="" class="h-20 max-w-[180px] rounded border border-neutral-200 bg-white object-contain p-2 dark:border-neutral-700" />
                        <label v-if="bisaUbah" class="pk-unggah">
                            <Upload class="h-4 w-4" />{{ mengunggah === 'logo' ? 'Mengunggah…' : 'Pilih gambar' }}
                            <input type="file" accept="image/*" class="hidden" @change="(e) => unggah(e, (u) => (bank.logoImage = u), 'logo')" />
                        </label>
                        <button v-if="bisaUbah && bank.logoImage" type="button" class="pk-hapus" @click="bank.logoImage = ''"><X class="h-3.5 w-3.5" />Hapus logo</button>
                    </div>
                </div>
                <button v-if="bisaUbah" type="button" class="mt-4 rounded-lg border border-danger-400 px-3 py-2 text-xs font-semibold text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30" @click="hapusBank">
                    <Trash2 class="mr-1 inline h-3.5 w-3.5" />Hapus Bank Ini
                </button>
            </div>

            <!-- ══ Plafon & tenor ══ -->
            <div class="card p-5">
                <h2 class="text-sm font-bold uppercase tracking-wide">Plafon &amp; Tenor</h2>
                <p class="mt-1 text-sm text-neutral-500">Batas jumlah pinjaman dan lama cicilan yang tersedia.</p>
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="pk-label" for="pk-pmin">Plafon minimum</label>
                        <div class="pk-uang"><span>Rp</span><input id="pk-pmin" :value="ribuan(bank.plafon.min)" :disabled="! bisaUbah" type="text" inputmode="numeric" @input="(e) => ketikPlafon(e, 'min')" /></div>
                    </div>
                    <div>
                        <label class="pk-label" for="pk-pmax">Plafon maksimum</label>
                        <div class="pk-uang"><span>Rp</span><input id="pk-pmax" :value="ribuan(bank.plafon.max)" :disabled="! bisaUbah" type="text" inputmode="numeric" @input="(e) => ketikPlafon(e, 'max')" /></div>
                    </div>
                    <div>
                        <label class="pk-label" for="pk-utj">UTJ awal (Rp)</label>
                        <div class="pk-uang"><span>Rp</span><input id="pk-utj" :value="ribuan(bank.utjAmount)" :disabled="! bisaUbah" type="text" inputmode="numeric" @input="(e) => (bank.utjAmount = angka((e.target as HTMLInputElement).value))" /></div>
                    </div>
                    <div>
                        <label class="pk-label" for="pk-tmin">Tenor minimum (tahun)</label>
                        <input id="pk-tmin" v-model.number="bank.tenor.minYears" :disabled="! bisaUbah" type="number" min="1" max="50" class="pk-input" />
                    </div>
                    <div>
                        <label class="pk-label" for="pk-tmax">Tenor maksimum (tahun)</label>
                        <input id="pk-tmax" v-model.number="bank.tenor.maxYears" :disabled="! bisaUbah" type="number" min="1" max="50" class="pk-input" />
                    </div>
                    <div>
                        <label class="pk-label" for="pk-dp">DP Otomatis (%) — dipakai semua perhitungan</label>
                        <input id="pk-dp" v-model.number="bank.dpDefaultPercent" :disabled="! bisaUbah" type="number" min="0" max="90" step="0.1" class="pk-input" />
                    </div>
                    <div>
                        <label class="pk-label" for="pk-rasio">Rasio maksimum angsuran/gaji (%)</label>
                        <input id="pk-rasio" v-model.number="rasioPersen" :disabled="! bisaUbah" type="number" min="1" max="100" class="pk-input" />
                        <p class="mt-1 text-xs text-neutral-500">Tersimpan sebagai catatan ketentuan bank. Gaji minimum di kalkulator memakai <b>2× angsuran tertinggi</b>, sama seperti aplikasi aslinya — rasio ini tidak ikut menghitungnya.</p>
                    </div>
                </div>
            </div>

            <!-- ══ Skema bunga ══ -->
            <div class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-bold uppercase tracking-wide">Skema Bunga — {{ bank.name }}</h2>
                        <p class="mt-1 text-sm text-neutral-500">Skema yang bisa dipilih pengunjung untuk bank ini.</p>
                    </div>
                    <Button v-if="bisaUbah" variant="secondary" @click="tambahSkema"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah skema</Button>
                </div>

                <div class="mt-4 space-y-4">
                    <div v-for="(s, i) in bank.schemes" :key="s.id" class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="pk-label" :for="`pk-tipe-${i}`">Tipe skema</label>
                                    <select :id="`pk-tipe-${i}`" :value="s.type" :disabled="! bisaUbah" class="pk-input" @change="(e) => gantiTipe(i, (e.target as HTMLSelectElement).value)">
                                        <option v-for="t in TIPE" :key="t.nilai" :value="t.nilai">{{ t.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="pk-label" :for="`pk-mintenor-${i}`">Tenor minimum khusus skema ini (tahun)</label>
                                    <input :id="`pk-mintenor-${i}`" v-model.number="s.minTenorYears" :disabled="! bisaUbah" type="number" min="0" max="50" class="pk-input" />
                                    <p class="mt-1 text-xs text-neutral-500">Isi 0 kalau mengikuti tenor minimum umum di atas.</p>
                                </div>
                            </div>
                            <button v-if="bisaUbah" type="button" class="pk-hapus" @click="hapusSkema(i)"><Trash2 class="h-3.5 w-3.5" />Hapus skema</button>
                        </div>

                        <div class="mt-3">
                            <label class="pk-label" :for="`pk-label-${i}`">Nama tampilan</label>
                            <input :id="`pk-label-${i}`" v-model="s.label" :disabled="! bisaUbah" type="text" class="pk-input" />
                        </div>

                        <!-- Fixed lalu Floating -->
                        <div v-if="s.type === 'fixed_floating'" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div><label class="pk-label" :for="`pk-fy-${i}`">Lama fixed (tahun)</label><input :id="`pk-fy-${i}`" v-model.number="s.fixedYears" :disabled="! bisaUbah" type="number" step="0.5" min="0" class="pk-input" /></div>
                            <div><label class="pk-label" :for="`pk-fr-${i}`">Bunga fixed (%/th)</label><input :id="`pk-fr-${i}`" v-model.number="s.fixedRatePercent" :disabled="! bisaUbah" type="number" step="0.01" min="0" class="pk-input" /></div>
                            <div><label class="pk-label" :for="`pk-fl-${i}`">Bunga floating (%/th)</label><input :id="`pk-fl-${i}`" v-model.number="s.floatingRatePercent" :disabled="! bisaUbah" type="number" step="0.01" min="0" class="pk-input" /></div>
                        </div>

                        <!-- Berjenjang -->
                        <div v-else-if="s.type === 'tiered'" class="mt-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Tahapan bunga, berurutan dari awal tenor</p>
                            <div class="mt-2 space-y-2">
                                <div v-for="(t, ti) in s.tiers" :key="ti" class="grid grid-cols-1 items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                    <div><label class="pk-label" :for="`pk-ty-${i}-${ti}`">Lama (tahun)</label><input :id="`pk-ty-${i}-${ti}`" v-model.number="t.years" :disabled="! bisaUbah" type="number" step="0.5" min="0" class="pk-input" /></div>
                                    <div><label class="pk-label" :for="`pk-tr-${i}-${ti}`">Bunga (%/th)</label><input :id="`pk-tr-${i}-${ti}`" v-model.number="t.ratePercent" :disabled="! bisaUbah" type="number" step="0.01" min="0" class="pk-input" /></div>
                                    <div><label class="pk-label" :for="`pk-tl-${i}-${ti}`">Label (Fixed/Cap)</label><input :id="`pk-tl-${i}-${ti}`" v-model="t.tierLabel" :disabled="! bisaUbah" type="text" class="pk-input" /></div>
                                    <button v-if="bisaUbah" type="button" class="pk-hapus mb-0.5" @click="hapusTahap(i, ti)"><X class="h-3.5 w-3.5" />Hapus</button>
                                </div>
                            </div>
                            <button v-if="bisaUbah" type="button" class="mt-2 rounded-lg border border-dashed border-neutral-400 px-3 py-1.5 text-xs font-medium hover:border-primary-500 hover:text-primary-600 dark:border-neutral-600" @click="tambahTahap(i)">
                                <Plus class="mr-1 inline h-3.5 w-3.5" />Tambah tahap
                            </button>
                            <div class="mt-3 max-w-xs">
                                <label class="pk-label" :for="`pk-tf-${i}`">Bunga floating setelah semua tahap (%/th)</label>
                                <input :id="`pk-tf-${i}`" v-model.number="s.floatingRatePercent" :disabled="! bisaUbah" type="number" step="0.01" min="0" class="pk-input" />
                            </div>
                        </div>

                        <!-- Anuitas / Efektif -->
                        <div v-else class="mt-3 max-w-xs">
                            <label class="pk-label" :for="`pk-rate-${i}`">Bunga (%/th)</label>
                            <input :id="`pk-rate-${i}`" v-model.number="s.ratePercent" :disabled="! bisaUbah" type="number" step="0.01" min="0" class="pk-input" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ Slide promo ══ -->
            <div class="card p-5">
                <h2 class="text-sm font-bold uppercase tracking-wide">Slide Promo — {{ bank.name }}</h2>
                <p class="mt-1 text-sm text-neutral-500">
                    Opsional. Kalau diaktifkan, setelah pengunjung memilih logo bank ini muncul dulu 2 slide sebelum masuk kalkulator.
                    Slide tetap tayang sampai tombol "Lewati" ditekan — tidak ada durasi otomatis.
                </p>
                <label class="mt-3 flex w-fit items-center gap-2 text-sm">
                    <input v-model="bank.promo.enabled" :disabled="! bisaUbah" type="checkbox" class="h-4 w-4 rounded" />
                    Aktifkan slide promo untuk bank ini
                </label>
                <div class="mt-3 space-y-3">
                    <div>
                        <label class="pk-label" for="pk-promo1">Teks Slide 1 (kata-kata pembuka)</label>
                        <textarea id="pk-promo1" v-model="bank.promo.slide1Text" :disabled="! bisaUbah" rows="5" class="pk-input pk-mono" placeholder="mis. Promo Spesial Bulan Ini!"></textarea>
                        <p class="mt-1 text-xs text-neutral-500">Tekan Enter untuk baris baru. Apit kata dengan *bintang* untuk menebalkan, mis. *KPR PASTI BTN*.</p>
                    </div>
                    <div>
                        <label class="pk-label" for="pk-promo2">Teks Slide 2 (opsional, muncul di atas gambar kalau ada)</label>
                        <textarea id="pk-promo2" v-model="bank.promo.slide2Text" :disabled="! bisaUbah" rows="3" class="pk-input pk-mono" placeholder="mis. DP 0% + Bunga 2.99%"></textarea>
                    </div>
                    <div>
                        <span class="pk-label">Gambar Slide 2 (opsional — tampil utuh)</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <img v-if="bank.promo.slide2Image" :src="bank.promo.slide2Image" alt="" class="h-28 max-w-[220px] rounded border border-neutral-200 object-contain dark:border-neutral-700" />
                            <label v-if="bisaUbah" class="pk-unggah">
                                <Upload class="h-4 w-4" />{{ mengunggah === 'promo' ? 'Mengunggah…' : 'Pilih gambar' }}
                                <input type="file" accept="image/*" class="hidden" @change="(e) => unggah(e, (u) => (bank.promo.slide2Image = u), 'promo')" />
                            </label>
                            <button v-if="bisaUbah && bank.promo.slide2Image" type="button" class="pk-hapus" @click="bank.promo.slide2Image = ''"><X class="h-3.5 w-3.5" />Hapus gambar</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ══ Berlaku untuk semua bank ══ -->
        <div class="card p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide">Foto Tampilan, Logo &amp; Bulan Berlaku</h2>
            <p class="mt-1 text-sm text-neutral-500">Berlaku untuk semua bank — tampil pada urutan pembuka sebelum layar pilih bank.</p>

            <div class="mt-3 grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div>
                    <span class="pk-label">Foto tampilan lebar (desktop)</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <img v-if="cfg.introPhoto" :src="cfg.introPhoto" alt="" class="h-28 max-w-[220px] rounded border border-neutral-200 object-cover dark:border-neutral-700" />
                        <label v-if="bisaUbah" class="pk-unggah">
                            <Upload class="h-4 w-4" />{{ mengunggah === 'intro' ? 'Mengunggah…' : 'Pilih gambar' }}
                            <input type="file" accept="image/*" class="hidden" @change="(e) => unggah(e, (u) => (cfg.introPhoto = u), 'intro')" />
                        </label>
                        <button v-if="bisaUbah && cfg.introPhoto" type="button" class="pk-hapus" @click="cfg.introPhoto = ''"><X class="h-3.5 w-3.5" />Hapus</button>
                    </div>
                </div>
                <div>
                    <span class="pk-label">Foto khusus HP (opsional)</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <img v-if="cfg.introPhotoMobile" :src="cfg.introPhotoMobile" alt="" class="h-28 max-w-[220px] rounded border border-neutral-200 object-cover dark:border-neutral-700" />
                        <label v-if="bisaUbah" class="pk-unggah">
                            <Upload class="h-4 w-4" />{{ mengunggah === 'introHp' ? 'Mengunggah…' : 'Pilih gambar' }}
                            <input type="file" accept="image/*" class="hidden" @change="(e) => unggah(e, (u) => (cfg.introPhotoMobile = u), 'introHp')" />
                        </label>
                        <button v-if="bisaUbah && cfg.introPhotoMobile" type="button" class="pk-hapus" @click="cfg.introPhotoMobile = ''"><X class="h-3.5 w-3.5" />Hapus</button>
                    </div>
                </div>
                <div>
                    <span class="pk-label">Logo Java Residence</span>
                    <div class="flex flex-wrap items-center gap-3">
                        <img v-if="cfg.brandLogo" :src="cfg.brandLogo" alt="" class="h-20 max-w-[180px] rounded border border-neutral-200 bg-white object-contain p-2 dark:border-neutral-700" />
                        <label v-if="bisaUbah" class="pk-unggah">
                            <Upload class="h-4 w-4" />{{ mengunggah === 'brand' ? 'Mengunggah…' : 'Pilih gambar' }}
                            <input type="file" accept="image/*" class="hidden" @change="(e) => unggah(e, (u) => (cfg.brandLogo = u), 'brand')" />
                        </label>
                        <button v-if="bisaUbah && cfg.brandLogo" type="button" class="pk-hapus" @click="cfg.brandLogo = ''"><X class="h-3.5 w-3.5" />Hapus logo</button>
                    </div>
                </div>
                <div>
                    <label class="pk-label" for="pk-bulan">Bulan berlaku</label>
                    <input id="pk-bulan" v-model="cfg.effectiveMonth" :disabled="! bisaUbah" type="text" class="pk-input max-w-xs" placeholder="September 2026" />
                    <p class="mt-1 text-xs text-neutral-500">Tampil pada slide kedua kalkulator. Perbarui tiap bulan supaya tim tahu data suku bunga perlu dicek ulang.</p>
                </div>
            </div>
            <p v-if="galat.gambar" class="mt-3 text-xs text-danger-500">{{ galat.gambar }}</p>
        </div>

        <div v-if="bisaUbah" class="flex justify-end">
            <Button :disabled="menyimpan" @click="simpan">
                <template #icon-left><Save class="h-4 w-4" /></template>
                {{ menyimpan ? 'Menyimpan…' : 'Simpan Pengaturan' }}
            </Button>
        </div>
    </div>
</template>

<style scoped>
.pk-label { display: block; font-size: 12px; font-weight: 600; letter-spacing: .02em; margin-bottom: 5px; }
.pk-input {
    width: 100%; min-height: 36px; border-radius: 8px; padding: 6px 10px; font-size: 14px;
    border: 1px solid rgb(212 212 212); background: #fff; color: inherit;
}
:global(.dark) .pk-input { border-color: rgb(64 64 64); background: rgb(23 23 23); }
.pk-input:disabled { opacity: .6; cursor: not-allowed; }
.pk-mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; line-height: 1.5; resize: vertical; }
.pk-uang { display: flex; align-items: stretch; border: 1px solid rgb(212 212 212); border-radius: 8px; background: #fff; overflow: hidden; }
:global(.dark) .pk-uang { border-color: rgb(64 64 64); background: rgb(23 23 23); }
.pk-uang span { display: flex; align-items: center; padding: 0 10px; font-size: 13px; opacity: .7; border-right: 1px solid rgb(229 229 229); }
:global(.dark) .pk-uang span { border-right-color: rgb(64 64 64); }
.pk-uang input { flex: 1; min-width: 0; border: 0; background: transparent; padding: 7px 10px; font-size: 14px; color: inherit; }
.pk-uang input:focus { outline: none; }
.pk-unggah {
    display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
    border: 1px solid rgb(212 212 212); border-radius: 8px; padding: 7px 12px; font-size: 13px; font-weight: 500;
}
:global(.dark) .pk-unggah { border-color: rgb(64 64 64); }
.pk-hapus {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 600; color: rgb(220 38 38);
    border: 1px solid rgb(252 165 165); border-radius: 8px; padding: 6px 10px;
}
</style>
