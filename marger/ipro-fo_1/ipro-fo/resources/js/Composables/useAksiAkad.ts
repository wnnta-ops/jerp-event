/**
 * Tombol "Lanjut & Akad" pada kolom ACTION panel Keputusan KPR (BankLoan/Show.vue).
 *
 * Dipisahkan dari komponennya supaya bisa diuji: logikanya ada dua percabangan yang kalau
 * tertukar TIDAK menimbulkan galat apa pun — tombolnya tetap bisa diklik, hanya berhenti di
 * tempat yang salah. Dan itulah keluhan yang membuat berkas ini ada: untuk baris KPR yang
 * SEDANG DIBUKA, tombolnya dulu memuat ulang halaman yang sama persis, sehingga halamannya
 * berkedip lalu mendarat KEMBALI DI PUNCAK — justru menjauh dari tombol tujuan, padahal
 * labelnya sudah bertanda "↓".
 *
 * Semua ketergantungan peramban (document/window) disuntikkan lewat argumen, bukan diambil dari
 * global, supaya bisa diperiksa tanpa jsdom.
 */

/** Tanda pada URL yang berarti "berhenti di tombol aksi Akad, bukan di puncak halaman". */
export const TANDA_AKAD = '#aksi-akad';

/** Kelas sorot sesaat pada tombol tujuan. Ditulis literal agar ikut terpungut Tailwind. */
export const KELAS_SOROT = ['ring-4', 'ring-primary-400', 'ring-offset-2'];

/** Berapa lama sorotnya bertahan (ms). */
export const LAMA_SOROT = 1600;

/**
 * Ke mana "Lanjut & Akad" mengarah.
 *
 * @returns null bila tetap di halaman ini (cukup digulir), atau URL tujuan bila KPR yang dibuka
 *          memang harus berganti. URL tujuan SELALU membawa TANDA_AKAD — tanpa itu halaman baru
 *          terbuka di puncak dan penggunanya harus mencari sendiri tombol yang tadi diklik.
 */
export function tujuanAkad(idBaris: number, idSekarang: number, urlKpr: (id: number) => string): string | null {
    return idBaris === idSekarang ? null : urlKpr(idBaris) + TANDA_AKAD;
}

type Sasaran = { id: string } & Record<string, any>;
type Dok = { getElementById(id: string): Sasaran | null };
type Win = { setTimeout(fn: () => void, ms: number): any };

/**
 * Gulirkan ke tombol aksi Akad lalu sorot sebentar.
 *
 * Tombolnya TIDAK selalu "Simpan Akad": begitu akad tersimpan, panel yang sama menampilkan
 * "Lanjut ke Pencairan". Keduanya memakai id aksi-akad dan saling meniadakan lewat v-if, jadi
 * yang tersorot selalu tombol yang benar-benar ada di bawah pada saat itu. Bila tahapnya belum
 * sampai Akad, tombol itu tidak ada sama sekali — yang digulirkan panel langkah berikutnya,
 * bukan diam saja tanpa memindahkan apa pun.
 *
 * @returns apa yang akhirnya dituju, untuk diperiksa.
 */
export function sorotAksiAkad(doc: Dok, win: Win): 'tombol' | 'panel' | 'tidak-ada' {
    const tombol = doc.getElementById('aksi-akad');
    const sasaran = tombol ?? doc.getElementById('next-step-form');
    if (! sasaran) return 'tidak-ada';

    sasaran.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    if (! tombol) return 'panel';

    // preventScroll WAJIB: focus() bawaan melompat seketika ke elemennya dan membatalkan gulir
    // halus yang baru saja dimulai, sehingga perpindahannya terasa menyentak.
    tombol.focus?.({ preventScroll: true });
    tombol.classList?.add(...KELAS_SOROT);
    win.setTimeout(() => tombol.classList?.remove(...KELAS_SOROT), LAMA_SOROT);

    return 'tombol';
}
