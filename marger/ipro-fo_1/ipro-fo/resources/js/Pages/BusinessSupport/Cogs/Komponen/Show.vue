<!-- COGS → Komponen COGS: Tanah · Housing · Infrastruktur · License Permit -->
<script setup lang="ts">
/**
 * Satu halaman untuk keempat komponen, dua bentuk sumber:
 *   - sumber 'ap'  (Tanah, License Permit)  → invoice hutang pada Kategori Invoice yang DITAUTKAN
 *   - sumber 'spk' (Housing, Infrastruktur) → Surat Perintah Kerja ber-jenis bangunan / sarana_prasarana
 *
 * Angka ringkasan datang dari server dan dihitung atas SELURUH baris yang lolos saringan —
 * bukan dijumlah di sini dari halaman tabel yang sedang tampil, yang hanya memuat 100 baris.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Layers, Link2, Settings2, X, Sparkles, ExternalLink } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    komponen: { slug: string; label: string; cogs: string; sumber: 'ap' | 'spk'; cogsLabel: string };
    daftar: Array<{ slug: string; label: string }>;
    filters: { tahun: number | null; search: string };
    bisaAtur: boolean;
    baris: any;
    ringkas: Record<string, number>;
    kategori?: Array<{ id: number; code: string; name: string; aktif: boolean; tertaut: boolean; milikLain: string | null; disarankan: boolean }>;
    jenisLabel?: string;
    // Sumber KETIGA: mutasi buku besar pada akun ber-tanda komponen ini (Chart of Account →
    // Edit Akun → Komponen COGS). Berdiri sendiri, TIDAK dijumlahkan dengan blok di atas.
    glAkun?: Array<{ code: string; name: string }>;
    glBaris?: Array<any>;
    glRingkas?: { jumlah: number; masuk: number; keluar: number; netto: number; belumEfektif: number; tanpaJejak: number };
    // Sumber KEEMPAT: kontrak SPK (MUK) yang ditautkan lewat tombol "Link COGS",
    // nilainya dibagi proporsional ke unit-unit SPK-nya.
    mukBaris?: Array<any>;
    mukRingkas?: { kontrak: number; nilai: number; unit: number; tanpaDasar: number };
}>();

const dariAp = computed(() => props.komponen.sumber === 'ap');
const glAda = computed(() => (props.glAkun ?? []).length > 0);
const mukAda = computed(() => (props.mukRingkas?.kontrak ?? 0) > 0);
const persen = (v: number | null) => (v === null || v === undefined ? '—' : (Number(v) * 100).toFixed(2) + '%');
const tgl = (v: string | null) => (v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const rp = (v: number) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');

/* ── Saringan ─────────────────────────────────────────────────────────────────────── */
const tahun = ref<number | ''>(props.filters.tahun ?? '');
const cari = ref(props.filters.search ?? '');
function tampilkan() {
    router.get(route('bs.cogs.komponen.show', props.komponen.slug),
        { tahun: tahun.value || undefined, search: cari.value || undefined },
        { preserveState: true, replace: true });
}

/* ── Ringkasan ────────────────────────────────────────────────────────────────────── */
const tertaut = computed(() => (props.kategori ?? []).filter((k) => k.tertaut));
/** Porsi yang sudah terbayar (AP) / sudah dikerjakan (SPK), 0–100. */
const porsi = computed(() => {
    const total = props.ringkas.nilai;
    const jalan = dariAp.value ? props.ringkas.terbayar : props.ringkas.progres;

    return total > 0 ? Math.min(100, Math.max(0, (jalan / total) * 100)) : 0;
});
const tiles = computed(() => (dariAp.value
    ? [
        { label: 'Invoice', nilai: String(props.ringkas.jumlah ?? 0), sub: 'di luar yang dibatalkan' },
        { label: 'Total Nilai', nilai: rp(props.ringkas.nilai), sub: 'nilai invoice (DPP + PPN)' },
        { label: 'Terbayar', nilai: rp(props.ringkas.terbayar), sub: `${porsi.value.toFixed(1)}% dari total` },
        { label: 'Sisa Hutang', nilai: rp(props.ringkas.sisa), sub: 'belum dibayar' },
    ]
    : [
        { label: 'SPK', nilai: String(props.ringkas.jumlah ?? 0), sub: 'di luar yang dibatalkan' },
        { label: 'Nilai SPK', nilai: rp(props.ringkas.nilai), sub: `kontrak ${rp(props.ringkas.kontrak)} + addendum ${rp(props.ringkas.addendum)}` },
        { label: 'Progres', nilai: rp(props.ringkas.progres), sub: `${porsi.value.toFixed(1)}% dari nilai SPK` },
        { label: 'Sisa Kontrak', nilai: rp(props.ringkas.sisa), sub: 'belum dikerjakan' },
    ]));

const statusVarian = (s: string): 'neutral' | 'success' | 'warning' | 'info' => (
    s === 'paid' || s === 'closed' || s === 'done' ? 'success'
        : s === 'partial' ? 'warning'
            : s === 'open' || s === 'active' ? 'info' : 'neutral');
const statusLabel: Record<string, string> = {
    open: 'Belum Lunas', partial: 'Sebagian', paid: 'Lunas', draft: 'Draft',
    active: 'Aktif', closed: 'Selesai', done: 'Selesai',
};

/* ── Atur tautan Kategori Invoice (khusus sumber AP) ──────────────────────────────── */
const modal = ref(false);
const pilihan = ref<number[]>([]);
const cariKat = ref('');
const menyimpan = ref(false);
const galat = ref('');

function bukaAtur() {
    pilihan.value = tertaut.value.map((k) => k.id);
    cariKat.value = '';
    galat.value = '';
    modal.value = true;
}
/** Disarankan naik ke atas, lalu yang sudah dipilih, lalu sisanya menurut kode. */
const kategoriModal = computed(() => {
    const q = cariKat.value.trim().toLowerCase();

    return (props.kategori ?? [])
        .filter((k) => ! q || `${k.code} ${k.name}`.toLowerCase().includes(q))
        .slice()
        .sort((a, b) => Number(b.disarankan) - Number(a.disarankan)
            || Number(pilihan.value.includes(b.id)) - Number(pilihan.value.includes(a.id))
            || a.code.localeCompare(b.code));
});
function toggle(id: number) {
    pilihan.value = pilihan.value.includes(id) ? pilihan.value.filter((x) => x !== id) : [...pilihan.value, id];
}
function simpanTautan() {
    menyimpan.value = true;
    galat.value = '';
    router.put(route('bs.cogs.komponen.tautan', props.komponen.slug), { kategori: pilihan.value }, {
        preserveScroll: true,
        onSuccess: () => { modal.value = false; },
        onError: (e: any) => { galat.value = e.kategori ?? Object.values(e)[0] ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Cost of Good Sold
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <Layers class="h-6 w-6 text-primary-600" /> Komponen COGS · {{ komponen.label }}
                </h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-neutral-500">
                    <Badge variant="primary">{{ komponen.cogsLabel }}</Badge>
                    <span v-if="dariAp">Sumber: <b>Account Payable</b> → Set Up → Kategori Invoice</span>
                    <span v-else>Sumber: <b>Contractor Management</b> → Surat Perintah Kerja, jenis <b>{{ jenisLabel }}</b></span>
                </p>
            </div>
        </div>

        <!-- Pindah antar komponen -->
        <nav class="flex flex-wrap gap-1 rounded-xl border border-neutral-200 bg-white p-1 dark:border-neutral-800 dark:bg-neutral-900" aria-label="Komponen COGS">
            <Link v-for="d in daftar" :key="d.slug" :href="route('bs.cogs.komponen.show', d.slug)"
                  class="rounded-lg px-4 py-2 text-sm font-medium transition"
                  :class="d.slug === komponen.slug
                      ? 'bg-primary-600 text-white shadow-sm'
                      : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                  :aria-current="d.slug === komponen.slug ? 'page' : undefined">
                {{ d.label }}
            </Link>
        </nav>

        <!-- Tautan sumber -->
        <div class="card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                        <Link2 class="h-4 w-4" /> {{ dariAp ? 'Kategori Invoice yang ditautkan' : 'Tautan sumber' }}
                    </p>

                    <template v-if="dariAp">
                        <div v-if="tertaut.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="k in tertaut" :key="k.id"
                                  class="inline-flex items-center gap-1.5 rounded-md border border-primary-200 bg-primary-50 px-2 py-1 text-xs text-primary-800 dark:border-primary-900 dark:bg-primary-950/40 dark:text-primary-200">
                                <b class="font-mono">{{ k.code }}</b><span class="opacity-80">{{ k.name }}</span>
                                <span v-if="! k.aktif" class="rounded bg-neutral-200 px-1 text-[10px] text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">nonaktif</span>
                            </span>
                        </div>
                        <p v-else class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                            Belum ada Kategori Invoice yang ditautkan — komponen ini belum menampilkan biaya apa pun.
                        </p>
                    </template>
                    <p v-else class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                        Setiap Surat Perintah Kerja ber-jenis <b>{{ jenisLabel }}</b> otomatis masuk komponen ini.
                        Jenisnya diatur saat registrasi SPK — tidak perlu ditautkan manual.
                    </p>
                </div>
                <Button v-if="dariAp && bisaAtur" variant="secondary" @click="bukaAtur">
                    <template #icon-left><Settings2 class="h-4 w-4" /></template>Atur Kategori Invoice
                </Button>
            </div>
        </div>

        <!-- Ringkasan -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="t in tiles" :key="t.label" class="card">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t.label }}</p>
                <p class="mt-1 truncate text-xl font-bold tabular-nums" :title="t.nilai">{{ t.nilai }}</p>
                <p class="mt-0.5 truncate text-xs text-neutral-500" :title="t.sub">{{ t.sub }}</p>
            </div>
        </div>
        <div v-if="ringkas.nilai > 0" class="card py-3">
            <div class="mb-1.5 flex justify-between text-xs text-neutral-500">
                <span>{{ dariAp ? 'Terbayar' : 'Progres pekerjaan' }}</span>
                <span class="tabular-nums">{{ porsi.toFixed(1) }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800" role="progressbar"
                 :aria-valuenow="Math.round(porsi)" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-primary-600 transition-all" :style="{ width: porsi + '%' }"></div>
            </div>
        </div>

        <!-- Saringan -->
        <div class="card flex flex-wrap items-end gap-3">
            <div class="w-32">
                <label class="mb-1 block text-xs uppercase text-neutral-500" for="kc-tahun">Tahun</label>
                <input id="kc-tahun" v-model.number="tahun" type="number" placeholder="Semua" :class="inputCls" @keyup.enter="tampilkan" />
            </div>
            <div class="min-w-[220px] flex-1">
                <label class="mb-1 block text-xs uppercase text-neutral-500" for="kc-cari">Cari</label>
                <input id="kc-cari" v-model="cari" type="text" :class="inputCls" @keyup.enter="tampilkan"
                       :placeholder="dariAp ? 'No. invoice, supplier, kategori, keterangan' : 'No. SPK, kontraktor, judul, cluster, unit'" />
            </div>
            <Button @click="tampilkan">Tampilkan</Button>
        </div>

        <!-- Tabel -->
        <div v-if="baris.data?.length" class="card overflow-x-auto p-0">
            <table v-if="dariAp" class="w-full text-sm">
                <thead>
                    <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
                        <th class="px-3 py-2">No. Invoice</th>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2">Supplier</th>
                        <th class="px-3 py-2">Kategori</th>
                        <th class="px-3 py-2">Keterangan</th>
                        <th class="px-3 py-2 text-right">Nilai</th>
                        <th class="px-3 py-2 text-right">Terbayar</th>
                        <th class="px-3 py-2 text-right">Sisa</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in baris.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="whitespace-nowrap px-3 py-2">
                            <Link :href="route('bs.ap.show', r.id)" class="inline-flex items-center gap-1 font-mono text-primary-600 hover:underline">
                                {{ r.nomor }}<ExternalLink class="h-3 w-3 opacity-60" />
                            </Link>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ r.tanggal ?? '—' }}</td>
                        <td class="px-3 py-2">{{ r.supplier ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ r.kategori || '—' }}</td>
                        <td class="max-w-[260px] truncate px-3 py-2" :title="r.keterangan">{{ r.keterangan || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ rp(r.nilai) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ rp(r.terbayar) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ rp(r.sisa) }}</td>
                        <td class="px-3 py-2"><Badge :variant="statusVarian(r.status)" dot>{{ statusLabel[r.status] ?? r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>

            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
                        <th class="px-3 py-2">No. SPK</th>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2">Kontraktor</th>
                        <th class="px-3 py-2">Pekerjaan</th>
                        <th class="px-3 py-2">Cluster · Tahap · Unit</th>
                        <th class="px-3 py-2 text-right">Kontrak</th>
                        <th class="px-3 py-2 text-right">Addendum</th>
                        <th class="px-3 py-2 text-right">Progres</th>
                        <th class="px-3 py-2 text-right">Sisa</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in baris.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="whitespace-nowrap px-3 py-2">
                            <Link :href="route('bs.spk.show', r.id)" class="inline-flex items-center gap-1 font-mono text-primary-600 hover:underline">
                                {{ r.nomor }}<ExternalLink class="h-3 w-3 opacity-60" />
                            </Link>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ r.tanggal ?? '—' }}</td>
                        <td class="px-3 py-2">{{ r.kontraktor ?? '—' }}</td>
                        <td class="max-w-[240px] truncate px-3 py-2" :title="r.judul">{{ r.judul || '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ r.lokasi || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ rp(r.kontrak) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ r.addendum ? rp(r.addendum) : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ rp(r.progres) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ rp(r.sisa) }}</td>
                        <td class="px-3 py-2"><Badge :variant="statusVarian(r.status)" dot>{{ statusLabel[r.status] ?? r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState v-else
                    :title="dariAp && ! tertaut.length ? 'Belum ada Kategori Invoice yang ditautkan' : 'Belum ada biaya pada komponen ini'"
                    :description="dariAp && ! tertaut.length
                        ? (bisaAtur ? 'Klik Atur Kategori Invoice untuk memilih kategori mana yang membentuk komponen ' + komponen.label + '.' : 'Minta pemegang izin Komponen COGS untuk menautkan Kategori Invoice.')
                        : (dariAp ? 'Tidak ada invoice hutang pada kategori yang ditautkan untuk saringan ini.' : 'Tidak ada Surat Perintah Kerja ber-jenis ' + jenisLabel + ' untuk saringan ini.')" />

        <div v-if="baris.links?.length > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in baris.links" :key="l.label" :href="l.url" v-html="l.label"
                       class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>

        <!-- ══════════ Sumber ketiga: mutasi buku besar akun ber-tanda komponen ══════════ -->
        <div v-if="glAda" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-t border-neutral-200 pt-5 dark:border-neutral-800">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <Layers class="h-5 w-5 text-primary-600" /> Mutasi Buku Besar — {{ komponen.label }}
                </h2>
                <div class="text-xs text-neutral-500">
                    Akun bertanda: <span v-for="(a, i) in (glAkun ?? [])" :key="a.code"><span v-if="i"> · </span><b>{{ a.code }}</b> {{ a.name }}</span>
                </div>
            </div>

            <!-- Peringatan ini WAJIB terbaca: satu akun kewajiban di bagan akun ini bisa menjadi
                 akun debit beberapa kategori invoice sekaligus, jadi menjumlahkan blok ini dengan
                 blok di atas akan menghitung uang yang sama dua kali. -->
            <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300">
                Angka di bawah berdiri <b>sendiri</b> dan <b>tidak boleh dijumlahkan</b> dengan biaya dari
                {{ dariAp ? 'Kategori Invoice' : 'Surat Perintah Kerja' }} di atas — satu akun bisa melayani lebih dari satu kategori,
                sehingga penjumlahannya menghitung uang yang sama dua kali.
                Ringkasan hanya menjumlah jurnal <b>Approved</b>, sama seperti Buku Besar &amp; Neraca.
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Mutasi Masuk</div>
                    <div class="mt-1 font-mono text-lg font-bold text-amber-600">{{ rp(glRingkas?.masuk ?? 0) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Mutasi Keluar</div>
                    <div class="mt-1 font-mono text-lg font-bold text-emerald-600">{{ rp(glRingkas?.keluar ?? 0) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Netto</div>
                    <div class="mt-1 font-mono text-lg font-bold">{{ rp(glRingkas?.netto ?? 0) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Baris</div>
                    <div class="mt-1 font-mono text-lg font-bold">{{ glRingkas?.jumlah ?? 0 }}</div>
                    <div v-if="(glRingkas?.belumEfektif ?? 0) > 0" class="text-[11px] text-amber-600">{{ glRingkas?.belumEfektif }} belum Approved</div></div>
            </div>

            <div v-if="(glBaris ?? []).length" class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-2.5">No</th>
                        <th class="px-3 py-2.5">No. SPR</th>
                        <th class="px-3 py-2.5">Nama Customer</th>
                        <th class="px-3 py-2.5">Kode Unit</th>
                        <th class="px-3 py-2.5">Lokasi</th>
                        <th class="px-3 py-2.5">Tahap</th>
                        <th class="px-3 py-2.5">Tanggal</th>
                        <th class="px-3 py-2.5 text-right">Masuk</th>
                        <th class="px-3 py-2.5 text-right">Keluar</th>
                        <th class="px-3 py-2.5">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in (glBaris ?? [])" :key="r.no" :class="r.efektif ? '' : 'opacity-60'">
                            <td class="px-3 py-2 tabular-nums">{{ r.no }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ r.spr ?? '—' }}</td>
                            <td class="px-3 py-2">{{ r.customer ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ r.unit ?? '—' }}</td>
                            <td class="px-3 py-2">{{ r.lokasi ?? '—' }}</td>
                            <td class="px-3 py-2">{{ r.tahap ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2">{{ tgl(r.tanggal) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-amber-600">{{ r.masuk ? rp(r.masuk) : '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-emerald-600">{{ r.keluar ? rp(r.keluar) : '—' }}</td>
                            <td class="px-3 py-2"><Badge :variant="r.efektif ? 'success' : 'warning'" dot>{{ r.status_label }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="Belum ada mutasi buku besar"
                        description="Akun sudah ditandai komponen ini, tetapi belum ada jurnal yang menyentuhnya pada saringan yang dipilih." />

            <p v-if="(glRingkas?.tanpaJejak ?? 0) > 0" class="text-xs text-neutral-500">
                {{ glRingkas?.tanpaJejak }} baris belum bisa ditelusuri ke SPR/unit — barisnya tetap ditampilkan dengan sel kosong,
                sebab membuangnya membuat jumlah layar ini berhenti sama dengan mutasi akunnya.
            </p>
        </div>

        <!-- ══════════ Sumber keempat: kontrak SPK (MUK) yang ditautkan ══════════ -->
        <div v-if="mukAda" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-t border-neutral-200 pt-5 dark:border-neutral-800">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <Link2 class="h-5 w-5 text-primary-600" /> Kontrak SPK (MUK) — {{ komponen.label }}
                </h2>
                <div class="text-xs text-neutral-500">
                    {{ mukRingkas?.kontrak }} kontrak · {{ mukRingkas?.unit }} bagian unit · total {{ rp(mukRingkas?.nilai ?? 0) }}
                </div>
            </div>

            <p class="rounded-lg border border-sky-300 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-700/50 dark:bg-sky-900/20 dark:text-sky-300">
                Nilai tiap kontrak dibagi <b>proporsional</b> ke unit-unit pada Surat Perintah Kerja yang nomornya sama,
                menurut nilai tiap unit. Jumlah seluruh bagian sama <b>persis</b> dengan nilai kontraknya —
                sisa pembulatan dibebankan ke unit terakhir.
                <span v-if="(mukRingkas?.tanpaDasar ?? 0) > 0">
                    <b>{{ mukRingkas?.tanpaDasar }} kontrak</b> belum punya dasar bagi dan ditampilkan utuh dengan keterangannya.
                </span>
            </p>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-2.5">No</th>
                        <th class="px-3 py-2.5">No. Kontrak</th>
                        <th class="px-3 py-2.5">No. SPK</th>
                        <th class="px-3 py-2.5">Proyek</th>
                        <th class="px-3 py-2.5">Kode Unit</th>
                        <th class="px-3 py-2.5 text-right">Porsi</th>
                        <th class="px-3 py-2.5">Tanggal</th>
                        <th class="px-3 py-2.5 text-right">Nominal</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in (mukBaris ?? [])" :key="r.no">
                            <td class="px-3 py-2 tabular-nums">{{ r.no }}</td>
                            <td class="px-3 py-2 font-mono text-xs font-semibold">{{ r.kontrak ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ r.spk ?? '—' }}</td>
                            <td class="px-3 py-2 text-neutral-500">{{ r.proyek || '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ r.unit ?? '—' }}
                                <span v-if="r.alasan" class="ml-1 text-[11px] font-normal text-amber-600">({{ r.alasan }})</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ persen(r.porsi) }}</td>
                            <td class="whitespace-nowrap px-3 py-2">{{ tgl(r.tanggal) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ rp(r.nilai) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal: atur tautan Kategori Invoice -->
        <Teleport to="body">
            <div v-if="modal" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4" @click.self="modal = false">
                <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900" role="dialog" aria-modal="true" aria-labelledby="kc-modal-judul">
                    <div class="flex items-start justify-between gap-3 border-b border-neutral-200 p-5 dark:border-neutral-800">
                        <div>
                            <h2 id="kc-modal-judul" class="text-lg font-bold">Kategori Invoice untuk {{ komponen.label }}</h2>
                            <p class="mt-1 text-sm text-neutral-500">Invoice hutang pada kategori yang dipilih akan dihitung sebagai biaya {{ komponen.cogsLabel }}. Satu kategori hanya bisa dimiliki satu komponen.</p>
                        </div>
                        <button type="button" class="text-neutral-400 hover:text-neutral-700" aria-label="Tutup" @click="modal = false"><X class="h-5 w-5" /></button>
                    </div>

                    <div class="border-b border-neutral-200 p-4 dark:border-neutral-800">
                        <input v-model="cariKat" type="text" placeholder="Cari kode atau nama kategori" :class="inputCls" />
                        <p class="mt-2 text-xs text-neutral-500">{{ pilihan.length }} kategori dipilih</p>
                    </div>

                    <ul class="flex-1 divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
                        <li v-for="k in kategoriModal" :key="k.id">
                            <label class="flex cursor-pointer items-center gap-3 px-5 py-2.5 hover:bg-neutral-50 dark:hover:bg-neutral-800/60"
                                   :class="{ 'cursor-not-allowed opacity-50': k.milikLain }">
                                <input type="checkbox" class="h-4 w-4 rounded" :checked="pilihan.includes(k.id)" :disabled="!! k.milikLain" @change="toggle(k.id)" />
                                <span class="min-w-0 flex-1">
                                    <span class="font-mono text-xs font-semibold">{{ k.code }}</span>
                                    <span class="ml-2 text-sm">{{ k.name }}</span>
                                </span>
                                <span v-if="k.milikLain" class="shrink-0 text-xs text-neutral-500">dipakai {{ k.milikLain }}</span>
                                <span v-else-if="k.disarankan" class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                    <Sparkles class="h-3 w-3" />disarankan
                                </span>
                            </label>
                        </li>
                    </ul>

                    <div class="flex items-center justify-between gap-3 border-t border-neutral-200 p-4 dark:border-neutral-800">
                        <p v-if="galat" class="text-xs text-danger-600">{{ galat }}</p>
                        <span v-else></span>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700" @click="modal = false">Batal</button>
                            <Button :disabled="menyimpan" @click="simpanTautan">{{ menyimpan ? 'Menyimpan…' : 'Simpan Tautan' }}</Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
