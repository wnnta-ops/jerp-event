/**
 * COGS Baru — format impor/ekspor daftar unit pada Rincian COGS.
 *
 * ── Mengapa impornya mengisi DRAF, bukan basis data ──
 * Tabel Rincian COGS adalah draf yang disimpan sekaligus lewat satu tombol, dan simpanan itu
 * MENGGANTI seluruh daftar. Impor yang menulis langsung ke basis data akan tertimpa begitu
 * operator menekan "Simpan Rincian COGS" dengan draf lama di layarnya — dan tidak ada galat apa
 * pun yang memberi tahu. Dengan mengisi draf, jalur simpannya tetap satu, dan seluruh penjaga
 * yang sudah ada di sana (kode kembar, unit belum booked tidak boleh mengunci costing) tetap
 * berlaku tanpa ditulis dua kali.
 *
 * ── Angka teks: koma desimal, TANPA pemisah ribuan ──
 * Berlaku untuk teks yang DITEMPEL. Ekspor & template kini berkas Excel dengan sel angka
 * sungguhan (keLembar), dan sel angka dari berkas Excel diterjemahkan dulu (dariLembar).
 * Untuk teks, satu titik yang berdiri sendiri benar-benar ambigu: "1.500" bisa berarti seribu lima ratus (pemisah ribuan) atau satu koma lima
 * (desimal), dan menebaknya salah menggeser luas unit seribu kali lipat tanpa terlihat. Yang
 * dibaca tetap longgar — "1.234,56", "1234.56", "1234,56" semuanya diterima — tetapi bentuk yang
 * benar-benar ambigu DITOLAK dengan keterangan, bukan ditebak.
 *
 * ── Pemisah kolom dideteksi, bukan dipatok ──
 * Excel berbahasa Indonesia menulis CSV dengan titik koma, Excel lain dengan koma, dan menyalin
 * langsung dari lembar kerja menghasilkan TAB. Ketiganya diterima supaya operator bisa menempel
 * apa adanya dari Excel.
 */

export type BarisUnit = {
    no_spr: string | null;
    nama_customer: string | null;
    unit_rumah: string;
    lokasi: string | null;
    tahap: string | null;
    tipe: string | null;
    lt: number;
    lb: number;
    status: 'belum' | 'sudah';
    costing: 'belum' | 'standard' | 'realisasi';
    booked_revenue: number | null;
};

export type Galat = { baris: number; pesan: string };

/** Urutan kolom — dipakai ekspor, template, dan pembacaan judul. */
export const KOLOM: Array<{ kunci: keyof BarisUnit; judul: string }> = [
    { kunci: 'no_spr', judul: 'No. SPR' },
    { kunci: 'nama_customer', judul: 'Nama Customer' },
    { kunci: 'unit_rumah', judul: 'Unit Rumah' },
    { kunci: 'lokasi', judul: 'Lokasi' },
    { kunci: 'tahap', judul: 'Tahap' },
    { kunci: 'tipe', judul: 'Tipe' },
    { kunci: 'lt', judul: 'LT' },
    { kunci: 'lb', judul: 'LB' },
    { kunci: 'status', judul: 'Status' },
    { kunci: 'costing', judul: 'Pilihan Costing' },
    { kunci: 'booked_revenue', judul: 'Booked Revenue' },
];

export const STATUS: Record<string, string> = { belum: 'Belum Booked', sudah: 'Sudah Booked' };
export const COSTING: Record<string, string> = {
    belum: 'Standard Costing / Realisasi Costing',
    standard: 'Standard Costing',
    realisasi: 'Realisasi Costing',
};

/** Samakan bentuk teks sebelum dibandingkan: tanpa spasi berlebih, tanpa beda huruf besar-kecil. */
const normal = (v: unknown): string => String(v ?? '').replace(/\s+/g, ' ').trim().toLowerCase();

/**
 * Teks → angka. Memulangkan `null` bila kosong, dan melempar bila BENAR-BENAR ambigu.
 *
 * Aturannya:
 *   • ada koma DAN titik → yang terakhir muncul adalah pemisah desimal
 *   • hanya koma         → pemisah desimal
 *   • hanya titik, diikuti TEPAT tiga angka, dan tidak ada tanda lain → AMBIGU, ditolak
 *   • selain itu titik   → pemisah desimal
 */
export function bacaAngka(teks: string): number | null {
    const s = String(teks ?? '').trim().replace(/\s/g, '');
    if (s === '') return null;

    const adaKoma = s.includes(',');
    const adaTitik = s.includes('.');
    let bersih = s;

    if (adaKoma && adaTitik) {
        const desimal = s.lastIndexOf(',') > s.lastIndexOf('.') ? ',' : '.';
        const ribuan = desimal === ',' ? '.' : ',';
        bersih = s.split(ribuan).join('').replace(desimal, '.');
    } else if (adaKoma) {
        bersih = s.replace(/,/g, '.');
    } else if (adaTitik) {
        // "1.500" — seribu lima ratus, atau satu koma lima? Menebaknya salah menggeser luas
        // unit seribu kali lipat tanpa terlihat, jadi tidak ditebak.
        if (/^-?\d{1,3}\.\d{3}$/.test(s)) {
            throw new Error(`"${s}" ambigu — tulis 1234,56 (koma desimal) atau 1234 (tanpa pemisah ribuan)`);
        }
    }

    const n = Number(bersih);
    if (Number.isNaN(n)) throw new Error(`"${s}" bukan angka`);

    return n;
}

/** Satu sel CSV: dikutip hanya bila perlu, kutip ganda digandakan. */
function sel(v: unknown): string {
    const s = v === null || v === undefined ? '' : String(v);

    return /[";\n\r,\t]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/** BOM UTF-8, ditulis sebagai KODE: karakter mentahnya tak terlihat di editor dan mudah terhapus tanpa sengaja. */
const BOM = String.fromCharCode(0xFEFF);

/**
 * Daftar unit → lembar Excel {judul, isi}; berkasnya dirakit server (bs.cogs2.rincian-xlsx.tulis).
 *
 * LT, LB & Booked Revenue dikirim sebagai ANGKA sungguhan, bukan teks berformat: di Excel sel
 * angka tidak punya persoalan koma-atau-titik sama sekali, dan bisa langsung dijumlahkan.
 */
export function keLembar(baris: Array<Partial<BarisUnit>>): { judul: string[]; isi: Array<Array<string | number>> } {
    const isi = baris.map((b) => KOLOM.map((k): string | number => {
        if (k.kunci === 'lt' || k.kunci === 'lb' || k.kunci === 'booked_revenue') {
            const v = b[k.kunci];

            return v === null || v === undefined || Number.isNaN(Number(v)) ? '' : Number(v);
        }
        if (k.kunci === 'status') return STATUS[String(b.status ?? 'belum')] ?? STATUS.belum;
        if (k.kunci === 'costing') return COSTING[String(b.costing ?? 'belum')] ?? COSTING.belum;

        return String(b[k.kunci] ?? '');
    }));

    return { judul: KOLOM.map((k) => k.judul), isi };
}

/**
 * Baris mentah dari berkas Excel → teks bertab, lalu dibaca dariCsv() seperti tempelan biasa.
 *
 * Sel angka di Excel tersimpan dalam bentuk mesin ("87.5", "1.125"). Titik di sana SELALU
 * desimal, jadi diubah ke koma lebih dulu — kalau tidak, "1.125" dianggap ambigu dan ditolak
 * oleh bacaAngka padahal Excel sudah menyimpannya tanpa keraguan.
 */
export function dariLembar(baris: string[][]): string {
    return baris.map((b) => b.map((s) => {
        const t = String(s ?? '');

        return sel(/^-?\d+\.\d+$/.test(t) ? t.replace('.', ',') : t);
    }).join('\t')).join('\n');
}

/** Template: judul kolom + dua baris contoh yang memperlihatkan bentuk angkanya. */
export function templateLembar(): { judul: string[]; isi: Array<Array<string | number>> } {
    return keLembar([
        {
            no_spr: 'SPR-001', nama_customer: 'NAMA PELANGGAN', unit_rumah: 'JRK3-001-X-9',
            lokasi: 'JRK3', tahap: '001 & 002', tipe: 'CICA',
            lt: 75, lb: 36, status: 'belum', costing: 'belum', booked_revenue: null,
        },
        {
            no_spr: '', nama_customer: '', unit_rumah: 'JRK3-001-X-10',
            lokasi: 'JRK3', tahap: '001 & 002', tipe: 'CICA HOOK',
            lt: 87.5, lb: 39, status: 'sudah', costing: 'standard', booked_revenue: 650000000,
        },
    ]);
}

/** Pecah satu baris CSV menurut pemisahnya, menghormati kutip ganda. */
function pecah(baris: string, pemisah: string): string[] {
    const out: string[] = [];
    let kini = '';
    let dalamKutip = false;
    for (let i = 0; i < baris.length; i++) {
        const c = baris[i];
        if (dalamKutip) {
            if (c === '"') {
                if (baris[i + 1] === '"') { kini += '"'; i++; } else { dalamKutip = false; }
            } else { kini += c; }
        } else if (c === '"') { dalamKutip = true; }
        else if (c === pemisah) { out.push(kini); kini = ''; }
        else { kini += c; }
    }
    out.push(kini);

    return out.map((v) => v.trim());
}

/** Pemisah dipilih dari yang PALING BANYAK muncul di baris judul — bukan dipatok. */
function pemisahDari(judul: string): string {
    const hitung = (c: string) => (judul.match(new RegExp('\\' + c, 'g')) ?? []).length;
    const kandidat: Array<[string, number]> = [[';', hitung(';')], ['\t', hitung('\t')], [',', hitung(',')]];
    kandidat.sort((a, b) => b[1] - a[1]);

    return kandidat[0][1] > 0 ? kandidat[0][0] : ';';
}

/**
 * CSV / tempelan dari Excel → daftar unit.
 *
 * Seluruh baris tetap dibaca walau ada yang bergalat, dan galatnya dikumpulkan beserta nomor
 * barisnya. Berhenti di galat pertama membuat operator memperbaiki satu per satu dan mengunggah
 * ulang berkali-kali; yang dibutuhkan adalah daftar lengkap apa saja yang salah.
 */
export function dariCsv(teks: string): { baris: BarisUnit[]; galat: Galat[] } {
    const galat: Galat[] = [];
    const bersih = String(teks ?? '').replace(new RegExp('^' + BOM), '').replace(/\r\n?/g, '\n').trim();
    if (bersih === '') return { baris: [], galat: [{ baris: 0, pesan: 'Isinya kosong.' }] };

    const garis = bersih.split('\n').filter((g) => g.trim() !== '');
    const pemisah = pemisahDari(garis[0]);
    const judul = pecah(garis[0], pemisah).map(normal);

    // Peta judul → indeks kolom. Judul yang tidak dikenali diabaikan; yang WAJIB dan tidak ada
    // dilaporkan, sebab tanpa Unit Rumah seluruh barisnya tidak berarti apa-apa.
    const idx: Partial<Record<keyof BarisUnit, number>> = {};
    for (const k of KOLOM) {
        const p = judul.indexOf(normal(k.judul));
        if (p >= 0) idx[k.kunci] = p;
    }
    if (idx.unit_rumah === undefined) {
        galat.push({ baris: 1, pesan: 'Kolom "Unit Rumah" tidak ditemukan di baris judul.' });

        return { baris: [], galat };
    }

    const ambil = (sel: string[], k: keyof BarisUnit): string =>
        idx[k] === undefined ? '' : String(sel[idx[k] as number] ?? '').trim();

    const out: BarisUnit[] = [];
    const sudahAda = new Set<string>();

    garis.slice(1).forEach((g, i) => {
        const nomor = i + 2; // baris 1 = judul
        const s = pecah(g, pemisah);

        const kode = ambil(s, 'unit_rumah');
        if (kode === '') {
            galat.push({ baris: nomor, pesan: 'Unit Rumah kosong.' });

            return;
        }
        if (sudahAda.has(kode.toLowerCase())) {
            galat.push({ baris: nomor, pesan: `Unit Rumah "${kode}" kembar di dalam berkas ini.` });

            return;
        }
        sudahAda.add(kode.toLowerCase());

        const angka = (k: keyof BarisUnit, nama: string, wajib: boolean): number | null => {
            try {
                const v = bacaAngka(ambil(s, k));
                if (v === null) { if (wajib) galat.push({ baris: nomor, pesan: `${nama} kosong.` }); return wajib ? 0 : null; }
                if (v < 0) { galat.push({ baris: nomor, pesan: `${nama} tidak boleh negatif.` }); return wajib ? 0 : null; }

                return v;
            } catch (e: any) {
                galat.push({ baris: nomor, pesan: `${nama}: ${e.message}` });

                return wajib ? 0 : null;
            }
        };

        const cocok = (peta: Record<string, string>, teksSel: string, bawaan: string): string | null => {
            if (teksSel === '') return bawaan;
            const n = normal(teksSel);
            // Dicocokkan PERSIS, bukan sebagian: label "Standard Costing / Realisasi Costing"
            // memuat kedua label lain di dalamnya, jadi pencocokan sebagian akan memilih yang salah.
            for (const [kunci, label] of Object.entries(peta)) {
                if (n === normal(kunci) || n === normal(label)) return kunci;
            }

            return null;
        };

        const st = cocok(STATUS, ambil(s, 'status'), 'belum');
        if (st === null) {
            galat.push({ baris: nomor, pesan: `Status "${ambil(s, 'status')}" tidak dikenal — isi "Belum Booked" atau "Sudah Booked".` });
        }
        const cs = cocok(COSTING, ambil(s, 'costing'), 'belum');
        if (cs === null) {
            galat.push({ baris: nomor, pesan: `Pilihan Costing "${ambil(s, 'costing')}" tidak dikenal.` });
        }
        // Aturan yang sama dengan penjaga di server: selama belum booked, kedua pilihan costing
        // masih terbuka. Menguncinya di sini akan mengeluarkan unit itu dari pembebanan realisasi
        // padahal ia belum terjual.
        if (st === 'belum' && cs !== null && cs !== 'belum') {
            galat.push({ baris: nomor, pesan: `"${kode}" belum booked, jadi Pilihan Costing-nya harus tetap terbuka.` });
        }

        const lt = angka('lt', 'LT', true);
        const lb = angka('lb', 'LB', true);
        const rev = angka('booked_revenue', 'Booked Revenue', false);

        out.push({
            no_spr: ambil(s, 'no_spr') || null,
            nama_customer: ambil(s, 'nama_customer') || null,
            unit_rumah: kode,
            lokasi: ambil(s, 'lokasi') || null,
            tahap: ambil(s, 'tahap') || null,
            tipe: ambil(s, 'tipe') || null,
            lt: lt ?? 0,
            lb: lb ?? 0,
            status: (st ?? 'belum') as BarisUnit['status'],
            costing: (cs ?? 'belum') as BarisUnit['costing'],
            booked_revenue: rev,
        });
    });

    if (out.length === 0 && galat.length === 0) {
        galat.push({ baris: 1, pesan: 'Tidak ada baris data di bawah judul.' });
    }

    return { baris: out, galat };
}
