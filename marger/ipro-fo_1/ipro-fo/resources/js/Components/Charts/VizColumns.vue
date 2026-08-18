<script setup lang="ts">
// Kolom tegak — satu deret, satu kolom per kategori, tinggi kolom membawa besaran.
//
// Dipakai empat kartu Dashboard: Inisiasi KPR, Proses KPR, Summary Rekening Ledger,
// dan Aging Hutang. Keempatnya dulu berbentuk batang mendatar; bentuk kolom dipilih
// atas permintaan, dan konsekuensinya ditangani di sini — pada batang mendatar nama
// kategori punya seluruh lebar kartu, pada kolom ia hanya sepelebar satu slot. Karena
// itu komponen ini memenggal nama kategori jadi beberapa baris dan menyingkat label
// rupiah; nama serta nilai PENUH selalu tersedia di tooltip tiap kolom.
//
// Tiga cara pewarnaan — sengaja eksplisit supaya satu entitas tidak berganti warna
// saat muncul di dua kartu berbeda:
//   warna : satu rona berbeda per kolom (kategori sejajar, mis. tahap corong KPR)
//   ramp  : satu rona bergradasi mengikuti URUTAN kolom (kelompok berurut, mis. umur
//           hutang) — diambil mulai indeks 1 karena langkah paling pucat tidak lolos
//           ambang kontras untuk tanda berurut
//   flat  : satu warna untuk semua kolom (deret tunggal; tinggi kolom sudah cukup)
import { computed } from 'vue';
import { SEQ, rp, rpRingkas, kosong, type Baris } from './viz';

const props = withDefaults(defineProps<{
    rows: Baris[];
    warna?: readonly string[] | null;
    ramp?: readonly string[] | null;
    flat?: string | null;
    /** cetak nomor urut di bawah sumbu — dipakai corong, sebab rona tidak membawa urutan */
    nomor?: boolean;
    /** true → label nilai berupa jumlah + satuan; false → rupiah */
    hitung?: boolean;
    satuan?: string;
    /** tinggi bidang gambar, di luar talang label */
    plot?: number;
    /**
     * Berapa baris yang disediakan untuk nama kategori. Naikkan bila nama kategorinya
     * terdiri dari beberapa kata yang semuanya perlu terbaca — Summary Rekening Ledger
     * memakai 3 supaya "BTN SYARIAH 1000001709" tidak kehilangan kata "SYARIAH", yang
     * justru satu-satunya pembeda dari rekening BTN yang lain.
     */
    barisLabel?: number;
}>(), { warna: null, ramp: null, flat: null, nomor: false, hitung: false, satuan: '', plot: 150, barisLabel: 2 });

const W = 560;      // lebar viewBox; SVG-nya melar mengikuti lebar kartu
const PX = 8;       // talang kiri/kanan
const PT = 16;      // ruang label nilai di atas kolom tertinggi
const LH = 11.5;    // tinggi satu baris label sumbu
const R = 4;        // ujung kolom membulat 4px, sisi garis dasar tetap siku
const TEBAL = 26;   // tebal maksimum kolom (~24px pada lebar kartu sebenarnya)
// Perkiraan lebar satu karakter label sumbu (10,5px × 0,60 em), dalam satuan viewBox.
// Angkanya sengaja DILEBIHKAN: kalau meleset, label harus meleset ke arah lebih pendek —
// nama yang tersisa sedikit lebih ringkas masih terbaca, dua nama yang saling menimpa tidak.
const KAR = 6.3;

const IW = W - PX * 2;
// Satu nilai yang bukan bilangan sudah cukup meracuni SELURUH skala: Math.max(0, NaN)
// menghasilkan NaN, seluruh koordinat ikut NaN, dan SVG-nya gagal tampil sama sekali —
// satu baris rusak menghapus tujuh baris yang sebenarnya benar. Karena itu dijadikan 0
// di sini, satu kali, sebelum dipakai skala maupun kolom.
const angka = (v: unknown): number => (Number.isFinite(v as number) ? (v as number) : 0);
const nilai = computed(() => props.rows.map((r) => angka(r.v)));
const hampa = computed(() => kosong(nilai.value));
const bw = computed(() => IW / Math.max(1, props.rows.length));
const tebal = computed(() => Math.min(TEBAL, bw.value * 0.5));
// Berapa karakter yang muat pada satu baris label, dihitung dari lebar slot yang tersedia
// — bukan angka tetap, sebab kartu 5 kolom punya slot dua kali lebar kartu 8 kolom.
const maksKar = computed(() => Math.max(6, Math.floor((bw.value - 4) / KAR)));

/**
 * Penggal satu kata yang lebih panjang dari satu baris.
 *
 * Kata yang didominasi angka (nomor rekening) dipotong dari DEPAN: pembedanya ada di
 * ekor — tiga rekening BCA hanya bisa dibedakan lewat digit terakhirnya. Kata biasa
 * dipotong dari belakang seperti lazimnya.
 */
function potong(kata: string, maks: number): string {
    if (kata.length <= maks) return kata;
    const digit = (kata.match(/[0-9]/g) ?? []).length / kata.length;
    return digit > 0.5 ? '…' + kata.slice(-(maks - 1)) : kata.slice(0, maks - 1) + '…';
}

/** Penggal nama kategori jadi paling banyak `maksBaris` baris selebar `maks` karakter. */
function bagi(teks: string, maks: number, maksBaris: number): string[] {
    const sisa = String(teks ?? '').split(/\s+/).filter(Boolean);
    const baris: string[] = [];
    while (sisa.length && baris.length < maksBaris) {
        const terakhir = baris.length === maksBaris - 1;
        let kini = sisa.shift() as string;
        while (sisa.length && (kini + ' ' + sisa[0]).length <= maks) kini += ' ' + (sisa.shift() as string);
        if (terakhir && sisa.length) kini += ' ' + sisa.join(' ');   // sisanya dipaksa masuk lalu dipotong
        baris.push(potong(kini, maks));
    }
    return baris.length ? baris : [''];
}

const label = computed(() => props.rows.map((r) => bagi(r.n, maksKar.value, props.barisLabel)));

// Ruang di BAWAH bidang gambar untuk label nilai kolom bernilai negatif — hanya disediakan
// bila memang ada saldo negatif, supaya kartu tanpa minus tidak kelebihan ruang kosong.
const adaMinus = computed(() => nilai.value.some((v) => v < 0));
const PB = computed(() => (adaMinus.value ? 14 : 4));
// Talang label selalu setinggi `barisLabel` baris penuh, walau semua nama kebetulan muat
// lebih sedikit. Kartu 1 dan 2 bersebelahan dalam satu baris kisi: bila talangnya mengikuti
// isi, "Profiling Customer" yang terpenggal dua baris membuat garis dasar kartu 1 naik 11,5
// satuan terhadap kartu 2 dan kedua grafik berhenti sejajar.
const talang = computed(() => props.barisLabel * LH + (props.nomor ? LH : 0) + 6);
const H = computed(() => PT + props.plot + PB.value + talang.value);

// Rentang sumbu selalu MENYERTAKAN nol: tanpa itu kolom negatif tidak punya garis dasar
// dan tinggi kolom berhenti membawa arti.
const atas = computed(() => Math.max(0, ...nilai.value));
const bawah = computed(() => Math.min(0, ...nilai.value));
const rentang = computed(() => (atas.value - bawah.value) || 1);
const y0 = computed(() => PT + props.plot * (atas.value / rentang.value));

// Label di atas kolom dibiarkan sependek mungkin — satuannya ("berkas") sudah tertulis
// di kalimat pengantar kartu dan diulang di tooltip, jadi mengulangnya delapan kali di
// dalam grafik hanya menambah tinta tanpa menambah keterangan.
function teksNilai(v: number): string {
    return props.hitung ? v.toLocaleString('id-ID') : rpRingkas(v);
}

/** Nilai versi lengkap untuk tooltip: satuan ditulis, rupiah tidak disingkat. */
function teksPenuh(v: number): string {
    return props.hitung
        ? v.toLocaleString('id-ID') + (props.satuan ? ' ' + props.satuan : '')
        : rp(v);
}

/** Kolom dengan ujung membulat di sisi yang tumbuh dan siku di sisi garis dasar. */
function jalur(x: number, w: number, dasar: number, ujung: number): string {
    const naik = ujung <= dasar;
    const r = Math.min(R, Math.abs(dasar - ujung) / 2, w / 2);
    const s = naik ? 1 : -1;                        // arah dari ujung kembali ke dasar
    const u = ujung + s * r;                        // titik mulai lengkung
    const n2 = (v: number) => v.toFixed(1);
    return [
        'M' + n2(x) + ',' + n2(dasar),
        'L' + n2(x) + ',' + n2(u),
        'Q' + n2(x) + ',' + n2(ujung) + ' ' + n2(x + r) + ',' + n2(ujung),
        'L' + n2(x + w - r) + ',' + n2(ujung),
        'Q' + n2(x + w) + ',' + n2(ujung) + ' ' + n2(x + w) + ',' + n2(u),
        'L' + n2(x + w) + ',' + n2(dasar),
        'Z',
    ].join(' ');
}

const kolom = computed(() => props.rows.map((r, i) => {
    const v = angka(r.v);
    const minus = v < 0;
    const cx = PX + bw.value * i + bw.value / 2;
    const yv = PT + props.plot * ((atas.value - v) / rentang.value);
    // Tinggi minimum 2 satuan supaya saldo yang sangat kecil dibanding rekening terbesar
    // tetap terlihat sebagai kolom, bukan hilang sama sekali.
    const tinggi = Math.max(2, Math.abs(yv - y0.value));
    const ujung = minus ? y0.value + tinggi : y0.value - tinggi;
    return {
        key: i + '-' + r.n,
        cx,
        d: jalur(cx - tebal.value / 2, tebal.value, y0.value, ujung),
        // Saldo negatif memakai warna kritis, bukan corak ramp: itu keadaan yang perlu
        // menonjol, dan warnanya selalu berpasangan dengan angka bertanda minus di
        // sebelahnya sehingga tidak pernah warna saja yang membawa arti.
        fill: minus
            ? 'var(--viz-crit)'
            : (props.warna
                ? props.warna[i % props.warna.length]
                : (props.flat ?? (props.ramp ?? SEQ)[Math.min(5, 1 + i)])),
        teks: teksNilai(v),
        // Label nilai: di ATAS ujung untuk kolom positif, di BAWAH ujung untuk yang negatif.
        ty: minus ? ujung + 11 : ujung - 5,
        minus,
        no: i + 1,
        baris: label.value[i],
        judul: r.n + ': ' + teksPenuh(v) + (minus ? ' (posisi kredit)' : ''),
    };
}));

const yLabel = computed(() => PT + props.plot + PB.value + 10);
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <svg v-else :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
            <!-- satu garis dasar saja; nilainya sudah tercetak di tiap kolom sehingga garis
                 kisi hanya akan mengulang keterangan yang sama -->
            <line :x1="PX" :x2="W - PX" :y1="y0" :y2="y0" class="ax" />
            <g v-for="b in kolom" :key="b.key">
                <path data-mark :d="b.d" :fill="b.fill"><title>{{ b.judul }}</title></path>
                <text :x="b.cx.toFixed(1)" :y="b.ty.toFixed(1)" text-anchor="middle" class="vlab"
                      :fill="b.minus ? 'var(--viz-crit)' : undefined">{{ b.teks }}</text>
                <text v-if="nomor" :x="b.cx.toFixed(1)" :y="yLabel" text-anchor="middle" class="no">{{ b.no }}</text>
                <text v-for="(t, j) in b.baris" :key="j" :x="b.cx.toFixed(1)"
                      :y="yLabel + (nomor ? LH : 0) + j * LH" text-anchor="middle" class="dlab">{{ t }}</text>
            </g>
        </svg>
    </div>
</template>
