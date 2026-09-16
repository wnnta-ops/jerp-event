<script setup lang="ts">
/**
 * KPR → Query/Report → Kalkulator KPR.
 *
 * Pindahan dari aplikasi statis "Kalkulator KPR Java Residence". Alur, kolom, dan SELURUH
 * angkanya dipertahankan sama persis — mesin hitungnya dipakai apa adanya dari kprCalc.ts
 * dan kesamaannya dikunci uji sidik jari (tests/Manual/uji-kalkulator-kpr-mesin.cjs).
 *
 * Yang disesuaikan hanya wadahnya: palet aslinya (navy #12314A + oranye #F37321 + kertas
 * #FBF4EE) dipertahankan sebagai identitas, tetapi dibuat punya versi gelap supaya tidak
 * menjadi satu-satunya layar yang menyala putih saat aplikasi dalam mode gelap.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RotateCcw, ChevronRight, ChevronDown, Table2 } from 'lucide-vue-next';
import { buildAmortization, buildYearlySummary, hitungDp, idr, minSalaryFor, quickSummary, type KprScheme } from './kprCalc';

const props = defineProps<{ config: any }>();

const banks = computed<any[]>(() => props.config?.banks ?? []);

/* ── Alur layar ───────────────────────────────────────────────────────────────────────
   Urutan & durasinya persis aslinya: judul 1,2 dtk → bulan berlaku 1,2 dtk → foto tim
   1,2 dtk → memudar 0,7 dtk. Bedanya hanya satu: di sini urutan itu BISA dilewati
   (klik / Esc / tombol "Lewati"). Layar ini dibuka berkali-kali dalam sehari oleh tim
   sendiri, bukan sekali oleh pengunjung. */
type Tahap = 'intro' | 'pilih' | 'promo' | 'kalkulator';
const tahap = ref<Tahap>('intro');
const introStep = ref(0);          // 0 judul · 1 bulan berlaku · 2 foto tim
const introMemudar = ref(false);
const jamTayang: number[] = [];

function bersihkanJam() {
    jamTayang.splice(0).forEach((id) => window.clearTimeout(id));
}
function jalankanIntro() {
    bersihkanJam();
    introStep.value = 0;
    introMemudar.value = false;
    jamTayang.push(window.setTimeout(() => { introStep.value = 1; }, 1200));
    jamTayang.push(window.setTimeout(() => { introStep.value = 2; }, 2400));
    jamTayang.push(window.setTimeout(() => { introMemudar.value = true; }, 3600));
    jamTayang.push(window.setTimeout(() => { tahap.value = 'pilih'; }, 4300));
}
function lewatiIntro() {
    if (tahap.value !== 'intro') return;
    bersihkanJam();
    introMemudar.value = false;
    tahap.value = 'pilih';
}
function tombolEsc(e: KeyboardEvent) {
    if (e.key === 'Escape') lewatiIntro();
}
onMounted(() => { jalankanIntro(); window.addEventListener('keydown', tombolEsc); });
onBeforeUnmount(() => { bersihkanJam(); window.removeEventListener('keydown', tombolEsc); });

/* ── Pilih bank & slide promo ─────────────────────────────────────────────────────── */
const bankIdx = ref(0);
const bank = computed<any>(() => banks.value[bankIdx.value] ?? null);
const promoSlide = ref(1);

const punyaPromo = computed(() => {
    const p = bank.value?.promo;

    return !! (p?.enabled && (p.slide1Text || p.slide2Text || p.slide2Image));
});
/** Slide 2 hanya ditayangkan bila ia memang punya isi. */
const promoPunyaSlide2 = computed(() => {
    const p = bank.value?.promo;

    return !! (p?.slide2Text || p?.slide2Image);
});

function pilihBank(i: number) {
    bankIdx.value = i;
    resetInput();
    if (punyaPromo.value) {
        promoSlide.value = 1;
        tahap.value = 'promo';
    } else {
        tahap.value = 'kalkulator';
    }
}
function lanjutPromo() {
    if (promoSlide.value === 1 && promoPunyaSlide2.value) promoSlide.value = 2;
    else tahap.value = 'kalkulator';
}
function gantiBank() {
    tahap.value = 'pilih';
}

/** *tebal* → <strong>, baris baru → <br>. Di-escape DULU: teksnya datang dari panel
 *  pengaturan dan dirender sebagai HTML — persis urutan yang dipakai aplikasi aslinya. */
function formatPromo(teks: string): string {
    return String(teks ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*([^*]+)\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>');
}

/* ── Input kalkulator ─────────────────────────────────────────────────────────────── */
const harga = ref(0);
const utj = ref(0);
const freeDp = ref(false);
const extraDpOn = ref(false);
const extraDp = ref(0);
const tenorYears = ref(15);
const schemeIdx = ref(0);
const subsidi = ref(false);
const dpTerbuka = ref(false);
const tabelTerbuka = ref(false);

const skema = computed<KprScheme | null>(() => bank.value?.schemes?.[schemeIdx.value] ?? null);

/** Subsidi bunga tahun pertama hanya ada pada BTN — sama seperti aslinya, dikenali dari id. */
const adaSubsidi = computed(() => bank.value?.id === 'btn');

const tenorMin = computed(() => Math.max(
    Number(bank.value?.tenor?.minYears ?? 1) || 1,
    Number(skema.value?.minTenorYears ?? 0) || 0,
) || 1);
/* Aslinya batas atas slider ditulis mati 30 dan TIDAK pernah mengikuti pengaturan, sehingga
   bank yang disetel maksimal 25 tahun tetap bisa ditarik ke 30. Di sini ia mengikuti
   pengaturan — kalau tidak, kolom "Tenor maksimum" di panel pengaturan tidak berarti apa-apa. */
const tenorMax = computed(() => Number(bank.value?.tenor?.maxYears ?? 30) || 30);

function resetInput() {
    harga.value = 0;
    utj.value = Number(banks.value[bankIdx.value]?.utjAmount ?? 0) || 0;
    freeDp.value = false;
    extraDpOn.value = false;
    extraDp.value = 0;
    schemeIdx.value = 0;
    subsidi.value = false;
    dpTerbuka.value = false;
    tabelTerbuka.value = false;
    tenorYears.value = Math.min(Math.max(15, tenorMin.value), tenorMax.value);
}
watch([tenorMin, tenorMax], () => {
    if (tenorYears.value < tenorMin.value) tenorYears.value = tenorMin.value;
    if (tenorYears.value > tenorMax.value) tenorYears.value = tenorMax.value;
});
watch(bankIdx, () => { if (! adaSubsidi.value) subsidi.value = false; });

/* ── Rumus DP & plafon ─────────────────────────────────────────────────────────────
   Rumusnya ada di kprCalc.ts, bukan di sini: ia aturan bisnis yang harus bisa diuji
   langsung, bukan detail tampilan. */
const dpPct = computed(() => Number(bank.value?.dpDefaultPercent ?? 5));
const dpTambahan = computed(() => (extraDpOn.value ? Math.max(0, extraDp.value) : 0));
const dp = computed(() => hitungDp(harga.value, utj.value, dpPct.value, freeDp.value, dpTambahan.value));
const dpFull = computed(() => dp.value.dpFull);
const dpSubsidi = computed(() => dp.value.dpSubsidi);
const termin1 = computed(() => dp.value.termin1);
const termin3 = computed(() => dp.value.termin3);
const plafon = computed(() => dp.value.plafon);

/* ── Hasil hitung ─────────────────────────────────────────────────────────────────── */
const tenorMonths = computed(() => tenorYears.value * 12);
const potongan = computed(() => (subsidi.value && adaSubsidi.value ? 2 : 0));

const baris = computed(() => (skema.value && plafon.value > 0 && tenorMonths.value > 0
    ? buildAmortization(skema.value, plafon.value, tenorMonths.value, potongan.value, 12)
    : []));
const tahunan = computed(() => buildYearlySummary(baris.value));
const ringkas = computed(() => (skema.value && plafon.value > 0
    ? quickSummary(skema.value, plafon.value, tenorMonths.value, potongan.value, 12)
    : { firstPayment: 0, lastPayment: 0, changesOverTime: false }));
const gajiMinimum = computed(() => minSalaryFor(ringkas.value.lastPayment));

const plafonMin = computed(() => Number(bank.value?.plafon?.min ?? 0));
const plafonMax = computed(() => Number(bank.value?.plafon?.max ?? 0));
const catatanPlafon = computed(() => {
    if (plafon.value <= 0) return '';
    if (plafonMin.value && plafon.value < plafonMin.value) return ` · Plafon di bawah minimum (${idr(plafonMin.value)})`;
    if (plafonMax.value && plafon.value > plafonMax.value) return ` · Plafon di atas maksimum (${idr(plafonMax.value)})`;

    return '';
});

/** Ringkasan suku bunga pada tab skema — dua desimal, persis aslinya. */
function ringkasanSkema(s: any): string {
    if (s.type === 'fixed_floating') return `${Number(s.fixedRatePercent).toFixed(2)}% (${s.fixedYears}th) → ${Number(s.floatingRatePercent).toFixed(2)}%`;
    if (s.type === 'tiered') return (s.tiers ?? []).map((t: any) => `${Number(t.ratePercent).toFixed(2)}%`).join(' → ') + ` → ${Number(s.floatingRatePercent).toFixed(2)}%`;

    return `${Number(s.ratePercent).toFixed(2)}%`;
}
function catatanSkema(s: any): string {
    if (! s) return '';
    if (s.type === 'fixed_floating') return `Bunga ${s.fixedRatePercent}% tetap selama ${s.fixedYears} tahun pertama, lalu mengikuti bunga floating ${s.floatingRatePercent}% untuk sisa tenor.`;
    if (s.type === 'tiered') return `Bunga berjenjang: ${(s.tiers ?? []).map((t: any) => `${t.tierLabel ? t.tierLabel + ' ' : ''}${t.ratePercent}% selama ${t.years} th`).join(', ')}, lalu floating ${s.floatingRatePercent}%.`;
    if (s.type === 'efektif') return `Bunga efektif ${s.ratePercent}% — pokok dibayar rata, bunga dihitung dari sisa pinjaman sehingga angsuran menurun.`;

    return `Bunga anuitas ${s.ratePercent}% tetap sepanjang tenor.`;
}

/* ── Input berpemisah ribuan ──────────────────────────────────────────────────────── */
const angka = (s: string) => parseInt(String(s).replace(/[^\d]/g, ''), 10) || 0;
const ribuan = (n: number) => (n ? n.toLocaleString('id-ID') : '');
function ketikHarga(e: Event) { harga.value = angka((e.target as HTMLInputElement).value); }
function ketikUtj(e: Event) { utj.value = angka((e.target as HTMLInputElement).value); }
function ketikExtra(e: Event) { extraDp.value = angka((e.target as HTMLInputElement).value); }
</script>

<template>
    <div class="kpr-kalk">
        <!-- ══ Urutan intro ══ -->
        <Transition name="kpr-fade">
            <div v-if="tahap === 'intro'" class="kalk-intro" :class="{ memudar: introMemudar }" @click="lewatiIntro">
                <button type="button" class="kalk-lewati" @click.stop="lewatiIntro">Lewati ▸</button>

                <div v-show="introStep === 0" class="kalk-slide kalk-slide-judul">
                    <h1>KPR Java Residence</h1>
                    <img v-if="config.brandLogo" :src="config.brandLogo" alt="Java Residence" class="kalk-brand" />
                </div>

                <div v-show="introStep === 1" class="kalk-slide kalk-slide-bulan">
                    <h1 v-if="config.effectiveMonth">Berlaku untuk bulan {{ config.effectiveMonth }}</h1>
                </div>

                <div v-show="introStep === 2" class="kalk-slide kalk-slide-foto">
                    <picture v-if="config.introPhoto">
                        <source v-if="config.introPhotoMobile" media="(max-width: 640px)" :srcset="config.introPhotoMobile" />
                        <img :src="config.introPhoto" alt="Tim Java Residence" />
                    </picture>
                    <div v-else class="kalk-foto-cadangan">Foto Tim Java Residence</div>
                    <span class="kalk-foto-caption">Java Residence</span>
                </div>
            </div>
        </Transition>

        <!-- ══ Pilih bank ══ -->
        <div v-if="tahap === 'pilih'" class="space-y-6">
            <div class="text-center">
                <p class="kalk-eyebrow justify-center">Kalkulator Angsuran Rumah</p>
                <h1 class="kalk-headline mx-auto">Pilih bank untuk memulai simulasi.</h1>
                <p class="kalk-sub mx-auto">Plafon, suku bunga, dan tenor mengikuti ketentuan terbaru tiap bank pada Kategori Perhitungan KPR.</p>
            </div>
            <div class="mx-auto grid max-w-4xl grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <button v-for="(b, i) in banks" :key="b.id" type="button" class="kalk-bank" @click="pilihBank(i)">
                    <span class="kalk-bank-logo">
                        <img v-if="b.logoImage" :src="b.logoImage" :alt="'Logo ' + b.name" />
                        <span v-else class="kalk-bank-nama">{{ b.name }}</span>
                    </span>
                    <span class="kalk-bank-kaki">{{ b.fullName || b.name }}</span>
                </button>
            </div>
        </div>

        <!-- ══ Slide promo ══ -->
        <div v-else-if="tahap === 'promo'" class="kalk-promo">
            <button type="button" class="kalk-lewati" @click="lanjutPromo">Lewati ▸</button>
            <img v-if="promoSlide === 2 && bank?.promo?.slide2Image" :src="bank.promo.slide2Image" alt="" class="kalk-promo-img" />
            <div class="kalk-promo-teks" :class="{ 'kalk-promo-atas-gambar': promoSlide === 2 && bank?.promo?.slide2Image }">
                <!-- eslint-disable-next-line vue/no-v-html -- sudah di-escape di formatPromo() -->
                <p v-html="formatPromo(promoSlide === 1 ? bank?.promo?.slide1Text : bank?.promo?.slide2Text)"></p>
            </div>
        </div>

        <!-- ══ Kalkulator ══ -->
        <div v-else-if="tahap === 'kalkulator'" class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="kalk-eyebrow">Kalkulator Angsuran Rumah · {{ bank?.name }}</p>
                    <h1 class="kalk-headline">Hitung cicilan KPR sebelum ke bank.</h1>
                </div>
                <button type="button" class="kalk-ganti" @click="gantiBank">
                    <RotateCcw class="h-3.5 w-3.5" /> Ganti bank
                </button>
            </div>

            <p class="kalk-disclaimer">*Simulasi bersifat estimasi, bukan penawaran resmi bank</p>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
                <!-- ── Kolom input ── -->
                <div class="card space-y-4 p-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="kalk-field">
                            <label for="kalk-harga">Harga rumah</label>
                            <div class="kalk-uang">
                                <span>Rp</span>
                                <input id="kalk-harga" type="text" inputmode="numeric" :value="ribuan(harga)" placeholder="0" @input="ketikHarga" />
                            </div>
                        </div>
                        <div class="kalk-field">
                            <label for="kalk-utj">Uang tanda jadi (UTJ)</label>
                            <div class="kalk-uang">
                                <span>Rp</span>
                                <input id="kalk-utj" type="text" inputmode="numeric" :value="ribuan(utj)" placeholder="0" @input="ketikUtj" />
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        <label class="kalk-centang"><input v-model="freeDp" type="checkbox" /> Free DP</label>
                        <label class="kalk-centang"><input v-model="extraDpOn" type="checkbox" /> Tambah DP lagi</label>
                        <label v-if="adaSubsidi" class="kalk-centang"><input v-model="subsidi" type="checkbox" /> Subsidi bunga 2% (12 bulan pertama)</label>
                    </div>

                    <div v-if="extraDpOn" class="kalk-field max-w-xs">
                        <label for="kalk-extra">DP tambahan</label>
                        <div class="kalk-uang">
                            <span>Rp</span>
                            <input id="kalk-extra" type="text" inputmode="numeric" :value="ribuan(extraDp)" placeholder="0" @input="ketikExtra" />
                        </div>
                    </div>

                    <div class="kalk-field">
                        <label for="kalk-tenor">Tenor</label>
                        <div class="flex items-center gap-3">
                            <input id="kalk-tenor" v-model.number="tenorYears" type="range" :min="tenorMin" :max="tenorMax" step="1" class="kalk-slider" />
                            <span class="kalk-range-val">{{ tenorYears }} th</span>
                        </div>
                        <p v-if="tenorMin > (bank?.tenor?.minYears ?? 1)" class="kalk-hint">Skema ini menuntut tenor minimal {{ tenorMin }} tahun.</p>
                    </div>

                    <div>
                        <p class="kalk-section-title">Skema bunga</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button v-for="(s, i) in bank?.schemes ?? []" :key="s.id" type="button"
                                    class="kalk-tab" :class="{ aktif: i === schemeIdx }" @click="schemeIdx = i">
                                <span>{{ s.label }}</span>
                                <span class="kalk-tab-rate">{{ ringkasanSkema(s) }}</span>
                            </button>
                        </div>
                        <p class="kalk-hint mt-2">{{ catatanSkema(skema) }}</p>
                    </div>
                </div>

                <!-- ── Slip hasil ── -->
                <div class="kalk-slip-wrap">
                    <div class="kalk-slip">
                        <div class="kalk-slip-head">
                            <p class="kalk-slip-label">Estimasi Angsuran Pertama / Bulan</p>
                            <p class="kalk-slip-angka">{{ idr(ringkas.firstPayment) }}<small>/bln</small></p>
                            <p class="kalk-slip-note">
                                {{ ringkas.changesOverTime
                                    ? `Angsuran berubah menjadi ${idr(ringkas.lastPayment)}/bln pada tahap berikutnya.`
                                    : 'Angsuran tetap sampai lunas.' }}
                            </p>
                        </div>

                        <div class="kalk-slip-body">
                            <div class="kalk-slip-row"><span>Harga rumah</span><b>{{ idr(harga) }}</b></div>
                            <div class="kalk-slip-row"><span>Uang tanda jadi (UTJ)</span><b>{{ idr(utj) }}</b></div>
                            <button type="button" class="kalk-slip-row kalk-slip-row-btn" @click="dpTerbuka = ! dpTerbuka">
                                <span>DP {{ dpPct }}%
                                    <ChevronDown class="inline h-3 w-3 transition-transform" :class="{ 'rotate-180': dpTerbuka }" />
                                </span>
                                <b v-if="freeDp"><s class="italic opacity-60">{{ idr(dpFull) }}</s> <em class="kalk-free">Free DP</em></b>
                                <b v-else>{{ idr(Math.max(0, dpFull - utj)) }}</b>
                            </button>
                            <template v-if="dpTerbuka">
                                <div class="kalk-slip-row kalk-slip-sub"><span>Subsidi DP 20%</span><b><s class="italic opacity-60">{{ idr(dpSubsidi) }}</s></b></div>
                                <div class="kalk-slip-row kalk-slip-sub"><span>DP Termin 1</span><b>{{ idr(termin1) }}</b></div>
                                <div class="kalk-slip-row kalk-slip-sub"><span>DP Termin 2</span><b>{{ idr(termin1) }}</b></div>
                                <div class="kalk-slip-row kalk-slip-sub"><span>DP Termin 3</span><b>{{ idr(termin3) }}</b></div>
                            </template>
                            <div v-if="dpTambahan > 0" class="kalk-slip-row"><span>DP Tambahan</span><b>{{ idr(dpTambahan) }}</b></div>
                            <div class="kalk-slip-row"><span>Plafon KPR</span><b>{{ idr(plafon) }}</b></div>
                            <div class="kalk-slip-row"><span>Tenor</span><b>{{ tenorYears }} tahun</b></div>
                            <div class="kalk-slip-row"><span>Skema</span><b>{{ skema?.label }}</b></div>
                        </div>

                        <div class="kalk-slip-body kalk-slip-gaji">
                            <div class="kalk-slip-row"><span>Gaji minimum disarankan</span><b>{{ idr(gajiMinimum) }}</b></div>
                        </div>

                        <div class="kalk-slip-foot">
                            Plafon tersedia: {{ idr(plafonMin) }} – {{ idr(plafonMax) }}{{ catatanPlafon }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Ringkasan per tahun ── -->
            <div v-if="tahunan.length" class="card p-5">
                <p class="kalk-section-title">Ringkasan Angsuran per Tahun</p>
                <p class="kalk-hint">Total dan rata-rata angsuran tiap tahun.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="kalk-ledger">
                        <thead>
                            <tr>
                                <th class="text-left">Tahun</th><th>Angsuran/bulan</th><th>Total 1 Tahun</th>
                                <th>Total Bunga</th><th>Sisa Pinjaman</th><th>Suku Bunga</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="y in tahunan" :key="y.year">
                                <td class="text-left">{{ y.year }}</td>
                                <td>{{ idr(y.firstMonthPayment) }}</td>
                                <td>{{ idr(y.totalPayment) }}</td>
                                <td>{{ idr(y.totalInterest) }}</td>
                                <td>{{ idr(y.endBalance) }}</td>
                                <td>{{ y.rateLabel }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button type="button" class="kalk-tombol-tabel" @click="tabelTerbuka = ! tabelTerbuka">
                    <Table2 class="h-4 w-4" />
                    {{ tabelTerbuka ? 'Sembunyikan tabel angsuran' : 'Lihat tabel angsuran lengkap' }}
                    <ChevronRight class="h-4 w-4 transition-transform" :class="{ 'rotate-90': tabelTerbuka }" />
                </button>

                <div v-if="tabelTerbuka" class="mt-4">
                    <p class="kalk-section-title">Tabel Angsuran Bulanan</p>
                    <p class="kalk-hint">Rincian pokok, bunga, dan sisa pinjaman setiap bulan.</p>
                    <div class="kalk-ledger-scroll mt-3">
                        <table class="kalk-ledger">
                            <thead>
                                <tr>
                                    <th class="text-left">Bulan</th><th>Angsuran</th><th>Pokok</th>
                                    <th>Bunga</th><th>Sisa Pinjaman</th><th>Suku Bunga</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in baris" :key="r.month" :class="{ 'kalk-tahun-baru': r.month % 12 === 1 }">
                                    <td class="text-left">{{ r.month }}</td>
                                    <td>{{ idr(r.payment) }}</td>
                                    <td>{{ idr(r.principal) }}</td>
                                    <td>{{ idr(r.interest) }}</td>
                                    <td>{{ idr(r.balance) }}</td>
                                    <td>{{ r.rateLabel }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <p v-else class="kalk-kosong">Isi harga rumah untuk melihat estimasi angsuran.</p>
        </div>
    </div>
</template>

<style scoped>
/* Palet aslinya dipertahankan sebagai identitas, dengan pasangan gelapnya supaya layar ini
   tidak menjadi satu-satunya yang menyala putih saat aplikasi dalam mode gelap. */
.kpr-kalk {
    --ink: #12314A;
    --ink-soft: #4C6478;
    --paper: #FBF4EE;
    --stub: #FFFFFF;
    --line: #E7D8C9;
    --line-2: #D8C4AE;
    --gold: #F37321;
    --gold-deep: #C85A16;
    --green: #2E6B4F;
}
:global(.dark) .kpr-kalk {
    --ink: #E8EDF2;
    --ink-soft: #94A7B8;
    --paper: #1B2430;
    --stub: #141C26;
    --line: #2C3846;
    --line-2: #3A4A5C;
    --gold: #F58A43;
    --gold-deep: #FFA866;
    --green: #4CAF80;
}

.kalk-eyebrow {
    display: flex; align-items: center; gap: 8px;
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--gold-deep);
}
.kalk-eyebrow::before { content: ''; width: 22px; height: 1px; background: var(--gold); }
.kalk-eyebrow.justify-center::before { display: none; }
.kalk-headline {
    font-weight: 800; letter-spacing: -.02em; line-height: 1.1; color: var(--ink);
    font-size: clamp(22px, 3vw, 32px); max-width: 20ch; margin-top: 4px;
}
.kalk-sub { color: var(--ink-soft); font-size: 14px; line-height: 1.6; max-width: 52ch; margin-top: 8px; }
.kalk-disclaimer {
    background: color-mix(in srgb, var(--gold) 14%, transparent);
    border: 1px solid color-mix(in srgb, var(--gold) 35%, transparent);
    color: var(--gold-deep); font-size: 11.5px; font-weight: 700;
    border-radius: 8px; padding: 8px 12px;
}
.kalk-kosong { color: var(--ink-soft); font-size: 13px; text-align: center; padding: 28px 0; }

/* ── Intro ── */
.kalk-intro {
    position: relative; display: flex; align-items: center; justify-content: center;
    min-height: 62vh; border-radius: 14px; overflow: hidden; cursor: pointer;
    transition: opacity .7s ease;
}
.kalk-intro.memudar { opacity: 0; }
.kalk-slide { width: 100%; text-align: center; padding: 48px 24px; }
.kalk-slide h1 { font-weight: 800; line-height: 1.15; font-size: clamp(26px, 5vw, 44px); }
.kalk-slide-judul { background: var(--paper); color: var(--ink); }
.kalk-slide-bulan { background: var(--gold); color: #fff; }
.kalk-brand { max-width: 160px; max-height: 90px; object-fit: contain; margin: 14px auto 0; }
.kalk-slide-foto { position: relative; background: var(--ink); min-height: 62vh; padding: 0; }
.kalk-slide-foto img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center top; }
.kalk-slide-foto::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(18,49,74,.05), rgba(18,49,74,.75));
}
.kalk-foto-cadangan {
    display: flex; align-items: center; justify-content: center; height: 62vh;
    color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: .06em; font-size: 15px; font-weight: 600;
}
.kalk-foto-caption {
    position: absolute; left: 32px; bottom: 28px; z-index: 2; color: #fff;
    font-weight: 800; font-size: clamp(20px, 3vw, 28px);
}
.kalk-lewati {
    position: absolute; top: 16px; right: 16px; z-index: 20;
    background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.5); color: #fff;
    font-weight: 600; font-size: 13px; padding: 8px 15px; border-radius: 4px; backdrop-filter: blur(4px);
}
.kalk-lewati:hover { background: rgba(255,255,255,.3); }
.kalk-slide-judul ~ .kalk-lewati, .kalk-intro:has(.kalk-slide-judul:not([style*="none"])) .kalk-lewati {
    color: var(--ink); border-color: var(--line-2); background: rgba(0,0,0,.05);
}

/* ── Pilih bank ── */
.kalk-bank {
    display: flex; flex-direction: column; align-items: center; gap: 12px;
    background: var(--stub); border: 1px solid var(--line); border-radius: 12px; padding: 28px 20px;
    transition: border-color .15s, transform .15s, box-shadow .15s;
}
.kalk-bank:hover { border-color: var(--gold); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(243,115,33,.16); }
.kalk-bank:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
.kalk-bank-logo { display: flex; align-items: center; justify-content: center; height: 108px; width: 100%; }
.kalk-bank-logo img { max-height: 108px; max-width: 100%; object-fit: contain; }
.kalk-bank-nama { font-weight: 700; font-size: 18px; color: var(--ink); }
.kalk-bank-kaki { font-size: 12px; color: var(--ink-soft); }

/* ── Promo ── */
.kalk-promo {
    position: relative; display: flex; align-items: center; justify-content: center;
    min-height: 62vh; background: var(--gold); color: #fff; border-radius: 14px; overflow: hidden;
}
.kalk-promo-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; object-position: center; }
.kalk-promo-teks { position: relative; z-index: 2; padding: 40px 24px; max-width: 34ch; }
.kalk-promo-teks p { font-size: clamp(18px, 2.4vw, 28px); line-height: 1.6; white-space: normal; }
.kalk-promo-atas-gambar p { text-shadow: 0 2px 10px rgba(18,49,74,.85); }

/* ── Field ── */
.kalk-field label { display: block; font-size: 12.5px; font-weight: 600; letter-spacing: .02em; color: var(--ink-soft); margin-bottom: 6px; }
.kalk-uang { display: flex; align-items: center; border: 1px solid var(--line-2); border-radius: 8px; background: var(--stub); overflow: hidden; }
.kalk-uang span {
    padding: 0 10px; font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 13px;
    color: var(--ink-soft); border-right: 1px solid var(--line);
    align-self: stretch; display: flex; align-items: center;
}
.kalk-uang input {
    flex: 1; min-width: 0; height: 38px; border: 0; background: transparent; padding: 0 12px;
    font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 15px; color: var(--ink);
}
.kalk-uang input:focus { outline: none; }
.kalk-uang:focus-within { border-color: var(--gold); }
.kalk-centang { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--ink); cursor: pointer; }
.kalk-centang input { width: 15px; height: 15px; accent-color: var(--gold); }
.kalk-slider { flex: 1; accent-color: var(--gold); }
.kalk-range-val {
    font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 13px; font-weight: 600;
    min-width: 64px; text-align: right; color: var(--ink);
}
.kalk-hint { font-size: 12.5px; color: var(--ink-soft); line-height: 1.5; }
.kalk-section-title { font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--ink); }

.kalk-tab {
    display: flex; flex-direction: column; gap: 2px; align-items: flex-start;
    border: 1px solid var(--line-2); border-radius: 8px; padding: 8px 14px; background: var(--stub);
    font-size: 13px; font-weight: 600; color: var(--ink); transition: all .15s;
}
.kalk-tab:hover { border-color: var(--gold); }
.kalk-tab.aktif { background: var(--gold); border-color: var(--gold); color: #fff; }
.kalk-tab-rate { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 12px; font-weight: 500; color: var(--gold-deep); }
.kalk-tab.aktif .kalk-tab-rate { color: rgba(255,255,255,.92); }

.kalk-ganti {
    display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600;
    color: var(--gold-deep); border: 1px solid var(--line-2); border-radius: 8px; padding: 7px 12px;
}
.kalk-ganti:hover { border-color: var(--gold); }

/* ── Slip ── */
.kalk-slip-wrap { position: sticky; top: 16px; align-self: start; }
.kalk-slip {
    position: relative; background: var(--stub); border: 1px solid var(--line); border-radius: 4px;
    box-shadow: 0 12px 32px -16px rgba(18,49,74,.35); margin-top: 14px;
}
/* Tepi robekan struk — ciri khas kartu hasil pada aplikasi aslinya. */
.kalk-slip::before {
    content: ''; position: absolute; left: 0; right: 0; top: -1px; height: 14px;
    background-image: radial-gradient(circle at 10px 0, transparent 6px, var(--stub) 6.5px);
    background-size: 20px 14px; background-repeat: repeat-x;
}
.kalk-slip-head { padding: 26px 24px 18px; border-bottom: 1px dashed var(--line-2); }
.kalk-slip-label {
    font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 11px;
    text-transform: uppercase; letter-spacing: .1em; color: var(--ink-soft);
}
.kalk-slip-angka {
    font-family: 'IBM Plex Mono', ui-monospace, monospace; font-weight: 600; line-height: 1;
    font-size: clamp(26px, 3.2vw, 34px); color: var(--ink); margin-top: 8px;
}
.kalk-slip-angka small { font-size: 15px; font-weight: 500; color: var(--ink-soft); }
.kalk-slip-note { font-size: 12.5px; color: var(--ink-soft); margin-top: 8px; }
.kalk-slip-body { padding: 14px 24px; }
.kalk-slip-gaji { padding-top: 0; }
.kalk-slip-row {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    width: 100%; padding: 7px 0; border-bottom: 1px solid var(--line); font-size: 13px; color: var(--ink-soft);
}
.kalk-slip-row:last-child { border-bottom: 0; }
.kalk-slip-row b { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-weight: 600; color: var(--ink); }
.kalk-slip-row-btn { text-align: left; cursor: pointer; }
.kalk-slip-row-btn:hover span { color: var(--gold-deep); }
.kalk-slip-sub { padding-left: 14px; font-size: 12.5px; }
.kalk-free { font-style: normal; color: var(--green); font-weight: 700; }
.kalk-slip-foot {
    padding: 14px 24px 20px; border-top: 1px dashed var(--line-2);
    font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 11px; letter-spacing: .04em; color: var(--ink-soft);
}

/* ── Tabel ── */
.kalk-ledger {
    width: 100%; border-collapse: collapse; background: var(--stub);
    font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 12.5px; border: 1px solid var(--line);
}
.kalk-ledger th {
    text-align: right; padding: 10px 14px; background: var(--ink); color: #fff;
    font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap;
}
:global(.dark) .kalk-ledger th { color: #0F1720; }
.kalk-ledger td { text-align: right; padding: 8px 14px; border-bottom: 1px solid var(--line); color: var(--ink); white-space: nowrap; }
.kalk-ledger tbody tr:nth-child(even) td { background: var(--paper); }
.kalk-ledger tr.kalk-tahun-baru td { border-top: 1px solid var(--line-2); }
.kalk-ledger-scroll { max-height: 520px; overflow: auto; border: 1px solid var(--line); border-radius: 4px; }
.kalk-tombol-tabel {
    display: inline-flex; align-items: center; gap: 8px; margin-top: 16px;
    font-size: 13px; font-weight: 600; color: var(--gold-deep);
    border: 1px solid var(--line-2); border-radius: 8px; padding: 8px 14px;
}
.kalk-tombol-tabel:hover { border-color: var(--gold); }

.kpr-fade-leave-active { transition: opacity .7s ease; }
.kpr-fade-leave-to { opacity: 0; }

@media (prefers-reduced-motion: reduce) {
    .kalk-intro, .kpr-fade-leave-active, .kalk-bank { transition-duration: .01s; }
    .kalk-bank:hover { transform: none; }
}
@media (max-width: 640px) {
    .kalk-ledger { font-size: 11px; }
    .kalk-ledger th, .kalk-ledger td { padding: 7px 9px; }
    .kalk-slip-wrap { position: static; }
}
</style>
