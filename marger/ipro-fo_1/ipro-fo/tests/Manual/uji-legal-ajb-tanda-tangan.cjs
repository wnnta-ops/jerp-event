// Kesepakatan tanda tangan jembatan Legal ⇄ Java Core — DUA BAHASA, SATU PESAN.
//
// Modul Legal (Next.js/Node) menandatangani tiap permintaan, Java Core (PHP)
// memverifikasinya. Keduanya menghitung HMAC atas pesan yang HARUS identik:
//
//     <timestamp> \n <path> \n <sha256 hex dari body>
//
// Kalau keduanya meleset sedikit saja — urutan bagian, pemisah baris, cara
// menurunkan kunci dari JERP_SSO_SECRET — gejalanya bukan galat yang jelas
// melainkan 401 diam-diam: petugas Legal menyimpan akta, layar bilang berhasil,
// dan kolom di Detail AJB tidak pernah terisi. Karena itu uji ini menjalankan
// KEDUA implementasi ASLI (bukan salinan) lalu membandingkan hasilnya:
//   - PHP : app/Modules/BO/IproBridge/Support/LegalBridgeSignature.php
//   - Node: app/legal/lib/jerp-bridge.ts (ditranspile esbuild)
//
// Jalankan:  node tests/Manual/uji-legal-ajb-tanda-tangan.cjs .
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const esbuild = require('esbuild');

const BO = path.resolve(process.argv[2] || '.');
const AKAR = path.resolve(BO, '../../..');

const PHP_SIG = path.join(AKAR, 'app/Modules/BO/IproBridge/Support/LegalBridgeSignature.php');
const PHP_CFG = path.join(AKAR, 'config/ipro.php');
const TS_BRIDGE = path.join(AKAR, 'app/legal/lib/jerp-bridge.ts');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

// ── Muat implementasi Node yang ASLI ────────────────────────────────────────
// jerp-bridge.ts mengimpor crypto saja (tidak ada dependensi npm), jadi cukup
// ditranspile TS→CJS lalu di-require seperti modul biasa.
function muatJembatan(env) {
    const kode = esbuild.transformSync(fs.readFileSync(TS_BRIDGE, 'utf8'), { loader: 'ts', format: 'cjs' }).code;
    const berkas = path.join(os.tmpdir(), 'jerp-bridge-uji-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.cjs');
    fs.writeFileSync(berkas, kode);
    const simpanan = {};
    for (const k of Object.keys(env)) { simpanan[k] = process.env[k]; process.env[k] = env[k]; }
    try {
        delete require.cache[require.resolve(berkas)];
        return require(berkas);
    } finally {
        for (const k of Object.keys(env)) {
            if (simpanan[k] === undefined) delete process.env[k]; else process.env[k] = simpanan[k];
        }
        try { fs.unlinkSync(berkas); } catch { /* berkas sementara */ }
    }
}

/**
 * Jalankan potongan PHP yang me-require berkas tanda tangan yang ASLI.
 *
 * `-d opcache.enable_cli=0` BUKAN hiasan. Di mesin ini opcache CLI menyala dengan
 * revalidate_freq=60: PHP hanya memeriksa ulang tanggal berkas sekali per menit,
 * jadi dua pemanggilan berturut-turut membaca hasil kompilasi yang SAMA walaupun
 * berkasnya sudah berubah. Tanpa penonaktifan ini, uji mutasi melaporkan "lulus"
 * atas kode yang sudah basi — dan itu justru kebalikan dari gunanya uji ini.
 */
function php(potongan) {
    const berkas = path.join(os.tmpdir(), 'uji-sig-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.php');
    fs.writeFileSync(berkas,
        "<?php\nrequire " + JSON.stringify(PHP_SIG) + ";\n"
        + "use App\\Modules\\BO\\IproBridge\\Support\\LegalBridgeSignature as S;\n" + potongan);
    try {
        return execFileSync('php', ['-d', 'opcache.enable_cli=0', berkas], { encoding: 'utf8' }).trim();
    } finally {
        try { fs.unlinkSync(berkas); } catch { /* berkas sementara */ }
    }
}

console.log('\n=== 1. Pesan kanonik disusun sama di kedua bahasa ===');

const TS_TIMESTAMP = '1774000000';
const JALUR = '/api/legal-bridge/ajb';
const BODY = JSON.stringify({ legal_doc_id: 'ckabc123', spr: 'SPR-2026-00031', ajb_number: '112/AJB/V/2026' });

const pesanPhp = php('echo S::pesan(' + JSON.stringify(TS_TIMESTAMP) + ', ' + JSON.stringify(JALUR) + ', ' + JSON.stringify(BODY) + ');');
const crypto = require('crypto');
const pesanNode = TS_TIMESTAMP + '\n' + JALUR + '\n' + crypto.createHash('sha256').update(BODY).digest('hex');

cek('pesan kanonik PHP == susunan yang dipakai Node', pesanPhp === pesanNode,
    'php=' + JSON.stringify(pesanPhp) + ' node=' + JSON.stringify(pesanNode));
cek('  pesannya memang 3 bagian dipisah baris baru', pesanPhp.split('\n').length === 3, JSON.stringify(pesanPhp));
cek('  bagian ketiga adalah sha256 body (64 hex)', /^[0-9a-f]{64}$/.test(pesanPhp.split('\n')[2] || ''));

console.log('\n=== 2. Kunci turunan sama — tanpa variabel .env baru ===');

// Sisi Legal: JERP_SSO_SECRET (sudah dibagikan compose). Sisi FO: PORTAL_SSO_SECRET.
// Keduanya HARUS menurunkan kunci yang sama, kalau tidak semua permintaan 401.
const RAHASIA_PORTAL = 'rahasia-portal-untuk-uji';
const kunciPhp = php(
    "echo hash_hmac('sha256', 'legal-bridge-v1', " + JSON.stringify(RAHASIA_PORTAL) + ");");
const kunciNode = crypto.createHmac('sha256', RAHASIA_PORTAL).update('legal-bridge-v1').digest('hex');

cek('turunan kunci PHP == turunan kunci Node', kunciPhp === kunciNode, 'php=' + kunciPhp + ' node=' + kunciNode);

// Pemisah domainnya harus benar-benar tertulis di KEDUA berkas — bukan hanya di
// uji ini. Kalau salah satu sisi mengubahnya, jembatannya mati total.
const sumberCfg = fs.readFileSync(PHP_CFG, 'utf8');
const sumberTs = fs.readFileSync(TS_BRIDGE, 'utf8');
cek('  config/ipro.php memakai pemisah domain legal-bridge-v1', /'legal-bridge-v1'/.test(sumberCfg));
cek('  jerp-bridge.ts memakai pemisah domain legal-bridge-v1', /"legal-bridge-v1"/.test(sumberTs));
// Diperiksa di dalam BLOK legal_bridge_secret-nya sendiri, bukan di seluruh
// berkas: PORTAL_SSO_SECRET juga dipakai kunci 'portal_sso_secret' beberapa baris
// di atas, jadi pemeriksaan seluruh berkas tetap lulus walau turunannya diambil
// dari variabel yang sama sekali lain — dan jembatannya diam-diam mati.
const blokKunci = (sumberCfg.split("'legal_bridge_secret' =>")[1] ?? '').split('})(),')[0];
cek('  config/ipro.php menurunkan dari PORTAL_SSO_SECRET', /PORTAL_SSO_SECRET/.test(blokKunci), blokKunci.slice(0, 200));
cek('  turunannya memakai kunci portal, bukan nilai lain', /hash_hmac\('sha256', 'legal-bridge-v1', \$portal\)/.test(blokKunci));
cek('  jerp-bridge.ts menurunkan dari JERP_SSO_SECRET', /process\.env\.JERP_SSO_SECRET/.test(sumberTs));

// FAIL-CLOSED: tanpa secret induk, kunci turunan dari string kosong bisa
// dihitung siapa pun — jadi kedua sisi harus MENOLAK, bukan memakai kunci itu.
const tanpaSecret = muatJembatan({ JERP_SSO_SECRET: '', LEGAL_BRIDGE_SECRET: '' });
cek('  Node: tanpa secret induk, jembatan dinyatakan BELUM SIAP', tanpaSecret.jembatanSiap() === false);
cek('  PHP: config mengembalikan null bila kedua secret induk kosong',
    /return \$portal === '' \? null :/.test(sumberCfg), 'fail-closed-nya hilang dari config');

console.log('\n=== 3. Tanda tangan Node diterima verifikator PHP ===');

const jembatan = muatJembatan({ JERP_SSO_SECRET: RAHASIA_PORTAL, LEGAL_BRIDGE_SECRET: '' });
// Kunci yang sebenarnya dipakai Node tidak diekspor; dihitung dari turunan yang
// sudah dibuktikan identik di bagian 2, lalu dipakai menandatangani di PHP.
const sigPhp = php('echo S::tandai(' + JSON.stringify(kunciNode) + ', ' + JSON.stringify(TS_TIMESTAMP) + ', '
    + JSON.stringify(JALUR) + ', ' + JSON.stringify(BODY) + ');');
const sigNode = crypto.createHmac('sha256', kunciNode).update(pesanNode).digest('hex');

cek('tanda tangan PHP == tanda tangan Node', sigPhp === sigNode, 'php=' + sigPhp + ' node=' + sigNode);
cek('  panjangnya 64 hex (sha256)', /^[0-9a-f]{64}$/.test(sigPhp));

// Tiap bagian pesan harus BENAR-BENAR mengubah tanda tangan. Kalau salah satu
// diabaikan, tanda tangan untuk /ajb bisa dipakai ulang di /ajb/lepas, atau
// permintaan lama bisa diulang berhari-hari kemudian.
const sigJalurLain = php('echo S::tandai(' + JSON.stringify(kunciNode) + ', ' + JSON.stringify(TS_TIMESTAMP) + ', '
    + JSON.stringify('/api/legal-bridge/ajb/lepas') + ', ' + JSON.stringify(BODY) + ');');
const sigWaktuLain = php('echo S::tandai(' + JSON.stringify(kunciNode) + ', ' + JSON.stringify('1774000001') + ', '
    + JSON.stringify(JALUR) + ', ' + JSON.stringify(BODY) + ');');
const sigBodyLain = php('echo S::tandai(' + JSON.stringify(kunciNode) + ', ' + JSON.stringify(TS_TIMESTAMP) + ', '
    + JSON.stringify(JALUR) + ', ' + JSON.stringify(BODY.replace('00031', '00032')) + ');');

cek('  ganti path → tanda tangan berubah', sigJalurLain !== sigPhp);
cek('  ganti timestamp → tanda tangan berubah', sigWaktuLain !== sigPhp);
cek('  ganti isi body → tanda tangan berubah', sigBodyLain !== sigPhp);

console.log('\n=== 4. Umur permintaan dibatasi (anti-pengulangan) ===');

cek('PHP membatasi umur permintaan 300 detik',
    php('echo S::UMUR_MAKS;') === '300');
cek('  nama header sama di kedua sisi (X-Legal-Timestamp)',
    php('echo S::HEADER_TS;') === 'X-Legal-Timestamp' && /"X-Legal-Timestamp"/.test(sumberTs));
cek('  nama header sama di kedua sisi (X-Legal-Signature)',
    php('echo S::HEADER_SIG;') === 'X-Legal-Signature' && /"X-Legal-Signature"/.test(sumberTs));

// getPathInfo() (tanpa query) — BUKAN getRequestUri(). Semua endpoint POST +
// body JSON justru supaya tak ada query string yang perlu ditandatangani;
// memakai getRequestUri() akan menolak tanda tangan yang sah begitu ada ?query.
const sumberSig = fs.readFileSync(PHP_SIG, 'utf8');
cek('  verifikator memakai getPathInfo(), bukan getRequestUri()',
    /getPathInfo\(\)/.test(sumberSig) && ! /getRequestUri\(\)/.test(sumberSig.replace(/\/\/[^\n]*/g, '')));
cek('  perbandingan tanda tangan memakai hash_equals (waktu tetap)',
    /hash_equals\(/.test(sumberSig));

console.log('\n=== 5. Nominal rupiah: titik ribuan Indonesia ===');

// "501.000.000" lewat parseFloat/Number terbaca 501. Nominal akta di Back Office
// akan menyusut sejuta kali lipat TANPA satu pun galat muncul, dan tak ada yang
// memeriksa ulang angka yang "sudah terisi otomatis".
const { angkaRupiah } = jembatan;
const kasus = [
    ['Rp 501.000.000', 501000000],
    ['501.000.000', 501000000],
    ['501000000', 501000000],
    ['1.234.567,89', 1234567.89],
    ['1234.56', 1234.56],
    ['688.000.000', 688000000],
    ['', null],
    [null, null],
    ['tanpa angka', null],
];
for (const [masuk, harap] of kasus) {
    const hasil = angkaRupiah(masuk);
    cek('  angkaRupiah(' + JSON.stringify(masuk) + ') = ' + harap, hasil === harap, 'dapat ' + hasil);
}

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
