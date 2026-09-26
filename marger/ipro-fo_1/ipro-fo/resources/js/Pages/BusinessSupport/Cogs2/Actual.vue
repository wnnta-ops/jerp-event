<!-- COGS Baru → Actual Costing COGS (Tanah / Housing / Infrastruktur / License Permit) -->
<script setup lang="ts">
/**
 * Baris jurnal realisasi per komponen. Kolomnya mengikuti lampiran: yang punya SPK (Housing,
 * Infrastruktur) memakai kolom Nomor SPK, yang berkategori (Housing, Infra, License) memakai
 * kolom Kategori.
 *
 * ── Penambahan & Pengurangan dipisah, Saldo Akhir turunan ──
 * Satu angka bersih akan menyembunyikan koreksi yang justru perlu terlihat saat menelusuri
 * selisih antara realisasi dan budget — dan begitu koreksinya tak terlihat, orang akan mencari
 * sebab selisih itu di tempat yang salah.
 *
 * Angka di sini adalah SATU-SATUNYA sumber biaya aktual yang dibebankan ke unit di Rincian COGS.
 * Mengubahnya langsung menggeser HPP realisasi seluruh unit yang belum terkunci.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Save, Plus, Trash2, Info, AlertTriangle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import Kepala from '@/Components/Cogs2/Kepala.vue';
import { KOMPONEN, VAR, rp, rp2, angka } from '@/Components/Cogs2/warna';

const props = defineProps<{
    periode: any;
    komponen: 'tanah' | 'housing' | 'infra' | 'license';
    actualBaris: Record<string, any[]>;
    hitung: any;
    bisaUbah: boolean;
    // ── Dua sumber otomatis, di luar baris yang diketik sendiri ──────────────────────
    // Kontrak SPK (MUK) yang ditautkan lewat tombol "Link COGS", dan mutasi buku besar pada
    // akun COA yang ditandai Komponen COGS di Tambah/Edit Akun.
    sumberMuk?: Record<string, any[]>;
    sumberGl?: Record<string, any[]>;
    subtotal?: Record<string, { manual: number; muk: number; gl: number }>;
    akunBertanda?: Array<{ id: number; code: string; name: string; dipakai: boolean; milikLain: number | null }>;
    perluKategori?: boolean;
}>();

const def = computed(() => KOMPONEN.find((k) => k.kunci === props.komponen)!);

/** Kolom teks per komponen, persis seperti lampiran — tidak lebih dan tidak kurang. */
const KOLOM: Record<string, Array<{ k: string; label: string; lebar?: string }>> = {
    tanah: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
    housing: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori' },
        { k: 'no_spk', label: 'Nomor SPK' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
    infra: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori' },
        { k: 'no_spk', label: 'Nomor SPK' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
    license: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
};
const kolom = computed(() => KOLOM[props.komponen]);

const kosong = () => ({
    lokasi: props.periode.lokasi_kode ?? '', tahap: props.periode.tahap ?? '',
    kategori: '', no_spk: '', no_jurnal: '', keterangan: '', penambahan: 0, pengurangan: 0,
});
const baris = ref<any[]>([]);

function segarkan() {
    baris.value = (props.actualBaris?.[props.komponen] ?? []).map((b: any) => ({ ...kosong(), ...b }));
}
segarkan();
watch(() => [props.actualBaris, props.komponen], segarkan, { deep: true });

function tambah() { baris.value.push(kosong()); }
function hapus(i: number) { baris.value.splice(i, 1); }

const saldo = (b: any) => (Number(b.penambahan) || 0) - (Number(b.pengurangan) || 0);
const totalTambah = computed(() => baris.value.reduce((n, b) => n + (Number(b.penambahan) || 0), 0));
const totalKurang = computed(() => baris.value.reduce((n, b) => n + (Number(b.pengurangan) || 0), 0));
const totalSaldo = computed(() => totalTambah.value - totalKurang.value);

const menyimpan = ref(false);
const galat = ref<string | null>(null);
function simpan() {
    menyimpan.value = true; galat.value = null;
    router.put(route('bs.cogs2.actual.simpan', [props.periode.id, props.komponen]), { baris: baris.value }, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = (Object.values(e)[0] as string) ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}

const rekon = computed(() => props.hitung.rekon[props.komponen]);

/* ── Dua sumber otomatis ──────────────────────────────────────────────────────────── */
const barisMuk = computed<any[]>(() => props.sumberMuk?.[props.komponen] ?? []);
const barisGl = computed<any[]>(() => props.sumberGl?.[props.komponen] ?? []);
const sub = computed(() => props.subtotal?.[props.komponen] ?? { manual: 0, muk: 0, gl: 0 });

const ikut = ref({ ikut_muk: !! props.periode.ikut_muk, ikut_gl: !! props.periode.ikut_gl });
watch(() => [props.periode.ikut_muk, props.periode.ikut_gl], () => {
    ikut.value = { ikut_muk: !! props.periode.ikut_muk, ikut_gl: !! props.periode.ikut_gl };
});
function simpanSumber() {
    router.put(route('bs.cogs2.sumber.simpan', props.periode.id), { ...ikut.value }, { preserveScroll: true });
}

/** Akun COA yang diakui milik periode ini. Salinan lokal supaya centangnya bisa diubah dulu. */
const akun = ref<number[]>([]);
function segarkanAkun() {
    akun.value = (props.akunBertanda ?? []).filter((a) => a.dipakai).map((a) => a.id);
}
segarkanAkun();
watch(() => props.akunBertanda, segarkanAkun, { deep: true });

const simpanAkunJalan = ref(false);
function simpanAkun() {
    simpanAkunJalan.value = true;
    router.put(route('bs.cogs2.akun.simpan', [props.periode.id, props.komponen]), { account_id: akun.value }, {
        preserveScroll: true,
        onFinish: () => { simpanAkunJalan.value = false; },
    });
}

/**
 * Penggandaan: baris yang diketik yang nomor jurnal / nomor SPK-nya sudah datang dari sumber
 * otomatis. Totalnya tetap merekonsiliasi saat digandakan — hanya nilainya dua kali lipat —
 * jadi satu-satunya cara ia terlihat adalah disebut.
 */
const kembar = computed(() => {
    const dariSumber = new Set<string>();
    for (const b of barisMuk.value) if (b.no_spk) dariSumber.add('spk:' + String(b.no_spk).toLowerCase());
    for (const b of barisGl.value) if (b.no_jurnal) dariSumber.add('jur:' + String(b.no_jurnal).toLowerCase());

    return baris.value.filter((b) =>
        (b.no_spk && dariSumber.has('spk:' + String(b.no_spk).toLowerCase()))
        || (b.no_jurnal && dariSumber.has('jur:' + String(b.no_jurnal).toLowerCase())));
});
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const turunanCls = 'rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-2 py-1.5 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900/60';
</script>

<template>
    <Kepala :periode="periode" layar="actual" :komponen="komponen">
        <!-- ── Ringkasan pembebanan ───────────────────────────────────────────── -->
        <div class="card">
            <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: VAR[komponen] }"></span>
                Pembebanan {{ def.label }} ke Unit
            </p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-neutral-500">Biaya aktual</p><p class="text-lg font-semibold tabular-nums">{{ rp(rekon.aktual) }}</p></div>
                <div><p class="text-xs text-neutral-500">Terkunci di unit booked</p><p class="text-lg font-semibold tabular-nums">{{ rp(rekon.terkunci) }}</p></div>
                <div><p class="text-xs text-neutral-500">Dibebankan ke unit</p><p class="text-lg font-semibold tabular-nums">{{ rp(rekon.dibebankan) }}</p></div>
                <div>
                    <p class="text-xs text-neutral-500">Tarif per m² {{ komponen === 'housing' ? 'bangunan' : 'tanah' }}</p>
                    <p class="text-lg font-semibold tabular-nums" :class="rekon.tarif !== null && rekon.tarif < 0 ? 'text-rose-600 dark:text-rose-400' : ''">{{ rp2(rekon.tarif) }}</p>
                </div>
            </div>
            <p class="mt-3 flex items-start gap-2 rounded-lg p-2.5 text-xs"
               :class="Math.abs(rekon.selisih) < 0.005
                   ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200'
                   : 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-200'">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span v-if="Math.abs(rekon.selisih) < 0.005">
                    Seluruh biaya aktual habis dibebankan — biaya terkunci ditambah biaya yang dibagi ke unit sama <b>persis</b> dengan biaya aktual.
                </span>
                <span v-else>
                    <b>{{ rp(rekon.selisih) }}</b> belum terbebankan ke unit mana pun{{ rekon.alasan ? ': ' + rekon.alasan : '' }}.
                </span>
            </p>
        </div>

        <!-- ── Sumber biaya aktual ────────────────────────────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Sumber Biaya Aktual</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                    <p class="text-xs text-neutral-500">Diketik sendiri</p>
                    <p class="text-lg font-semibold tabular-nums">{{ rp(sub.manual) }}</p>
                </div>
                <div class="rounded-lg border p-3" :class="ikut.ikut_muk ? 'border-neutral-200 dark:border-neutral-800' : 'border-dashed border-neutral-300 opacity-60 dark:border-neutral-700'">
                    <label class="flex items-center gap-2 text-xs text-neutral-500">
                        <input v-model="ikut.ikut_muk" type="checkbox" :disabled="! bisaUbah" class="rounded border-neutral-300" @change="simpanSumber" />
                        Kontrak SPK (MUK) · {{ angka(barisMuk.length) }} kontrak
                    </label>
                    <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ rp(sub.muk) }}</p>
                </div>
                <div class="rounded-lg border p-3" :class="ikut.ikut_gl ? 'border-neutral-200 dark:border-neutral-800' : 'border-dashed border-neutral-300 opacity-60 dark:border-neutral-700'">
                    <label class="flex items-center gap-2 text-xs text-neutral-500">
                        <input v-model="ikut.ikut_gl" type="checkbox" :disabled="! bisaUbah" class="rounded border-neutral-300" @change="simpanSumber" />
                        Buku Besar (COA bertanda) · {{ angka(barisGl.length) }} baris
                    </label>
                    <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ rp(sub.gl) }}</p>
                </div>
            </div>

            <div v-if="kembar.length" class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    <b>{{ angka(kembar.length) }} baris yang diketik</b> memakai nomor jurnal / nomor SPK yang juga datang
                    dari sumber otomatis ({{ kembar.map((b: any) => b.no_jurnal || b.no_spk).slice(0, 4).join(', ') }}<span v-if="kembar.length > 4">, …</span>).
                    Keduanya dijumlahkan, jadi biayanya terhitung <b>dua kali</b> — hapus salah satunya, atau matikan sumbernya.
                </span>
            </div>
        </div>

        <!-- ── Akun COA bertanda komponen ini ─────────────────────────────────── -->
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    Akun COA bertanda {{ def.label }}
                </p>
                <Button v-if="bisaUbah && (akunBertanda ?? []).length" :disabled="simpanAkunJalan" size="sm" @click="simpanAkun">
                    <Save class="h-4 w-4" /> {{ simpanAkunJalan ? 'Menyimpan…' : 'Simpan Pilihan Akun' }}
                </Button>
            </div>

            <div v-if="(akunBertanda ?? []).length" class="mt-3 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                <label v-for="a in akunBertanda" :key="a.id"
                       class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm"
                       :class="akun.includes(a.id) ? 'border-sky-500 bg-sky-50/60 dark:bg-sky-950/20' : 'border-neutral-200 dark:border-neutral-800'">
                    <input v-model="akun" type="checkbox" :value="a.id" :disabled="! bisaUbah" class="mt-0.5 rounded border-neutral-300" />
                    <span class="min-w-0">
                        <span class="block font-mono text-xs">{{ a.code }}</span>
                        <span class="block text-xs text-neutral-500">{{ a.name }}</span>
                        <span v-if="a.milikLain !== null" class="mt-0.5 inline-block rounded bg-amber-100 px-1 text-[10px] text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                            sudah diakui periode lain
                        </span>
                    </span>
                </label>
            </div>
            <p v-else class="mt-3 rounded-lg bg-neutral-50 p-2.5 text-xs text-neutral-600 dark:bg-neutral-900/60 dark:text-neutral-400">
                Belum ada akun COA yang ditandai <b>{{ def.label }}</b>. Tandanya dipasang di
                <b>General Ledger → Master → Chart of Account</b>, pada kolom <b>Komponen COGS</b> di Tambah/Edit Akun.
            </p>

            <!--
                Tanda Komponen COGS melekat pada AKUN, sedangkan bagan akun di sini memisahkan
                cluster di tingkat akun (CIP TANAH C3 KRIAN vs CIP TANAH C5 SUKODONO, keduanya
                bertanda Tanah). Menarik seluruh akun bertanda ke satu periode berarti membebankan
                biaya cluster lain ke unit cluster ini — angkanya tetap keluar dan tidak ada galat.
            -->
            <p class="mt-3 flex items-start gap-2 rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Hanya akun yang <b>dicentang</b> yang mutasi buku besarnya dibebankan ke unit periode ini.
                    Tandanya melekat pada akun, sedangkan satu komponen bisa punya akun untuk beberapa cluster —
                    mencentang semuanya berarti membebankan biaya cluster lain ke unit cluster ini.
                    Debit menjadi penambahan, kredit menjadi pengurangan; jurnal yang belum <b>posted</b> tetap tampil tetapi tidak dijumlahkan.
                </span>
            </p>
        </div>

        <!-- ── Baris dari Kontrak SPK (MUK) ───────────────────────────────────── -->
        <div v-if="barisMuk.length" class="card overflow-x-auto">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Dari Kontrak SPK (MUK) — hanya dibaca
            </p>
            <table class="mt-3 min-w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-3 py-2 text-left">No. Kontrak</th>
                        <th class="px-3 py-2 text-left">No. SPK</th>
                        <th class="px-3 py-2 text-left">Proyek</th>
                        <th v-if="perluKategori" class="px-3 py-2 text-left">Kategori</th>
                        <th class="px-3 py-2 text-right">Nilai Kontrak</th>
                        <th class="px-3 py-2 text-right">Sudah Ditagih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(b, i) in barisMuk" :key="i">
                        <td class="px-3 py-2 font-mono text-xs">{{ b.no_kontrak }}</td>
                        <td class="px-3 py-2 text-xs">{{ b.no_spk || '—' }}</td>
                        <td class="px-3 py-2 text-xs text-neutral-500">{{ b.proyek || '—' }}</td>
                        <td v-if="perluKategori" class="px-3 py-2">
                            <span v-if="b.kategori">{{ b.kategori }}</span>
                            <span v-else class="text-amber-700 dark:text-amber-300">belum dipilih</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-neutral-500">{{ rp(b.nilai_kontrak) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ rp(b.penambahan) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                    <tr>
                        <td class="px-3 py-2.5" :colspan="perluKategori ? 4 : 3">Jumlah</td>
                        <td class="px-3 py-2.5 text-right tabular-nums text-neutral-500">{{ rp(barisMuk.reduce((n: number, b: any) => n + Number(b.nilai_kontrak || 0), 0)) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(sub.muk) }}</td>
                    </tr>
                </tfoot>
            </table>
            <!-- Yang dibebankan adalah SUDAH DITAGIH, bukan nilai kontrak: Actual Costing adalah
                 biaya yang sudah terjadi, sedangkan nilai kontrak baru komitmen. -->
            <p class="mt-3 flex items-start gap-2 rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Yang dibebankan adalah <b>Sudah Ditagih</b>, bukan nilai kontrak. Actual Costing adalah biaya yang
                    <b>sudah terjadi</b>; memakai nilai kontrak membebankan seluruhnya sejak hari pertama, jauh sebelum
                    pekerjaannya dikerjakan, dan HPP tiap unit akan melonjak lalu turun sendiri seiring penagihan.
                    Tautannya diatur lewat tombol <b>Link COGS</b> di Contractor Management → Master → Kontrak SPK (MUK).
                </span>
            </p>
        </div>

        <!-- ── Baris dari Buku Besar ──────────────────────────────────────────── -->
        <div v-if="barisGl.length" class="card overflow-x-auto">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Dari Buku Besar — hanya dibaca
            </p>
            <table class="mt-3 min-w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-3 py-2 text-left">No. Jurnal</th>
                        <th class="px-3 py-2 text-left">Tanggal</th>
                        <th class="px-3 py-2 text-left">Akun</th>
                        <th class="px-3 py-2 text-left">Keterangan</th>
                        <th class="px-3 py-2 text-right">Debit</th>
                        <th class="px-3 py-2 text-right">Kredit</th>
                        <th class="px-3 py-2 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(b, i) in barisGl" :key="i" :class="b.efektif ? '' : 'opacity-60'">
                        <td class="px-3 py-2 font-mono text-xs">{{ b.no_jurnal || '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ b.tanggal || '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ b.akun }}</td>
                        <td class="px-3 py-2 text-xs text-neutral-500">{{ b.keterangan || '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rp(b.debit) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rp(b.kredit) }}</td>
                        <td class="px-3 py-2 text-center text-xs">
                            <span v-if="b.efektif" class="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{{ b.status }}</span>
                            <span v-else class="rounded bg-neutral-200 px-1.5 py-0.5 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">{{ b.status || '—' }} · tidak dijumlah</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                    <tr>
                        <td class="px-3 py-2.5" colspan="4">Jumlah yang efektif</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(barisGl.reduce((n: number, b: any) => n + Number(b.penambahan || 0), 0)) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(barisGl.reduce((n: number, b: any) => n + Number(b.pengurangan || 0), 0)) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(sub.gl) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- ── Baris jurnal ───────────────────────────────────────────────────── -->
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Actual Costing COGS — {{ def.label }}</p>
                <Button v-if="bisaUbah" variant="ghost" size="sm" @click="tambah"><Plus class="h-4 w-4" /> Tambah Baris</Button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-3 py-2 text-left">No</th>
                            <th v-for="c in kolom" :key="c.k" class="px-3 py-2 text-left">{{ c.label }}</th>
                            <th class="px-3 py-2 text-right">Penambahan</th>
                            <th class="px-3 py-2 text-right">Pengurangan</th>
                            <th class="px-3 py-2 text-right">Saldo Akhir</th>
                            <th v-if="bisaUbah" class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(b, i) in baris" :key="i">
                            <td class="px-3 py-2 tabular-nums text-neutral-500">{{ i + 1 }}</td>
                            <td v-for="c in kolom" :key="c.k" class="px-3 py-2" :class="c.lebar">
                                <input v-model="b[c.k]" :disabled="! bisaUbah" :class="inputCls" />
                            </td>
                            <td class="px-3 py-2"><MoneyInput v-model="b.penambahan" :decimals="2" :disabled="! bisaUbah" /></td>
                            <td class="px-3 py-2"><MoneyInput v-model="b.pengurangan" :decimals="2" :disabled="! bisaUbah" /></td>
                            <!-- Saldo Akhir: turunan, tidak bisa diketik. -->
                            <td class="px-3 py-2"><div :class="turunanCls">{{ rp(saldo(b)) }}</div></td>
                            <td v-if="bisaUbah" class="px-3 py-2 text-right">
                                <button type="button" class="text-neutral-400 hover:text-rose-600" :aria-label="'Hapus baris ' + (i + 1)" @click="hapus(i)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! baris.length">
                            <td :colspan="kolom.length + 4 + (bisaUbah ? 1 : 0)" class="px-3 py-6 text-center text-neutral-500">Belum ada baris jurnal.</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <tr>
                            <td class="px-3 py-2.5" :colspan="kolom.length + 1">TOTAL</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalTambah) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalKurang) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalSaldo) }}</td>
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
    </Kepala>
</template>
