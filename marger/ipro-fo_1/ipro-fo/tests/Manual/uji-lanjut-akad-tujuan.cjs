// Tombol "Lanjut & Akad" pada kolom ACTION (BankLoan/Show.vue) harus berhenti DI TOMBOL aksi
// Akad yang ada di tengah bawah, bukan di puncak halaman.
//
// Keluhan asalnya: untuk baris KPR yang SEDANG DIBUKA, tombolnya memanggil window.location.href
// ke halaman yang sama persis. Halamannya memuat ulang penuh lalu mendarat KEMBALI DI PUNCAK —
// justru menjauh dari tombol tujuan, padahal labelnya sudah bertanda "↓".
//
// Yang dijaga:
//  1. Baris KPR yang sedang dibuka TIDAK berpindah halaman (tujuanAkad → null).
//  2. Baris KPR lain berpindah, dan URL-nya MEMBAWA tanda #aksi-akad — tanpa itu halaman baru
//     terbuka di puncak dan penggunanya harus mencari sendiri tombol yang tadi diklik.
//  3. Penyorotan mengenai TOMBOL, bukan panel, dan memakai focus({preventScroll:true}) — focus()
//     bawaan melompat seketika dan membatalkan gulir halus yang baru saja dimulai.
//  4. Tombol tujuannya TIDAK selalu "Simpan Akad": begitu akad tersimpan, panel yang sama
//     berganti menjadi "Lanjut ke Pencairan". Keduanya membawa id aksi-akad dan saling
//     meniadakan — di layar sungguhan (di-render SSR) hanya boleh ada SATU pada satu saat.
//
// Jalankan:  node tests/Manual/uji-lanjut-akad-tujuan.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2] || '.');
const MODUL = path.join(ROOT, 'resources/js/Composables/useAksiAkad.ts');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BankLoan/Show.vue');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

/** DOM tiruan seadanya — cukup untuk merekam apa yang benar-benar dipanggil. */
function bikinDom(idYangAda) {
    const jejak = { gulir: [], fokus: [], kelas: [], timeout: [] };
    const buat = (id) => ({
        id,
        classList: {
            add: (...c) => jejak.kelas.push(['add', id, ...c]),
            remove: (...c) => jejak.kelas.push(['remove', id, ...c]),
        },
        scrollIntoView: (opt) => jejak.gulir.push([id, opt]),
        focus: (opt) => jejak.fokus.push([id, opt]),
    });
    const simpul = Object.fromEntries(idYangAda.map((id) => [id, buat(id)]));

    return {
        jejak,
        doc: { getElementById: (id) => simpul[id] ?? null },
        win: { setTimeout: (fn, ms) => { jejak.timeout.push(ms); fn(); } },
    };
}

async function main() {
    const b = await esbuild.build({ entryPoints: [MODUL], bundle: true, write: false, format: 'cjs', platform: 'node' });
    const m = { exports: {} };
    new Function('module', 'exports', 'require', b.outputFiles[0].text)(m, m.exports, require);
    const { TANDA_AKAD, tujuanAkad, sorotAksiAkad, KELAS_SOROT } = m.exports;

    const url = (id) => '/kpr/' + id;

    console.log('\n=== 1. Baris KPR yang sedang dibuka: jangan muat ulang halaman ===');

    cek('baris yang sedang dibuka tidak berpindah halaman', tujuanAkad(7, 7, url) === null,
        String(tujuanAkad(7, 7, url)) + ' — memuat ulang halaman yang sama justru mendarat di puncak');

    console.log('\n=== 2. Baris KPR lain: berpindah, dan membawa tanda tujuan ===');

    const lain = tujuanAkad(9, 7, url);
    cek('baris KPR lain berpindah halaman', lain !== null);
    cek('URL-nya menuju KPR yang diklik, bukan yang sedang dibuka',
        typeof lain === 'string' && lain.startsWith('/kpr/9'), String(lain));
    cek('URL membawa tanda #aksi-akad', typeof lain === 'string' && lain.endsWith(TANDA_AKAD),
        String(lain) + ' — halaman tujuan akan terbuka di puncak');
    cek('tandanya memang #aksi-akad', TANDA_AKAD === '#aksi-akad', String(TANDA_AKAD));
    // id sebagai teks vs angka: kolom ACTION mengirim s.id dari JSON, dan pembandingan yang
    // longgar akan menganggap KPR lain sebagai KPR yang sedang dibuka lalu tidak pindah ke mana pun.
    cek('id bertipe beda tetap dianggap KPR lain', tujuanAkad('7', 7, url) !== null,
        'perbandingannya longgar — KPR lain akan dikira KPR yang sedang dibuka');

    console.log('\n=== 3. Yang disorot tombolnya, dengan cara yang tidak membatalkan guliran ===');

    const p = bikinDom(['aksi-akad', 'next-step-form']);
    cek('mengenai tombol, bukan panel', sorotAksiAkad(p.doc, p.win) === 'tombol');
    cek('yang digulir tombol aksi Akad', p.jejak.gulir.length === 1 && p.jejak.gulir[0][0] === 'aksi-akad',
        JSON.stringify(p.jejak.gulir));
    cek('gulirnya halus dan berhenti di tengah layar',
        p.jejak.gulir[0][1]?.behavior === 'smooth' && p.jejak.gulir[0][1]?.block === 'center',
        JSON.stringify(p.jejak.gulir[0][1]));
    cek('tombolnya difokuskan', p.jejak.fokus.length === 1 && p.jejak.fokus[0][0] === 'aksi-akad');
    cek('fokusnya TIDAK ikut menggulir (preventScroll)', p.jejak.fokus[0][1]?.preventScroll === true,
        'focus() bawaan melompat seketika dan membatalkan gulir halusnya');
    cek('sorotnya dipasang lalu dilepas lagi',
        p.jejak.kelas.filter((k) => k[0] === 'add').length === 1
        && p.jejak.kelas.filter((k) => k[0] === 'remove').length === 1,
        JSON.stringify(p.jejak.kelas));
    cek('sorot dilepas setelah jeda, bukan seketika', p.jejak.timeout[0] >= 500, String(p.jejak.timeout[0]));
    cek('kelas sorotnya benar-benar dipakai',
        KELAS_SOROT.length > 0 && KELAS_SOROT.every((k) => p.jejak.kelas[0].includes(k)),
        JSON.stringify(p.jejak.kelas[0]));

    console.log('\n=== 4. Saat tombolnya belum ada, jangan diam saja ===');

    const q = bikinDom(['next-step-form']);
    cek('jatuh ke panel langkah berikutnya', sorotAksiAkad(q.doc, q.win) === 'panel');
    cek('panelnya benar-benar digulir', q.jejak.gulir.length === 1 && q.jejak.gulir[0][0] === 'next-step-form');
    cek('tidak ada yang difokuskan/disorot', q.jejak.fokus.length === 0 && q.jejak.kelas.length === 0);

    const r = bikinDom([]);
    cek('tanpa keduanya, tidak meledak', sorotAksiAkad(r.doc, r.win) === 'tidak-ada');
    cek('dan tidak menggulir ke mana-mana', r.jejak.gulir.length === 0);

    console.log('\n=== 5. Halaman benar-benar memakainya ===');

    const src = fs.readFileSync(HALAMAN, 'utf8');
    const tanpaKomentar = src
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');

    cek('Show.vue mengimpor helpernya', /from '@\/Composables\/useAksiAkad'/.test(src));
    cek('goToAkad memakai tujuanAkad', /function goToAkad[\s\S]{0,400}tujuanAkad\(/.test(tanpaKomentar));
    cek('tidak ada lagi navigasi langsung ke route kpr.show tanpa tanda',
        ! /window\.location\.href\s*=\s*route\('kpr\.show'/.test(tanpaKomentar),
        'kembali memuat ulang halaman dan mendarat di puncak');
    cek('tanda dipasang saat halaman dibuka dari baris KPR lain',
        /onMounted\([\s\S]{0,260}TANDA_AKAD[\s\S]{0,200}sorotAksiAkad/.test(tanpaKomentar),
        'datang dari KPR lain akan berhenti di puncak halaman');

    // Kedua tombol memakai id yang sama dan HARUS saling meniadakan; kalau syarat v-if-nya
    // berubah sampai keduanya bisa muncul bersama, getElementById memilih yang pertama —
    // gulirnya berhenti di tombol yang salah tanpa satu pun galat.
    const idAksi = (src.match(/id="aksi-akad"/g) || []).length;
    cek('id aksi-akad dipasang pada dua tombol bawah', idAksi === 2, String(idAksi));
    cek('id-nya ada di tombol Simpan Akad', /id="aksi-akad"[^>]*>Simpan Akad</.test(src)
        || /<Button[^>]*id="aksi-akad"[\s\S]{0,200}>Simpan Akad</.test(src));
    cek('id-nya ada di tombol Lanjut ke Pencairan', /<Button[^>]*id="aksi-akad"[\s\S]{0,200}>Lanjut ke Pencairan/.test(src));

    // Syarat v-if keduanya diambil apa adanya dari berkas, lalu dibuktikan saling meniadakan
    // untuk SEMUA kombinasi status/akad/editAkad — bukan dipercaya begitu saja.
    const kondisi = [...src.matchAll(/<div v-if="([^"]+)" id="next-step-form"/g)].map((x) => x[1]);
    const cAkadTercatat = kondisi.find((k) => k.includes("a.status === 'akad'") && k.includes('a.akad'));
    cek('syarat panel "akad tercatat" ditemukan di berkas', !!cAkadTercatat, JSON.stringify(kondisi));

    const showAkadForm = /const showAkadForm = computed\(\(\) => ([^)]+\)?[^;]*)\);/.exec(src);
    cek('definisi showAkadForm ditemukan', !!showAkadForm);

    let bentrok = 0;
    for (const status of ['sp3k', 'akad', 'disbursed']) {
        for (const akad of [null, { id: 1 }]) {
            for (const editAkad of [false, true]) {
                const nilai = { a: { status, akad }, editAkad: { value: editAkad } };
                const jalankan = (ekspr) => Function('a', 'editAkad', 'return (' + ekspr.replace(/editAkad\.value/g, 'editAkad.value') + ');')(nilai.a, nilai.editAkad);
                const x = jalankan(cAkadTercatat.replace(/editAkad/g, 'editAkad.value').replace(/editAkad\.value\.value/g, 'editAkad.value'));
                const y = jalankan(showAkadForm[1]);
                if (x && y) bentrok++;
            }
        }
    }
    cek('kedua tombol tidak pernah muncul bersamaan', bentrok === 0,
        bentrok + ' kombinasi status/akad/editAkad memunculkan dua tombol ber-id sama');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
