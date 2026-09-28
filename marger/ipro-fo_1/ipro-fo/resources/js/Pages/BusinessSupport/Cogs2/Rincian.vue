<!-- COGS Baru → Rincian COGS: budget & realisasi per unit -->
<script setup lang="ts">
/**
 * Layar terberat modul ini: satu baris per unit, dengan budget (Standard Costing) dan realisasi
 * (Realisasi Costing) berdampingan.
 *
 * ── Rantai melingkarnya sudah selesai di server ──
 * Di lampiran, realisasi tiap unit memakai angka yang dijumlahkan dari kolom realisasi itu
 * sendiri — Excel hanya bisa menyelesaikannya dengan "iterative calculation", yang bisa berhenti
 * sebelum konvergen dan diam-diam memulangkan angka yang belum stabil. Di sini rantai itu
 * dipecahkan aljabarnya dan dihitung SEKALI di server; layar ini tidak menghitung apa pun,
 * hanya menampilkan.
 *
 * ── Tiga lebar tampilan ──
 * Tabelnya bisa mencapai 24 kolom. Memaksakan semuanya sekaligus membuat angka penting terdorong
 * ke luar layar dan orang menggulir mendatar untuk membandingkan dua kolom yang seharusnya
 * bersebelahan. Jadi ada tiga pilihan: Ringkas (total saja), Budget (rincian empat komponen
 * budget), dan Realisasi (rincian empat komponen realisasi).
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Save, Plus, Trash2, DownloadCloud, AlertTriangle, Info } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import Kepala from '@/Components/Cogs2/Kepala.vue';
import ImporUnit from '@/Components/Cogs2/ImporUnit.vue';
import { KOMPONEN, VAR, rp, rp2, m2, persen, angka } from '@/Components/Cogs2/warna';

const props = defineProps<{
    periode: any;
    unitBaris: any[];
    hitung: any;
    statusOpsi: Record<string, string>;
    costingOpsi: Record<string, string>;
    bisaUbah: boolean;
}>();

/* ── Salinan lokal ────────────────────────────────────────────────────────────────── */
const kosong = () => ({
    no_spr: '', nama_customer: '', unit_rumah: '',
    lokasi: props.periode.lokasi_kode ?? '', tahap: props.periode.tahap ?? '',
    tipe: '', lt: 0, lb: 0, status: 'belum', costing: 'belum', booked_revenue: null,
});
const baris = ref<any[]>([]);
function segarkan() { baris.value = (props.unitBaris ?? []).map((b: any) => ({ ...kosong(), ...b })); }
segarkan();
watch(() => props.unitBaris, segarkan, { deep: true });

function tambah() { baris.value.push(kosong()); }
function hapus(i: number) { baris.value.splice(i, 1); }

/**
 * Hasil "Tambah Unit Massal" masuk ke DRAF, bukan langsung ke basis data.
 *
 * Simpanan Rincian COGS mengganti SELURUH daftar, jadi impor yang menulis langsung akan tertimpa
 * begitu operator menekan Simpan dengan draf lama di layarnya — tanpa galat apa pun. Dengan
 * mengisi draf, jalur simpannya tetap satu dan seluruh penjaga di server tetap berlaku.
 *
 * Mode "tambah" MELEWATI kode yang sudah ada alih-alih menimpanya: menimpa akan menghapus status
 * booked dan pilihan costing unit yang sudah terkunci HPP-nya, dan itu tidak terlihat sebagai
 * kesalahan — hanya angka HPP yang berubah sendiri.
 */
function terimaImpor(masuk: any[], mode: 'ganti' | 'tambah') {
    if (mode === 'ganti') {
        baris.value = masuk.map((b) => ({ ...kosong(), ...b }));

        return;
    }
    const ada = new Set(baris.value.map((b: any) => String(b.unit_rumah ?? '').toLowerCase()));
    for (const b of masuk) {
        if (ada.has(String(b.unit_rumah ?? '').toLowerCase())) continue;
        ada.add(String(b.unit_rumah ?? '').toLowerCase());
        baris.value.push({ ...kosong(), ...b });
    }
}

/**
 * Selama unit BELUM booked, kedua pilihan costing masih terbuka — mengunci "Standard Costing"
 * di situ akan mengeluarkannya dari pembebanan realisasi padahal ia belum terjual. Jadi begitu
 * status dikembalikan ke "belum", pilihannya ikut dibuka lagi.
 */
function ubahStatus(b: any) { if (b.status === 'belum') b.costing = 'belum'; }

const menyimpan = ref(false);
const menarik = ref(false);
const galat = ref<string | null>(null);

function simpan() {
    menyimpan.value = true; galat.value = null;
    router.put(route('bs.cogs2.rincian.simpan', props.periode.id), { baris: baris.value }, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = (Object.values(e)[0] as string) ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}
function tarik() {
    menarik.value = true; galat.value = null;
    router.post(route('bs.cogs2.tarik-unit', props.periode.id), {}, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = e.tarik ?? (Object.values(e)[0] as string) ?? 'Gagal menarik unit.'; },
        onFinish: () => { menarik.value = false; },
    });
}

/* ── Lebar tampilan ───────────────────────────────────────────────────────────────── */
const tampilan = ref<'ringkas' | 'budget' | 'realisasi'>('ringkas');

/* ── Grafik: Budget vs Realisasi per komponen ─────────────────────────────────────── */
const BAR = { w: 640, kiri: 96, kanan: 92, tinggiBaris: 34, sela: 4 };
const grafik = computed(() => {
    const data = KOMPONEN.map((k) => ({
        kunci: k.kunci, label: k.label, warna: VAR[k.kunci],
        budget: Number(props.hitung.perhitungan[k.kunci].budget) || 0,
        realisasi: Number(props.hitung.rekon[k.kunci].aktual) || 0,
    }));
    const maks = Math.max(1, ...data.flatMap((d) => [d.budget, d.realisasi]));
    const lebar = BAR.w - BAR.kiri - BAR.kanan;
    return {
        data, maks, lebar,
        tinggi: data.length * BAR.tinggiBaris + 8,
        skala: (v: number) => Math.max(0, (v / maks) * lebar),
    };
});

const adaPeringatan = computed(() => (props.hitung.peringatan ?? []).length > 0);
const inputCls = 'h-8 w-full rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const selCls = 'h-8 w-full rounded-lg border border-neutral-300 bg-white px-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <Kepala :periode="periode" layar="rincian">
        <template #aksi>
            <Button v-if="bisaUbah" variant="secondary" size="sm" :disabled="menarik" @click="tarik">
                <DownloadCloud class="h-4 w-4" /> {{ menarik ? 'Menarik…' : 'Tarik Unit dari Front Office' }}
            </Button>
        </template>

        <!-- ── Peringatan: keadaan yang angkanya tetap keluar tetapi menyesatkan ─ -->
        <div v-if="adaPeringatan" class="space-y-1.5">
            <p v-for="(w, i) in hitung.peringatan" :key="i"
               class="card flex items-start gap-2 border-amber-300 bg-amber-50 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ w.pesan }}</span>
            </p>
        </div>

        <!-- ── Ringkasan per status & pilihan costing (baris 1–3 lampiran) ────── -->
        <div class="card overflow-x-auto">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Summary HPP</p>
            <table class="mt-3 min-w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Pilihan Costing</th>
                        <th class="px-3 py-2 text-right">Unit</th>
                        <th v-for="k in KOMPONEN" :key="k.kunci" class="px-3 py-2 text-right">{{ k.pendek }}</th>
                        <th class="px-3 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in [
                        { kunci: 'booked_standard', status: 'Sudah Booked', costing: 'Standard Costing', catatan: 'memakai angka budget — HPP-nya terkunci' },
                        { kunci: 'booked_realisasi', status: 'Sudah Booked', costing: 'Realisasi Costing', catatan: 'memakai angka realisasi berjalan' },
                        { kunci: 'booked_belum_pilih', status: 'Sudah Booked', costing: 'Belum ditetapkan', catatan: 'masih ikut menanggung realisasi' },
                        { kunci: 'belum_booked', status: 'Belum Booked', costing: 'Standard / Realisasi Costing', catatan: 'keduanya masih mungkin' },
                    ]" :key="r.kunci">
                        <td class="px-3 py-2 font-medium">{{ r.status }}</td>
                        <td class="px-3 py-2">
                            {{ r.costing }}
                            <span class="block text-xs text-neutral-500">{{ r.catatan }}</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ angka(hitung.ringkas[r.kunci].jumlah) }}</td>
                        <td v-for="k in KOMPONEN" :key="k.kunci" class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.ringkas[r.kunci][k.kunci]) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ rp(hitung.ringkas[r.kunci].total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ── Grafik Budget vs Actual Costing ────────────────────────────────── -->
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Budget vs Actual Costing</p>
            <svg :viewBox="'0 0 ' + BAR.w + ' ' + grafik.tinggi" class="mt-3 w-full" :style="{ maxHeight: grafik.tinggi * 1.6 + 'px' }" role="img"
                 :aria-label="'Perbandingan budget dan biaya aktual per komponen: ' + grafik.data.map(d => d.label + ' budget ' + rp(d.budget) + ', aktual ' + rp(d.realisasi)).join('; ')">
                <g v-for="(d, i) in grafik.data" :key="d.kunci" :transform="'translate(0,' + (i * BAR.tinggiBaris + 4) + ')'">
                    <text :x="BAR.kiri - 8" y="14" text-anchor="end" class="fill-neutral-600 dark:fill-neutral-300" style="font-size:10px">{{ d.label }}</text>
                    <!-- Budget: berongga (garis tepi), Actual: pejal. Bentuknya yang membedakan,
                         bukan warnanya — warna tetap menandai KOMPONEN, bukan jenis angkanya. -->
                    <rect :x="BAR.kiri" y="3" :width="grafik.skala(d.budget)" height="9" rx="1.5"
                          fill="transparent" :stroke="d.warna" stroke-width="1.5" />
                    <rect :x="BAR.kiri" y="15" :width="grafik.skala(d.realisasi)" height="9" rx="1.5" :fill="d.warna" />
                    <text :x="BAR.kiri + grafik.lebar + 6" y="11" class="fill-neutral-500" style="font-size:9px">{{ rp(d.budget) }}</text>
                    <text :x="BAR.kiri + grafik.lebar + 6" y="23" class="fill-neutral-700 dark:fill-neutral-200" style="font-size:9px;font-weight:600">{{ rp(d.realisasi) }}</text>
                </g>
            </svg>
            <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-xs">
                <span class="inline-flex items-center gap-1.5">
                    <svg width="16" height="9" aria-hidden="true"><rect x="0.75" y="0.75" width="14.5" height="7.5" rx="1.5" fill="transparent" stroke="currentColor" stroke-width="1.5" /></svg>
                    Budget (Set Up Kategori COGS)
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <svg width="16" height="9" aria-hidden="true"><rect width="16" height="9" rx="1.5" fill="currentColor" /></svg>
                    Actual Costing
                </span>
            </div>
        </div>

        <!-- ── Rekonsiliasi pembebanan ────────────────────────────────────────── -->
        <div class="card overflow-x-auto">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Rekonsiliasi Pembebanan</p>
            <table class="mt-3 min-w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-3 py-2 text-left">Komponen</th>
                        <th class="px-3 py-2 text-right">Biaya Aktual</th>
                        <th class="px-3 py-2 text-right">Terkunci di Unit Booked</th>
                        <th class="px-3 py-2 text-right">Dibebankan ke Unit</th>
                        <th class="px-3 py-2 text-right">Σ Luas Penanggung</th>
                        <th class="px-3 py-2 text-right">Tarif per m²</th>
                        <th class="px-3 py-2 text-right">Selisih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="k in KOMPONEN" :key="k.kunci">
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: VAR[k.kunci] }"></span><b>{{ k.label }}</b>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.rekon[k.kunci].aktual) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.rekon[k.kunci].terkunci) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ rp(hitung.rekon[k.kunci].dibebankan) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ m2(hitung.rekon[k.kunci].bobot) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums" :class="hitung.rekon[k.kunci].tarif !== null && hitung.rekon[k.kunci].tarif < 0 ? 'text-rose-600 dark:text-rose-400' : ''">
                            {{ rp2(hitung.rekon[k.kunci].tarif) }}
                        </td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums"
                            :class="Math.abs(hitung.rekon[k.kunci].selisih) < 0.005 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">
                            {{ rp(hitung.rekon[k.kunci].selisih) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 flex items-start gap-2 rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Tarif = (Biaya Aktual − Terkunci) ÷ Σ Luas Penanggung. Rumus itu adalah penyelesaian
                    <b>tertutup</b> dari rantai melingkar di lampiran: realisasi tiap unit memakai angka yang dijumlahkan
                    dari kolom realisasi itu sendiri. Diselesaikan sekali lewat aljabar, bukan diiterasi —
                    iterasi bisa berhenti sebelum konvergen dan memulangkan angka yang terlihat wajar tetapi salah.
                    Selisih <b>nol</b> berarti seluruh biaya aktual habis dibebankan.
                </span>
            </p>
        </div>

        <!-- ── Tabel per unit ─────────────────────────────────────────────────── -->
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    Rincian per Unit — {{ angka(hitung.unitTotal.jumlah) }} unit ·
                    {{ angka(hitung.unitTotal.booked) }} booked · {{ angka(hitung.unitTotal.terkunci) }} terkunci
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex rounded-lg border border-neutral-200 p-0.5 dark:border-neutral-700" role="group" aria-label="Lebar tampilan">
                        <button v-for="t in [['ringkas', 'Ringkas'], ['budget', 'Budget'], ['realisasi', 'Realisasi']]" :key="t[0]"
                                type="button" class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                                :class="tampilan === t[0] ? 'bg-sky-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                :aria-pressed="tampilan === t[0]" @click="tampilan = t[0] as any">{{ t[1] }}</button>
                    </div>
                    <Button v-if="bisaUbah" variant="ghost" size="sm" @click="tambah"><Plus class="h-4 w-4" /> Tambah Unit</Button>
                    <ImporUnit :baris="baris" :nama-berkas="periode.cakupan" :bisa-ubah="bisaUbah" @impor="terimaImpor" />
                </div>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-2 py-2 text-left">No</th>
                            <th class="px-2 py-2 text-left">No. SPR</th>
                            <th class="px-2 py-2 text-left">Nama Customer</th>
                            <th class="px-2 py-2 text-left">Unit Rumah</th>
                            <th class="px-2 py-2 text-left">Tipe</th>
                            <th class="px-2 py-2 text-right">LT</th>
                            <th class="px-2 py-2 text-right">LB</th>
                            <template v-if="tampilan === 'budget'">
                                <th v-for="k in KOMPONEN" :key="k.kunci" class="px-2 py-2 text-right">{{ k.pendek }}</th>
                            </template>
                            <th class="px-2 py-2 text-right">Total Budget</th>
                            <template v-if="tampilan === 'realisasi'">
                                <th v-for="k in KOMPONEN" :key="k.kunci" class="px-2 py-2 text-right">{{ k.pendek }}</th>
                            </template>
                            <th class="px-2 py-2 text-right">Total Realisasi</th>
                            <th class="px-2 py-2 text-left">Status</th>
                            <th class="px-2 py-2 text-left">Pilihan Costing</th>
                            <th class="px-2 py-2 text-right">Booked Revenue</th>
                            <th class="px-2 py-2 text-right">% HPP/Rev</th>
                            <th v-if="bisaUbah" class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(b, i) in baris" :key="i" :class="hitung.unit[i]?.terkunci ? 'bg-neutral-50/60 dark:bg-neutral-900/40' : ''">
                            <td class="px-2 py-1.5 tabular-nums text-neutral-500">{{ i + 1 }}</td>
                            <td class="px-2 py-1.5"><input v-model="b.no_spr" :disabled="! bisaUbah" :class="inputCls" /></td>
                            <td class="px-2 py-1.5"><input v-model="b.nama_customer" :disabled="! bisaUbah" :class="inputCls" /></td>
                            <td class="px-2 py-1.5"><input v-model="b.unit_rumah" :disabled="! bisaUbah" :class="inputCls" class="font-mono text-xs" /></td>
                            <td class="px-2 py-1.5"><input v-model="b.tipe" :disabled="! bisaUbah" :class="inputCls" /></td>
                            <td class="px-2 py-1.5 w-24"><MoneyInput v-model="b.lt" :decimals="2" :disabled="! bisaUbah" /></td>
                            <td class="px-2 py-1.5 w-24"><MoneyInput v-model="b.lb" :decimals="2" :disabled="! bisaUbah" /></td>
                            <template v-if="tampilan === 'budget'">
                                <td v-for="k in KOMPONEN" :key="k.kunci" class="px-2 py-1.5 text-right tabular-nums">{{ rp(hitung.unit[i]?.budget?.[k.kunci]) }}</td>
                            </template>
                            <td class="px-2 py-1.5 text-right font-medium tabular-nums">{{ rp(hitung.unit[i]?.budget_total) }}</td>
                            <template v-if="tampilan === 'realisasi'">
                                <td v-for="k in KOMPONEN" :key="k.kunci" class="px-2 py-1.5 text-right tabular-nums">{{ rp(hitung.unit[i]?.realisasi?.[k.kunci]) }}</td>
                            </template>
                            <td class="px-2 py-1.5 text-right font-medium tabular-nums">{{ rp(hitung.unit[i]?.realisasi_total) }}</td>
                            <td class="px-2 py-1.5 w-32">
                                <select v-model="b.status" :disabled="! bisaUbah" :class="selCls" @change="ubahStatus(b)">
                                    <option v-for="(lbl, k) in statusOpsi" :key="k" :value="k">{{ lbl }}</option>
                                </select>
                            </td>
                            <td class="px-2 py-1.5 w-44">
                                <select v-model="b.costing" :disabled="! bisaUbah || b.status === 'belum'" :class="selCls">
                                    <option v-for="(lbl, k) in costingOpsi" :key="k" :value="k">{{ lbl }}</option>
                                </select>
                            </td>
                            <td class="px-2 py-1.5 w-36"><MoneyInput v-model="b.booked_revenue" :decimals="2" :disabled="! bisaUbah" /></td>
                            <!-- Revenue nol memulangkan "—", BUKAN #DIV/0! seperti di lampiran. -->
                            <td class="px-2 py-1.5 text-right tabular-nums">{{ persen(hitung.unit[i]?.persen_hpp) }}</td>
                            <td v-if="bisaUbah" class="px-2 py-1.5 text-right">
                                <button type="button" class="text-neutral-400 hover:text-rose-600" :aria-label="'Hapus unit ' + (b.unit_rumah || (i + 1))" @click="hapus(i)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! baris.length">
                            <td :colspan="20" class="px-3 py-8 text-center text-neutral-500">
                                Belum ada unit. Tarik dari Front Office, atau tambahkan satu per satu.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="baris.length" class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <tr>
                            <td class="px-2 py-2.5" colspan="5">Jumlah</td>
                            <td class="px-2 py-2.5 text-right tabular-nums">{{ m2(hitung.unitTotal.lt) }}</td>
                            <td class="px-2 py-2.5 text-right tabular-nums">{{ m2(hitung.unitTotal.lb) }}</td>
                            <template v-if="tampilan === 'budget'">
                                <td v-for="k in KOMPONEN" :key="k.kunci" class="px-2 py-2.5 text-right tabular-nums">
                                    {{ rp(hitung.ringkas.booked_standard[k.kunci] + hitung.ringkas.belum_booked[k.kunci]) }}
                                </td>
                            </template>
                            <td class="px-2 py-2.5 text-right tabular-nums">{{ rp(hitung.unitTotal.budget) }}</td>
                            <template v-if="tampilan === 'realisasi'">
                                <td v-for="k in KOMPONEN" :key="k.kunci" class="px-2 py-2.5 text-right tabular-nums">{{ rp(hitung.rekon[k.kunci].dibebankan) }}</td>
                            </template>
                            <td class="px-2 py-2.5 text-right tabular-nums">{{ rp(hitung.unitTotal.realisasi) }}</td>
                            <td class="px-2 py-2.5" colspan="2"></td>
                            <td class="px-2 py-2.5 text-right tabular-nums">{{ rp(hitung.unitTotal.revenue) }}</td>
                            <td class="px-2 py-2.5"></td>
                            <td v-if="bisaUbah"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-3 text-xs text-neutral-500">
                Baris berlatar abu adalah unit yang HPP-nya sudah <b>terkunci</b> (Sudah Booked + Standard Costing) —
                ia tidak lagi ikut menanggung realisasi berjalan, dan realisasinya nol karena memang tidak dibebani.
                Unit yang belum booked tidak bisa mengunci pilihan costing-nya.
            </p>

            <p v-if="galat" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galat }}</p>
            <div v-if="bisaUbah" class="mt-3 flex justify-end">
                <Button :disabled="menyimpan" @click="simpan"><Save class="h-4 w-4" /> {{ menyimpan ? 'Menyimpan…' : 'Simpan Rincian COGS' }}</Button>
            </div>
        </div>
    </Kepala>
</template>
