// Jalankan setup() Advances/Index.vue yang SEBENARNYA dan periksa penggolongan
// Refund / Reimburse / Match dari settlement.
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { effectScope } = require('vue');

globalThis.route = (n) => '/uji/' + n;

const ROOT = process.argv[2];
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Index.vue');

const vuePlugin = {
    name: 'vue',
    setup(build) {
        build.onResolve({ filter: /^@inertiajs\/vue3$/ }, () => ({ path: 'inertia', namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({
            contents: [
                'export const router = { get() {}, post() {} };',
                'export const Link = { render: () => null };',
                'export const Head = { render: () => null };',
                "import { reactive } from 'vue';",
                'export function useForm(a) { return reactive({ ...a, processing: false, post() {} }); }',
            ].join('\n'),
            loader: 'js',
        }));
        build.onLoad({ filter: /\.vue$/ }, (args) => {
            const { descriptor } = sfc.parse(fs.readFileSync(args.path, 'utf8'), { filename: args.path });
            const id = 'x' + Buffer.from(args.path).toString('hex').slice(0, 8);
            return {
                contents: sfc.compileScript(descriptor, { id, inlineTemplate: false }).content,
                loader: 'ts', resolveDir: path.dirname(args.path),
            };
        });
    },
};

const kosong = { rows: [], count: 0, total: 0 };
const PROPS = {
    advances: { data: [], total: 0 },
    filters: {}, summary: { outstanding: 0, count: 0 },
    buckets: { realisasi: kosong, refund: kosong, reimburse: kosong },
    canEditDocDate: true,
};

(async () => {
    const bundle = await esbuild.build({
        stdin: { contents: 'import C from ' + JSON.stringify(BERKAS) + '; export default C;', resolveDir: ROOT, loader: 'ts' },
        bundle: true, write: false, format: 'cjs', platform: 'node',
        plugins: [vuePlugin], external: ['vue'],
        alias: { '@': path.join(ROOT, 'resources/js') },
        resolveExtensions: ['.ts', '.vue', '.js', '.mjs', '.json'],
    });
    const mod = { exports: {} };
    new Function('module', 'exports', 'require', bundle.outputFiles[0].text)(mod, mod.exports, require);
    const Comp = mod.exports.default || mod.exports;

    const scope = effectScope();
    let b;
    scope.run(() => { b = Comp.setup(PROPS, { attrs: {}, slots: {}, emit: () => {}, expose: () => {} }); });

    let lolos = 0, gagal = 0;
    const ok = (c, n) => { c ? lolos++ : gagal++; console.log((c ? '  OK    ' : '  GAGAL ') + n); };
    const p = b.penyelesaian;

    console.log('== Tiga ketentuan ==');
    const refund = p({ status: 'realized', amount: 100000, realized_amount: 60000, settlement: 40000 });
    ok(refund?.key === 'refund' && refund.label === 'Refund', '1. realisasi lebih kecil → Refund: ' + refund?.label);
    ok(refund.ket.includes('dikembalikan ke kas'), '   keterangannya: ' + refund.ket);

    const reim = p({ status: 'realized', amount: 100000, realized_amount: 130000, settlement: -30000 });
    ok(reim?.key === 'reimburse' && reim.label === 'Reimburse', '2. realisasi lebih besar → Reimburse: ' + reim?.label);
    ok(reim.ket.includes('diganti dari kas'), '   keterangannya: ' + reim.ket);

    const match = p({ status: 'realized', amount: 100000, realized_amount: 100000, settlement: 0 });
    ok(match?.key === 'match' && match.label === 'Match', '3. nilainya sama → Match: ' + match?.label);
    ok(match.ket.includes('sama nilainya'), '   keterangannya: ' + match.ket);

    console.log('\n== Hanya untuk yang SUDAH direalisasi ==');
    ok(p({ status: 'requested', settlement: 0 }) === null, 'status Diajukan → tanpa penanda');
    ok(p({ status: 'outstanding', settlement: 5000 }) === null, 'status Outstanding → tanpa penanda walau ada selisih');
    ok(p(null) === null, 'baris kosong → tanpa penanda, bukan galat');

    console.log('\n== Selisih pecahan tidak salah golong ==');
    ok(p({ status: 'realized', settlement: 0.5 })?.key === 'refund', 'settlement 0,5 → Refund');
    ok(p({ status: 'realized', settlement: -0.5 })?.key === 'reimburse', 'settlement -0,5 → Reimburse');
    ok(p({ status: 'realized', settlement: '0' })?.key === 'match', 'settlement berupa teks "0" → Match');
    ok(p({ status: 'realized' })?.key === 'match', 'settlement tidak dikirim → dianggap 0 (Match)');

    console.log('\n== Sejalan dengan tab Refund/Reimburse ==');
    // Tab memakai settlement > 0 dan < 0; penanda baris harus memakai batas yang sama.
    for (const s of [1, 1000, 999999]) ok(p({ status: 'realized', settlement: s })?.key === 'refund', '  settlement ' + s + ' → refund');
    for (const s of [-1, -1000]) ok(p({ status: 'realized', settlement: s })?.key === 'reimburse', '  settlement ' + s + ' → reimburse');

    scope.stop();
    console.log('\n' + lolos + ' lolos, ' + gagal + ' gagal');
    process.exit(gagal ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
