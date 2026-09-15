// Collect Berkas (Proses KPR): "Simpan & Lanjut" aktif bila setiap berkas WAJIB sudah DITANDAI —
// Lengkap maupun Tidak Lengkap — bukan hanya bila semuanya Lengkap.
//
// Sebelumnya satu berkas yang memang belum ada menahan seluruh tahap: berkasnya ditandai Tidak
// Lengkap, tombolnya tetap berbunyi "Simpan" saja, dan prosesnya berhenti di situ. Padahal
// "tidak lengkap" adalah keadaan yang sah dan justru perlu tercatat — tiap penandaan menyimpan
// status_at, dan itulah jejak waktu yang dipakai menelusuri keterlambatan berkas.
//
// TIGA HAL YANG DIJAGA:
//
//  1. LAYAR DAN SERVER HARUS BERUBAH BERSAMA. Aturannya ada di dua tempat. Kalau hanya layar yang
//     dilonggarkan, tombolnya berbunyi "Simpan & Lanjut" sementara servernya diam-diam menolak
//     memajukan tahap — pengguna menekan tombol, layar berkata tersimpan, dan tahapnya tidak
//     bergerak tanpa satu pun galat.
//  2. YANG TIDAK BOLEH IKUT LONGGAR: tiap berkas Tidak Lengkap tetap wajib berketerangan, dan
//     Catatan Umum tetap wajib. Keduanya yang membuat "tidak lengkap" bisa dipertanggungjawabkan.
//  3. TIDAK BOLEH JADI LEBIH SULIT. Berkas opsional ("Apabila Ada") tetap boleh dibiarkan kosong;
//     mewajibkannya ditandai akan membuat tombolnya lebih sulit aktif daripada sebelum perubahan.
//
// Berlaku untuk Karyawan MAUPUN Wirausaha — keduanya diuji memakai daftar berkas yang sebenarnya.
//
// Jalankan:  node tests/Manual/uji-collect-berkas-ditandai.cjs .
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(process.argv[2] || '.');
const V = path.join(ROOT, 'resources/js/Pages/BankLoan/Initiation/Show.vue');
const C = path.join(ROOT, 'app/Http/Controllers/Web/BankLoan/KprInitiationController.php');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const src = fs.readFileSync(V, 'utf8');
const ctl = fs.readFileSync(C, 'utf8');
const bersih = (s) => s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const kode = bersih(src);
const kodeCtl = bersih(ctl);

/** Daftar berkas per kategori, dibaca APA ADANYA dari berkasCategories di layar. */
function kategoriBerkas() {
    const i = kode.indexOf('const berkasCategories');
    if (i < 0) return null;
    // Dicari dari '= {', BUKAN dari '{' pertama: deklarasinya beranotasi tipe
    // (Record<string, { label: string; items: BerkasItem[] }>), dan kurung pada anotasi itu
    // yang terambil lebih dulu — hasilnya objek tipe, bukan daftar berkasnya.
    const eq = kode.indexOf('= {', i);
    if (eq < 0) return null;
    // Ambil sampai penutup objeknya — cukup dengan menghitung kurung kurawal.
    let j = kode.indexOf('{', eq), dalam = 0, akhir = j;
    for (; akhir < kode.length; akhir++) {
        if (kode[akhir] === '{') dalam++;
        else if (kode[akhir] === '}') { dalam--; if (dalam === 0) break; }
    }
    const teks = kode.slice(j, akhir + 1);
    try {
        // eslint-disable-next-line no-new-func
        return new Function('return (' + teks.replace(/\bas const\b/g, '') + ');')();
    } catch (e) {
        return null;
    }
}

console.log('\n=== 1. Aturan layar: ditandai, bukan harus Lengkap ===');

const m = /const semuaBerkasDitandai = computed\(\(\) => \{([\s\S]*?)\n\}\);/.exec(kode);
cek('aturan semuaBerkasDitandai ditemukan', !! m, 'nama lamanya allBerkasComplete masih dipakai?');
cek('nama lama allBerkasComplete tidak tersisa', ! /allBerkasComplete/.test(kode),
    'ada pemakaian yang terlewat — tombol/pesan akan memakai aturan yang berbeda');

const kat = kategoriBerkas();
cek('daftar berkas per kategori terbaca dari layar', !! kat && !! kat.karyawan && !! kat.wirausaha,
    'uji ini harus memakai daftar yang sebenarnya, bukan daftar tiruan');

// Aturannya dijalankan SUNGGUHAN dengan f & berkasCategories tiruan: yang menentukan
// benar-tidaknya adalah hasilnya untuk tiap keadaan, bukan bentuk tulisannya.
const jalankan = new Function('f', 'berkasCategories', m[1].replace(/: Line/g, '') + '\n');
const nilai = (cat, isi) => jalankan({ collect_berkas: { category: cat, items: isi } }, kat);

for (const cat of ['karyawan', 'wirausaha']) {
    const wajib = (kat[cat]?.items ?? []).filter((it) => ! it.optional);
    const opsional = (kat[cat]?.items ?? []).filter((it) => it.optional);
    const isi = (status) => Object.fromEntries(wajib.map((it) => [cat + '__' + it.key, { status }]));

    console.log('  — ' + cat + ' (' + wajib.length + ' wajib, ' + opsional.length + ' opsional) —');

    cek('  semua Lengkap → boleh lanjut', nilai(cat, isi('lengkap')) === true);
    // Inti permintaan ini.
    cek('  semua Tidak Lengkap → TETAP boleh lanjut', nilai(cat, isi('tidak_lengkap')) === true,
        'satu berkas yang memang belum ada masih menahan seluruh tahap');

    const campur = isi('lengkap');
    campur[cat + '__' + wajib[wajib.length - 1].key] = { status: 'tidak_lengkap' };
    cek('  campuran Lengkap & Tidak Lengkap → boleh lanjut', nilai(cat, campur) === true);

    const kosong = isi('lengkap');
    kosong[cat + '__' + wajib[0].key] = { status: '' };
    cek('  satu berkas wajib BELUM ditandai → ditahan', nilai(cat, kosong) === false,
        'berkas yang belum diperiksa lolos, dan status_at-nya tidak pernah tercatat');

    const hilang = isi('lengkap');
    delete hilang[cat + '__' + wajib[0].key];
    cek('  berkas wajib yang tidak ada entrinya → ditahan', nilai(cat, hilang) === false);

    cek('  status asing tidak dianggap penandaan', nilai(cat, isi('entah')) === false,
        'nilai di luar lengkap/tidak_lengkap diterima sebagai tanda');

    if (opsional.length) {
        // TIDAK boleh jadi lebih sulit daripada sebelum perubahan.
        cek('  berkas OPSIONAL boleh dibiarkan kosong', nilai(cat, isi('lengkap')) === true,
            'mewajibkan yang opsional membuat tombolnya lebih sulit aktif daripada sebelumnya');
    }
}

cek('tanpa kategori → ditahan', nilai('', {}) === false);

console.log('\n=== 2. Yang TIDAK ikut dilonggarkan ===');

cek('berkas Tidak Lengkap tetap wajib berketerangan',
    /collectNotesOk = computed\(\(\) => currentItems\.value\.every\([\s\S]{0,220}tidak_lengkap[\s\S]{0,80}note/.test(kode),
    '"tidak lengkap" jadi tidak bisa dipertanggungjawabkan');
cek('Catatan Umum tetap wajib', /const catatanUmumOk = computed\(\(\) => !!\(f\.collect_berkas\.notes \?\? ''\)\.trim\(\)\);/.test(kode));
cek('tombol tetap terkunci sampai keduanya terpenuhi',
    /:disabled="!collectCanSave"/.test(kode) && /collectCanSave = computed\(\(\) => collectNotesOk\.value && catatanUmumOk\.value\)/.test(kode));
cek('penandaan tetap menyimpan waktunya (status_at)',
    /it\.status_at = new Date\(\)\.toISOString\(\);/.test(kode),
    'jejak waktu yang jadi alasan perubahan ini justru hilang');

console.log('\n=== 3. Label & pesan mengikuti aturan baru ===');

cek('tombol berbunyi "Simpan & Lanjut" saat semua sudah ditandai',
    /semuaBerkasDitandai \? 'Simpan & Lanjut →' : 'Simpan'/.test(kode));
cek('pesan siap-lanjut tidak lagi menuntut "Lengkap"',
    ! /Semua berkas wajib sudah Lengkap/.test(src), 'pesannya menjanjikan aturan lama');
cek('pesan penahan menyebut kedua pilihannya',
    /Tandai semua berkas wajib \(Lengkap \/ Tidak Lengkap\)/.test(src),
    'pengguna tidak diberi tahu bahwa Tidak Lengkap pun sah');

console.log('\n=== 4. Server berubah bersama layar ===');

// Kalau hanya layar yang longgar: tombolnya berbunyi "Simpan & Lanjut", servernya diam-diam
// menolak memajukan tahap, dan tidak ada satu pun galat yang memberi tahu.
cek('gerbang server menerima lengkap MAUPUN tidak_lengkap',
    /in_array\(\$items\[\$category\.'__'\.\$k\]\['status'\] \?\? '', \['lengkap', 'tidak_lengkap'\], true\)/.test(kodeCtl),
    'server masih menuntut semuanya Lengkap — tahapnya tidak akan pernah maju');
cek('server tidak lagi membandingkan langsung ke "lengkap" saja',
    ! /\$k\]\['status'\] \?\? ''\) === 'lengkap'/.test(kodeCtl));
cek('server tetap memakai daftar berkas WAJIB-nya sendiri',
    /\$required\s*=\s*self::BERKAS_REQUIRED\[\$category\] \?\? \[\];/.test(kodeCtl));
cek('server tetap menolak bila kategorinya kosong',
    /\$allComplete = \$category !== null && \$required !== \[\]/.test(kodeCtl));
cek('pesan server tidak lagi menuntut "Lengkap"',
    ! /Tandai semua berkas wajib "Lengkap" untuk bisa lanjut/.test(ctl));
cek('status_at tetap dicatat server', /'status_at' => \$statusAt,/.test(kodeCtl));

// Daftar wajib di server dan di layar harus sepadan, kalau tidak keduanya menghitung hal berbeda.
const mReq = /private const BERKAS_REQUIRED = \[([\s\S]*?)\];/.exec(ctl);
cek('BERKAS_REQUIRED server ditemukan', !! mReq);
if (mReq && kat) {
    for (const cat of ['karyawan', 'wirausaha']) {
        const mCat = new RegExp("'" + cat + "'\\s*=>\\s*\\[([^\\]]*)\\]").exec(mReq[1]);
        const srv = mCat ? (mCat[1].match(/'([^']+)'/g) || []).map((x) => x.slice(1, -1)) : [];
        const layar = (kat[cat]?.items ?? []).filter((it) => ! it.optional).map((it) => it.key);
        cek('daftar wajib ' + cat + ' sama antara layar & server',
            srv.length === layar.length && srv.every((k) => layar.includes(k)),
            'server=' + JSON.stringify(srv) + ' layar=' + JSON.stringify(layar));
    }
}

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
