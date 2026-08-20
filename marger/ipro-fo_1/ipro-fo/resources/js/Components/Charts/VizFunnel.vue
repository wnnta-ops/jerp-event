<script setup lang="ts">
// Corong tahapan — satu proses BERKELANJUTAN yang menyempit dari tahap ke tahap.
//
// Dipakai dua kartu Dashboard: Inisiasi KPR dan Proses KPR. Keduanya dulu berbentuk kolom
// tegak sejajar, dan bentuk itu keliru menggambarkan datanya: kolom sejajar dibaca sebagai
// kategori yang BERDIRI SENDIRI dan saling dibandingkan, padahal tahap 2 adalah bagian dari
// tahap 1 — berkas yang sama, dihitung ulang di tahap berikutnya. Corong menyatakannya
// dengan benar: satu aliran yang menyempit, dan pita penghubung antar tahap membuat
// kesinambungannya terlihat alih-alih harus disimpulkan dari urutan kolom.
//
// Dibaca ATAS ke BAWAH, bukan kiri ke kanan. Dua alasan:
//   1. Corong memang menyempit ke bawah — bentuknya sendiri sudah membawa arti.
//   2. Nama tahap mendapat lebar kartu seutuhnya, sehingga "Profiling Customer" dan
//      "SP3K Terbit" tercetak penuh. Pada kolom tegak nama hanya sepelebar satu slot dan
//      harus dipenggal dua baris.
//
// SENGAJA TANPA PERSENTASE. Yang ditulis hanya jumlah berkas per tahap dan SELISIH terhadap
// tahap sebelumnya (mis. "−11 tertahan") — angka mutlak yang bisa langsung dicocokkan
// dengan daftar berkasnya, bukan angka turunan yang masih harus dihitung balik.
import { computed } from 'vue';
import { TAHAP, kosong, type Baris } from './viz';

const props = withDefaults(defineProps<{
    rows: Baris[];
    warna?: readonly string[] | null;
    satuan?: string;
    /** tinggi satu batang tahap */
    tebal?: number;
    /** jarak antar batang — di ruang inilah pita penghubung & selisihnya digambar */
    sela?: number;
}>(), { warna: null, satuan: 'berkas', tebal: 22, sela: 15 });

const W = 560;      // lebar viewBox; SVG-nya melar mengikuti lebar kartu
const LW = 168;     // talang kiri: nomor + nama tahap
const RW = 62;      // talang kanan: jumlah
const PT = 8;
const PB = 6;
const RMIN = 6;     // lebar batang paling sempit, supaya tahap bernilai kecil tetap terlihat
const R = 4;        // sudut batang

const FX = LW;                    // pangkal bidang corong
const FW = W - LW - RW;           // lebar bidang corong
const CX = FX + FW / 2;           // sumbu tengah corong

// Satu nilai yang bukan bilangan sudah cukup meracuni SELURUH skala dan membuat SVG-nya
// gagal tampil — dijadikan 0 di sini, satu kali, sebelum dipakai skala maupun batang.
const angka = (v: unknown): number => (Number.isFinite(v as number) ? (v as number) : 0);
const nilai = computed(() => props.rows.map((r) => angka(r.v)));
const hampa = computed(() => kosong(nilai.value));

// Skala dipatok pada tahap TERBESAR, bukan tahap pertama: bila suatu tahap ternyata lebih
// banyak daripada tahap sebelumnya (aliran masuk susulan), batangnya tetap muat.
const puncak = computed(() => Math.max(1, ...nilai.value.map((v) => Math.abs(v))));
const lebar = (v: number) => Math.max(RMIN, (Math.abs(v) / puncak.value) * FW);

const H = computed(() => PT + props.rows.length * (props.tebal + props.sela) - props.sela + PB);

const warnaTahap = (i: number) => (props.warna ?? TAHAP)[i % (props.warna ?? TAHAP).length];

/** Batang bersudut membulat di keempat sudutnya. */
function batang(x: number, y: number, w: number, h: number): string {
    const r = Math.min(R, w / 2, h / 2);
    const n = (v: number) => v.toFixed(1);

    return [
        'M' + n(x + r) + ',' + n(y),
        'H' + n(x + w - r), 'Q' + n(x + w) + ',' + n(y) + ' ' + n(x + w) + ',' + n(y + r),
        'V' + n(y + h - r), 'Q' + n(x + w) + ',' + n(y + h) + ' ' + n(x + w - r) + ',' + n(y + h),
        'H' + n(x + r), 'Q' + n(x) + ',' + n(y + h) + ' ' + n(x) + ',' + n(y + h - r),
        'V' + n(y + r), 'Q' + n(x) + ',' + n(y) + ' ' + n(x + r) + ',' + n(y),
        'Z',
    ].join(' ');
}

const tahap = computed(() => props.rows.map((r, i) => {
    const v = angka(r.v);
    const w = lebar(v);
    const y = PT + i * (props.tebal + props.sela);

    return {
        key: i + '-' + r.n,
        no: i + 1,
        nama: String(r.n ?? ''),
        v,
        teks: v.toLocaleString('id-ID'),
        x: CX - w / 2,
        w,
        y,
        yTengah: y + props.tebal / 2 + 3.5,
        d: batang(CX - w / 2, y, w, props.tebal),
        fill: warnaTahap(i),
        judul: (i + 1) + '. ' + r.n + ': ' + v.toLocaleString('id-ID')
            + (props.satuan ? ' ' + props.satuan : ''),
    };
}));

/**
 * Pita penghubung antar tahap: trapesium dari lebar tahap i ke lebar tahap i+1.
 *
 * Inilah yang membuat grafiknya terbaca sebagai SATU aliran. Warnanya mengikuti tahap
 * ASAL dengan opacity rendah — pita itu menggambarkan berkas yang mengalir DARI tahap
 * sebelumnya, jadi warnanya pun datang dari sana.
 */
const pita = computed(() => tahap.value.slice(0, -1).map((a, i) => {
    const b = tahap.value[i + 1];
    const yA = a.y + props.tebal;
    const yB = b.y;
    const selisih = b.v - a.v;
    const n = (v: number) => v.toFixed(1);

    return {
        key: 'p' + i,
        d: ['M' + n(a.x) + ',' + n(yA), 'L' + n(a.x + a.w) + ',' + n(yA),
            'L' + n(b.x + b.w) + ',' + n(yB), 'L' + n(b.x) + ',' + n(yB), 'Z'].join(' '),
        fill: a.fill,
        yTeks: yA + (yB - yA) / 2 + 3,
        // Selisih ditulis sebagai jumlah, bukan persen: "−11" bisa langsung dicocokkan
        // dengan sebelas berkas yang tertahan; "−37%" masih harus dihitung balik dulu.
        teks: selisih === 0 ? '' : (selisih < 0 ? '−' : '+') + Math.abs(selisih).toLocaleString('id-ID'),
        turun: selisih < 0,
        judul: selisih === 0
            ? a.nama + ' → ' + b.nama + ': tidak ada perubahan jumlah'
            : a.nama + ' → ' + b.nama + ': '
                + (selisih < 0
                    ? Math.abs(selisih).toLocaleString('id-ID') + ' ' + props.satuan + ' tertahan di ' + a.nama
                    : selisih.toLocaleString('id-ID') + ' ' + props.satuan + ' masuk di ' + b.nama),
    };
}));
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <svg v-else :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
            <!-- Pita digambar LEBIH DULU supaya batang tahap berada di atasnya. -->
            <g v-for="p in pita" :key="p.key">
                <path data-mark :d="p.d" :fill="p.fill" opacity="0.16"><title>{{ p.judul }}</title></path>
                <text v-if="p.teks" :x="CX" :y="p.yTeks" text-anchor="middle" class="drop"
                      :class="p.turun ? 'turun' : 'naik'">{{ p.teks }}</text>
            </g>

            <g v-for="t in tahap" :key="t.key">
                <!-- Nomor & nama tahap di talang kiri: rata kanan supaya berbaris rapi
                     menuju corongnya, dan tercetak PENUH tanpa dipenggal. -->
                <text :x="14" :y="t.yTengah" class="no">{{ t.no }}</text>
                <text :x="LW - 12" :y="t.yTengah" text-anchor="end" class="dlab">{{ t.nama }}</text>

                <path data-mark :d="t.d" :fill="t.fill"><title>{{ t.judul }}</title></path>

                <text :x="W - RW + 10" :y="t.yTengah" class="vlab">{{ t.teks }}</text>
            </g>
        </svg>
    </div>
</template>

<style scoped>
/* Selisih antar tahap sengaja lebih redup daripada angka tahapnya: ia keterangan
   pelengkap, bukan angka utama yang dicari pembacanya. */
.viz :deep(.drop) { font-size: 10px; font-weight: 700; }
.viz :deep(.drop.turun) { fill: var(--viz-crit); }
.viz :deep(.drop.naik) { fill: var(--viz-muted); }
</style>
