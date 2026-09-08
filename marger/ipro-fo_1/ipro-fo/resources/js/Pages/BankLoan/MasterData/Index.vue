<script setup lang="ts">
import { ref, computed } from 'vue';
import { FileSpreadsheet, FilterX, Download } from 'lucide-vue-next';

const props = defineProps<{ rows: Array<any>; retensiCols: Array<string>; prosesCols: Array<string> }>();

const q = ref('');

/* ══ Definisi kolom sebagai DATA ══
   Header, baris filter, dan sel tabel semuanya dibangun dari struktur ini — satu sumber
   kebenaran, sehingga jumlah/urutan kolom tak mungkin melenceng antar bagian.
   item = 1 kolom tunggal, atau 2 sub-kolom berpasangan (mis. Tanggal + Nominal). */
type Col = { label: string; sub?: string; align?: 'r'; mono?: boolean; num?: boolean; get: (r: any) => any };
type Item = { label: string; cols: Col[] };
type Group = { label: string; cls: string; items: Item[] };

const one = (label: string, get: (r: any) => any, o: Partial<Col> = {}): Item => ({ label, cols: [{ label, get, ...o }] });
/** Item berpasangan Tanggal + Nominal (pola {t, n}). */
const pairTN = (label: string, get: (r: any) => any): Item => ({
    label,
    cols: [
        { label: `${label} — Tanggal`, sub: 'Tanggal', get: (r) => get(r)?.t ?? null },
        { label: `${label} — Nominal`, sub: 'Nominal', get: (r) => get(r)?.n ?? null, align: 'r', mono: true, num: true },
    ],
});
/** Item berpasangan Tanggal + Summary (pola {t, s}) — dipakai grup Proses KPR. */
const pairTS = (label: string, get: (r: any) => any): Item => ({
    label,
    cols: [
        { label: `${label} — Tanggal`, sub: 'Tanggal', get: (r) => get(r)?.t ?? null },
        { label: `${label} — Summary`, sub: 'Summary', get: (r) => get(r)?.s ?? null },
    ],
});

/* RINCIAN BIAYA GIMMICK — dipetakan operator lewat Setup Kategori Gimmick (satu kategori →
   satu kolom, dengan sakelar sinkron). */
const biayaKeys = [
    { key: 'kpr', label: 'BIAYA KPR' }, { key: 'ht', label: 'BIAYA HT' }, { key: 'ppjb', label: 'BIAYA PPJB' },
    { key: 'ssp', label: 'BIAYA SSP' }, { key: 'bphtb', label: 'BIAYA BPHTB' }, { key: 'ajb', label: 'BIAYA AJB' },
];

/* RINCIAN BIAYA PENJUALAN — TIDAK lewat Setup Kategori Gimmick. Keempatnya datang langsung dari
   realisasinya: BLT per jenjang dari Realisasi BLT, dan Komisi dari Realisasi Komisi. Jenjang
   BLT sudah ditentukan pada dokumen BLT itu sendiri, jadi menyediakan pemetaan kategori lagi
   hanya membuka peluang dua sumber kebenaran yang berbeda untuk satu hal yang sama. */
const biayaPenjualanKeys = [
    { key: 'blt_sales', label: 'BLT SALES' }, { key: 'blt_koord', label: 'BLT KOORDINATOR' },
    { key: 'blt_kantor', label: 'BLT KANTOR' }, { key: 'komisi', label: 'KOMISI' },
];
const utjDpLabels = ['UTJ', 'DP 1', 'DP 2', 'DP 3', 'DP 4', 'DP 5', 'DP 6'];

const groups = computed<Group[]>(() => [
    {
        label: 'Master Unit', cls: 'bg-emerald-50 dark:bg-emerald-900/30',
        items: [
            one('No. SPR', (r) => r.no_spr, { mono: true }),
            one('Kode Unit', (r) => r.kode_unit, { mono: true }),
            one('Nama Customer', (r) => r.customer),
            one('Tipe Rumah', (r) => r.tipe),
            one('Pricelist Gross', (r) => r.pricelist_gross, { align: 'r', mono: true, num: true }),
            one('Diskon', (r) => r.diskon, { align: 'r', mono: true, num: true }),
            one('Free DP', (r) => r.free_dp, { align: 'r', mono: true, num: true }),
            one('Pricelist Nett', (r) => r.pricelist_nett, { align: 'r', mono: true, num: true }),
            one('Revenue', (r) => r.revenue, { align: 'r', mono: true, num: true }),
            one('Agent / Sales', (r) => r.agent_sales),
            one('Cara Bayar', (r) => r.cara_bayar),
        ],
    },
    {
        label: 'Data KPR', cls: 'bg-sky-50 dark:bg-sky-900/30',
        items: [
            one('Tanggal BAST', (r) => r.tgl_bast),
            one('Tanggal AJB', (r) => r.tgl_ajb),
            one('Cabang Bank', (r) => r.bank),
            one('Tanggal Akad', (r) => r.tgl_akad),
            one('Pengajuan KPR', (r) => r.pengajuan_kpr, { align: 'r', mono: true, num: true }),
            one('ACC KPR', (r) => r.acc_kpr, { align: 'r', mono: true, num: true }),
            one('Selisih', (r) => r.selisih, { align: 'r', mono: true, num: true }),
        ],
    },
    {
        // 12 tahap Proses KPR — tiap tahap: Tanggal + Summary. Label dari server (prosesCols).
        label: 'Proses KPR', cls: 'bg-indigo-50 dark:bg-indigo-900/30',
        items: props.prosesCols.map((label, i) => pairTS(label, (r) => r.proses?.[i])),
    },
    {
        label: 'Collection UTJ & DP', cls: 'bg-amber-50 dark:bg-amber-900/30',
        items: utjDpLabels.map((label, i) => pairTN(label, (r) => (i === 0 ? r.utj : r.dp?.[i - 1]))),
    },
    {
        label: 'Collection Retensi', cls: 'bg-rose-50 dark:bg-rose-900/30',
        items: props.retensiCols.map((label, i) => pairTN(label, (r) => r.retensi?.[i])),
    },
    {
        label: 'Rincian Biaya Gimmick', cls: 'bg-violet-50 dark:bg-violet-900/30',
        items: biayaKeys.map((b) => pairTN(b.label, (r) => r.biaya?.[b.key])),
    },
    {
        // Warnanya sengaja BEDA dari Rincian Biaya Gimmick: keduanya sama-sama "rincian biaya",
        // dan satu-satunya penanda bahwa asalnya berlainan adalah judul kelompoknya. Pada tabel
        // selebar ini judul kelompok mudah tergulir keluar layar, sehingga warna itulah yang
        // tersisa untuk membedakannya.
        label: 'Rincian Biaya Penjualan', cls: 'bg-teal-50 dark:bg-teal-900/30',
        items: biayaPenjualanKeys.map((b) => pairTN(b.label, (r) => r.biaya?.[b.key])),
    },
]);

/** Semua kolom (datar, urut) — dipakai baris filter & sel tbody. */
const flatCols = computed<Col[]>(() => groups.value.flatMap((g) => g.items.flatMap((i) => i.cols)));

// ── Filter per kolom (client-side, "berisi", tak peka huruf besar/kecil) ──
const colF = ref<Record<number, string>>({});
const activeFilters = computed(() => Object.entries(colF.value).filter(([, v]) => String(v ?? '').trim() !== ''));
function clearFilters() { colF.value = {}; q.value = ''; }

const raw = (c: Col, r: any) => { const v = c.get(r); return v === null || v === undefined ? '' : String(v); };

const filtered = computed(() => {
    const s = q.value.trim().toLowerCase();
    const fs = activeFilters.value.map(([i, v]) => [flatCols.value[Number(i)], String(v).trim().toLowerCase()] as const);
    return props.rows.filter((r) => {
        // Pencarian global — mencakup SEMUA kolom (termasuk kolom proses), bukan hanya sebagian.
        if (s && ! flatCols.value.some((c) => raw(c, r).toLowerCase().includes(s))) return false;
        // Filter per kolom — semua filter aktif harus cocok (AND).
        return fs.every(([c, v]) => c && raw(c, r).toLowerCase().includes(v));
    });
});

const fmt = (c: Col, r: any) => {
    const v = c.get(r);
    if (v === null || v === undefined || v === '') return '—';
    return c.num ? Number(v).toLocaleString('id-ID') : String(v);
};
const tdCls = (c: Col) => [
    'border border-neutral-200 px-2 py-1 dark:border-neutral-700',
    c.align === 'r' ? 'text-right' : '',
    c.mono ? 'font-mono' : '',
];

// ── Unduh Excel ──────────────────────────────────────────────────────────
// Susunan kolom dikirim dari sini (satu sumber kebenaran ada di `groups` di atas), jadi isi
// berkasnya tak mungkin melenceng dari tabel di layar — menambah kolom otomatis ikut terunduh.
// Yang diunduh SELURUH baris Master Data KPR, bukan hanya yang lolos filter layar.
const downloading = ref(false);
function unduhExcel() {
    if (downloading.value) return;
    downloading.value = true;

    const cols = flatCols.value;
    const payload = {
        sheet: 'Master Data KPR',
        groups: groups.value.map((g) => ({ label: g.label, span: g.items.reduce((a, i) => a + i.cols.length, 0) })),
        // Kolom "No" berdiri sendiri di depan seluruh grup.
        headers: ['No', ...cols.map((c) => c.label)],
        numeric: [false, ...cols.map((c) => !!c.num)],
        rows: props.rows.map((r, i) => [i + 1, ...cols.map((c) => {
            const v = c.get(r);
            return v === null || v === undefined ? '' : v;
        })]),
    };

    // POST natif lewat <form> tersembunyi: unduhan file tidak bisa lewat Inertia (butuh
    // navigasi browser agar dialog "Save as" muncul).
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('kpr.master-data.export');
    form.style.display = 'none';
    const add = (name: string, value: string) => {
        const el = document.createElement('input');
        el.type = 'hidden'; el.name = name; el.value = value;
        form.appendChild(el);
    };
    add('_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '');
    // SATU field JSON, bukan satu input per sel: PHP membatasi max_input_vars (default 1000)
    // dan akan MEMOTONG kiriman tanpa error — tabel ini punya puluhan ribu sel.
    add('payload', JSON.stringify(payload));

    document.body.appendChild(form);
    form.submit();
    // Halaman tidak berpindah saat mengunduh, jadi status tombol dipulihkan sendiri.
    window.setTimeout(() => { form.remove(); downloading.value = false; }, 2500);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileSpreadsheet class="h-6 w-6 text-primary-600" /> Master Data KPR</h1>
            <button v-if="activeFilters.length || q" type="button" @click="clearFilters"
                class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">
                <FilterX class="h-3.5 w-3.5" /> Bersihkan filter ({{ activeFilters.length + (q ? 1 : 0) }})
            </button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <input v-model="q" type="text" placeholder="Cari semua kolom…"
                    class="h-9 w-72 max-w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
                <button type="button" @click="unduhExcel" :disabled="downloading || !rows.length"
                    :title="`Unduh seluruh ${rows.length} baris Master Data KPR ke Excel (filter layar tidak memengaruhi isi berkas)`"
                    class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-emerald-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                    <Download class="h-4 w-4" :class="downloading ? 'animate-bounce' : ''" />
                    {{ downloading ? 'Menyiapkan…' : 'Unduh Excel' }}
                    <span class="rounded bg-white/20 px-1.5 py-0.5 text-[10.5px] font-bold tabular-nums">{{ rows.length }}</span>
                </button>
            </div>
        </div>
        <p class="text-sm text-neutral-500">
            Rekap per-SPR: Master Unit, Data KPR, <strong>Proses KPR</strong> (tanggal &amp; summary tiap tahap), Collection UTJ &amp; DP, Collection Retensi, Rincian Biaya Gimmick, dan Rincian Biaya Penjualan (BLT &amp; Komisi, langsung dari realisasinya).
            Ketik pada kotak di bawah judul kolom untuk memfilter kolom tsb — filter antar kolom bersifat AND.
            <span class="text-neutral-400">{{ filtered.length }} dari {{ rows.length }} baris.</span>
        </p>

        <div class="card overflow-x-auto">
            <table class="min-w-max text-xs">
                <thead>
                    <!-- Baris 1: grup -->
                    <tr class="bg-neutral-100 text-[11px] font-bold uppercase text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        <th rowspan="4" class="sticky left-0 z-10 border border-neutral-200 bg-neutral-100 px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-800">No</th>
                        <th v-for="g in groups" :key="'g-' + g.label" :colspan="g.items.reduce((a, i) => a + i.cols.length, 0)"
                            :class="['border border-neutral-200 px-2 py-1.5 dark:border-neutral-700', g.cls]">{{ g.label }}</th>
                    </tr>
                    <!-- Baris 2: item (tunggal → rowspan 2, berpasangan → colspan 2) -->
                    <tr class="bg-neutral-50 text-[10px] font-semibold uppercase text-neutral-500 dark:bg-neutral-800/60">
                        <template v-for="g in groups" :key="'gi-' + g.label">
                            <th v-for="it in g.items" :key="'i-' + g.label + '-' + it.label"
                                :rowspan="it.cols.length === 1 ? 2 : 1" :colspan="it.cols.length"
                                :title="it.label" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ it.label }}</th>
                        </template>
                    </tr>
                    <!-- Baris 3: sub-header (Tanggal / Nominal / Summary) -->
                    <tr class="bg-neutral-50 text-[10px] font-medium uppercase text-neutral-400 dark:bg-neutral-800/40">
                        <template v-for="g in groups" :key="'gs-' + g.label">
                            <template v-for="it in g.items" :key="'s-' + g.label + '-' + it.label">
                                <th v-for="c in (it.cols.length > 1 ? it.cols : [])" :key="'sc-' + c.label"
                                    :class="['border border-neutral-200 px-2 py-1 dark:border-neutral-700', c.align === 'r' ? 'text-right' : '']">{{ c.sub }}</th>
                            </template>
                        </template>
                    </tr>
                    <!-- Baris 4: filter per kolom -->
                    <tr class="bg-white dark:bg-neutral-900">
                        <th v-for="(c, i) in flatCols" :key="'f-' + i" class="border border-neutral-200 p-0.5 dark:border-neutral-700">
                            <input v-model="colF[i]" type="text" :placeholder="'Filter'" :title="'Filter ' + c.label"
                                :class="['h-6 w-full min-w-[68px] rounded border px-1 text-[10px] font-normal normal-case',
                                         String(colF[i] ?? '').trim() ? 'border-primary-400 bg-primary-50 dark:bg-primary-900/30' : 'border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-800',
                                         c.align === 'r' ? 'text-right' : '']" />
                        </th>
                    </tr>
                </thead>
                <tbody class="text-neutral-700 dark:text-neutral-200">
                    <tr v-for="r in filtered" :key="r.no" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                        <td class="sticky left-0 z-10 border border-neutral-200 bg-white px-2 py-1 text-center dark:border-neutral-700 dark:bg-neutral-900">{{ r.no }}</td>
                        <td v-for="(c, i) in flatCols" :key="'c-' + r.no + '-' + i" :class="tdCls(c)" :title="c.sub === 'Summary' ? (c.get(r) ?? '') : undefined">
                            <span :class="c.sub === 'Summary' ? 'block max-w-[220px] truncate' : ''">{{ fmt(c, r) }}</span>
                        </td>
                    </tr>
                    <tr v-if="! filtered.length">
                        <!-- +1 = kolom "No" (sticky, di luar flatCols) -->
                        <td :colspan="flatCols.length + 1" class="px-4 py-8 text-center text-sm text-neutral-500">
                            {{ (q || activeFilters.length) ? 'Tidak ada baris yang cocok dengan filter.' : 'Belum ada data KPR.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
