<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, FileText, ChevronDown, ListTree, Layers, Lock, FilterX } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ journals: any; sources: Record<string, string>; statuses: Record<string, string>; filters: Record<string, any>; defaultDetail?: boolean; listRoute?: string; title?: string; canApprove?: boolean; jumlahStatus?: Record<string, number>; tahunRekap?: number;
    // Isi pilihan tiap saringan kolom, dihitung server dari rentang tanggalnya.
    opsiKolom?: Record<string, Array<{ id: string; label: string }>>; batasOpsi?: number;
    // Keterangan anggaran per jurnal: over / under / tanpa anggaran.
    budgetStatuses?: Record<string, string> }>();

/* Rekap per status untuk rentang yang sedang tampil. Angkanya dihitung server dengan aturan
   yang SAMA PERSIS dengan General Function — jurnal pada periode tertutup masuk Closed dan
   tidak ikut dihitung sebagai Baru/Final/Approved — lalu tiap angka menautkan ke sana. */
const REKAP: Array<{ k: string; label: string; nada: string }> = [
    { k: 'baru',     label: 'Jurnal Baru',     nada: 'bg-primary-50 text-primary-700 ring-primary-200 dark:bg-primary-900/20 dark:text-primary-300 dark:ring-primary-800' },
    { k: 'final',    label: 'Jurnal Final',    nada: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-800' },
    { k: 'approved', label: 'Jurnal Approved', nada: 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-900/20 dark:text-success-300 dark:ring-success-800' },
    { k: 'closed',   label: 'Jurnal Closed',   nada: 'bg-neutral-100 text-neutral-600 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-700' },
];
const rekap = computed(() => REKAP.map((r) => ({ ...r, n: props.jumlahStatus?.[r.k] ?? 0 })));
const adaRekap = computed(() => !! props.jumlahStatus);

/* ── Aksi massal ────────────────────────────────────────────────────────────
   SETIAP jurnal bisa dicentang, apa pun statusnya. Aksinya tidak lagi dipilih lebih dulu
   dari daftar — ia DISIMPULKAN dari status baris yang tercentang:

       semua Baru      → Finalisasi Massal
       semua Final     → Approve Massal          (mundurnya: Cancel Finalisasi Massal)
       semua Approved  → Cancel Approved Massal
       status campuran → tidak ada aksi; layar meminta pilihannya dipersempit

   Alasannya: satu perintah massal hanya sah untuk satu status asal. Dulu hal itu dijaga
   dengan mematikan centang pada baris yang tidak cocok, sehingga orang harus menebak aksi
   mana yang membuka baris yang ingin dikerjakannya. Sekarang urutannya dibalik — centang
   dulu, tombolnya menyesuaikan — dan pembatasan yang sama tetap ditegakkan lewat
   permintaan mempersempit pilihan saat statusnya tercampur.

   Tetap tidak ada yang melompati dua tahap pada rantai Baru → Final → Approved: jurnal
   Approved harus dibatalkan approval-nya dulu (kembali Final) sebelum finalisasinya bisa
   dibatalkan. Server menegakkan aturan yang sama; layar ini hanya mendahuluinya. */
type Aksi = { key: string; label: string; dari: string; jadi: string; rute: string; izin: 'gl' | 'approval'; nada: string };
const AKSI: Aksi[] = [
    { key: 'finalisasi',       label: 'Finalisasi Massal',        dari: 'draft',  jadi: 'final',  rute: 'bs.gl.journal.finalize-batch',        izin: 'gl',       nada: 'bg-primary-600 hover:bg-primary-700 text-white' },
    { key: 'approve',          label: 'Approve Massal',           dari: 'final',  jadi: 'posted', rute: 'bs.gl.journal.approve-batch',         izin: 'approval', nada: 'bg-success-600 hover:bg-success-700 text-white' },
    { key: 'batal-approve',    label: 'Cancel Approved Massal',   dari: 'posted', jadi: 'final',  rute: 'bs.gl.journal.cancel-batch',          izin: 'approval', nada: 'border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700' },
    { key: 'batal-finalisasi', label: 'Cancel Finalisasi Massal', dari: 'final',  jadi: 'draft',  rute: 'bs.gl.journal.cancel-finalize-batch', izin: 'gl',       nada: 'border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700' },
];
/** Urutan aksi per status asal — yang pertama jadi tombol utama, sisanya tombol pendamping. */
const RANTAI: Record<string, string[]> = {
    draft:  ['finalisasi'],
    final:  ['approve', 'batal-finalisasi'],
    posted: ['batal-approve'],
};

const dipilih = ref<number[]>([]);
const barisHalaman = computed<any[]>(() => props.journals?.data ?? []);

/**
 * Jurnal pada periode yang sudah CLOSED tidak bisa diubah sama sekali — bukan sekadar
 * "belum boleh": JournalService menolaknya lewat assertPeriodOpen(), jadi menawarkan kotak
 * centangnya hanya akan berakhir pada penolakan. Satu-satunya jalan mengubahnya adalah
 * membatalkan closing periodenya dulu (General Function → Cancel Closing) sehingga periode
 * itu kembali OPEN.
 */
function terkunci(j: any) { return !! j?.period_closed; }
/** Semua baris bisa dicentang, KECUALI yang periodenya sudah ditutup. */
const bisaDipilih = computed<number[]>(() =>
    barisHalaman.value.filter((j: any) => ! terkunci(j)).map((j: any) => Number(j.id)));

// Halaman/filter berubah → buang centang yang barisnya sudah tidak ada.
watch(() => props.journals, () => {
    const ada = new Set(bisaDipilih.value);
    dipilih.value = dipilih.value.filter((id) => ada.has(id));
});

/** Status apa saja yang tercentang, beserta id-nya. */
const perStatus = computed<Record<string, number[]>>(() => {
    const set = new Set(dipilih.value);
    const out: Record<string, number[]> = {};
    for (const j of barisHalaman.value) {
        if (! set.has(Number(j.id))) continue;
        (out[j.status] ??= []).push(Number(j.id));
    }

    return out;
});
const statusTerpilih = computed<string[]>(() => Object.keys(perStatus.value));
const campuran = computed(() => statusTerpilih.value.length > 1);

const bolehAksi = (a: Aksi) => a.izin === 'gl' || !! props.canApprove;
/** Aksi yang sah untuk pilihan sekarang — kosong bila belum memilih atau statusnya campuran. */
const aksiCocok = computed<Aksi[]>(() => {
    if (campuran.value || statusTerpilih.value.length !== 1) return [];

    return (RANTAI[statusTerpilih.value[0]] ?? [])
        .map((k) => AKSI.find((a) => a.key === k))
        .filter((a): a is Aksi => !! a && bolehAksi(a));
});
const aksiUtama = computed<Aksi | null>(() => aksiCocok.value[0] ?? null);
const aksiLain = computed<Aksi[]>(() => aksiCocok.value.slice(1));

/** Sisakan hanya baris berstatus tertentu — jalan keluar satu klik dari pilihan campuran. */
function sisakan(status: string) { dipilih.value = [...(perStatus.value[status] ?? [])]; }

const semuaTercentang = computed<boolean>({
    get: () => bisaDipilih.value.length > 0 && bisaDipilih.value.every((id) => dipilih.value.includes(id)),
    set: (v: boolean) => { dipilih.value = v ? [...bisaDipilih.value] : []; },
});

const memproses = ref(false);
function jalankanAksi(a: Aksi | null) {
    if (! a || ! dipilih.value.length || memproses.value) return;
    // Penjaga terakhir di layar: jangan pernah mengirim id berstatus lain ke rute aksi ini.
    const ids = perStatus.value[a.dari] ?? [];
    if (ids.length !== dipilih.value.length) return;
    const pesan = `${a.label}: ${ids.length} jurnal akan berubah dari `
        + `${props.statuses[a.dari] ?? a.dari} menjadi ${props.statuses[a.jadi] ?? a.jadi}.\n\nLanjutkan?`;
    if (! confirm(pesan)) return;
    memproses.value = true;
    router.post(route(a.rute), { ids }, {
        preserveScroll: true,
        onFinish: () => { memproses.value = false; dipilih.value = []; },
    });
}
const listRoute = props.listRoute ?? 'bs.gl.journal.index';
const source = ref(props.filters.source ?? '');
const statusFilter = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

/* ── Saringan per kolom ──────────────────────────────────────────────────────
   Tiap kolom punya saringannya sendiri, berupa dropdown yang bisa diketik untuk mencari
   (SearchSelect). Isinya datang dari server, dihitung dari rentang tanggalnya saja dan
   LEPAS dari saringan kolom lain — kalau ikut disaring, memilih satu kolom akan
   mengosongkan pilihan kolom lain dan orang tidak bisa lagi mengubah pikirannya.

   Sumber & Status memakai nama parameter lamanya (source/status) supaya tautan dan
   bookmark yang sudah ada tetap bekerja. */
const kolomFilter = ref<Record<string, any>>({
    f_number: props.filters.f_number ?? '',
    f_date: props.filters.f_date ?? '',
    f_desc: props.filters.f_desc ?? '',
    f_lines: props.filters.f_lines ?? '',
    f_debit: props.filters.f_debit ?? '',
    f_credit: props.filters.f_credit ?? '',
    f_account: props.filters.f_account ?? '',
    f_line_desc: props.filters.f_line_desc ?? '',
    f_budget: props.filters.f_budget ?? '',
});

/** Seluruh parameter yang sedang berlaku — dipakai bersama oleh Display dan Excel,
 *  supaya berkas yang diunduh selalu berisi persis apa yang sedang terlihat di layar. */
function paramSaring() {
    const p: Record<string, any> = {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    };
    for (const [k, v] of Object.entries(kolomFilter.value)) p[k] = (v === '' || v == null) ? undefined : v;
    return p;
}

function display() {
    router.get(route(listRoute), paramSaring(), { preserveState: true, replace: true });
}
function exportExcel() {
    window.location.href = route('bs.gl.journal.export', paramSaring());
}

/** Memilih di kolom langsung menampilkan hasilnya — tidak menunggu tombol Display,
 *  karena saringan kolom dipakai untuk menelusuri, bukan untuk menyusun kueri. */
function pilihKolom(key: string, v: any) {
    kolomFilter.value[key] = v ?? '';
    display();
}

const opsi = (k: string) => props.opsiKolom?.[k] ?? [];
/* Sumber & Status pilihannya berasal dari daftar bakunya, bukan dari data — supaya nilai
   yang sah tetap bisa dipilih walau kebetulan belum dipakai satu jurnal pun pada rentang
   ini, dan hasil kosongnya sendiri yang menjawab. */
/* Keterangan anggaran. TIGA keadaan, bukan dua: jurnal yang tak satu pun barisnya
   beranggaran TIDAK diberi hijau, karena mengatakan "Under Budget" pada sesuatu yang tak
   beranggaran berarti menjanjikan pengawasan yang sebenarnya tidak ada. */
const NADA_ANGGARAN: Record<string, string> = {
    over:  'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-900/25 dark:text-danger-300 dark:ring-danger-800/60',
    under: 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-900/25 dark:text-success-300 dark:ring-success-800/60',
    none:  'bg-neutral-100 text-neutral-500 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:ring-neutral-700',
};
const opsiAnggaran = computed(() => Object.entries(props.budgetStatuses ?? {}).map(([id, label]) => ({ id, label })));

const opsiSumber = computed(() => Object.entries(props.sources ?? {}).map(([id, label]) => ({ id, label })));
const opsiStatus = computed(() => Object.entries(props.statuses ?? {}).map(([id, label]) => ({ id, label })));
/** Berapa saringan kolom yang sedang aktif — dipakai tombol "Bersihkan". */
const jumlahSaringan = computed(() =>
    Object.values(kolomFilter.value).filter((v) => v !== '' && v != null).length
    + (source.value ? 1 : 0) + (statusFilter.value ? 1 : 0));
function bersihkanSaringan() {
    for (const k of Object.keys(kolomFilter.value)) kolomFilter.value[k] = '';
    source.value = '';
    statusFilter.value = '';
    display();
}
/** Daftar pilihan yang terpotong batas server disebutkan apa adanya — daftar yang diam-diam
 *  terpotong akan terbaca sebagai "nilainya memang tidak ada". */
const adaYangTerpotong = computed(() =>
    !! props.batasOpsi && Object.values(props.opsiKolom ?? {}).some((o) => o.length >= (props.batasOpsi as number)));
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusBadge: Record<string, any> = { draft: 'warning', final: 'info', posted: 'success' };

/* Saringan Akun & Keterangan Baris menjaring JURNALNYA, bukan barisnya: seluruh baris
   tetap ditampilkan supaya debit & kreditnya tetap seimbang di layar. Baris yang memang
   cocok ditandai, supaya mata langsung menemukannya di jurnal yang barisnya banyak. */
function barisCocok(ln: any) {
    const a = kolomFilter.value.f_account;
    const d = kolomFilter.value.f_line_desc;
    return (!!a && String(ln.account_id) === String(a)) || (!!d && ln.description === d);
}

// Rincian baris (akun debit/kredit) tiap jurnal. Default mengikuti mode halaman:
//  Master → "Jurnal" = tersembunyi (ringkas); Query → "Detail Transaksi Jurnal" = tampil.
const showDetail = ref(props.defaultDetail ?? false);
const open = ref<Record<number, boolean>>({});
function toggle(id: number) { open.value[id] = !open.value[id]; }
function isOpen(id: number) { return showDetail.value || open.value[id]; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> {{ title ?? 'Jurnal' }}</h1>
            <Link :href="route('bs.gl.journal.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Jurnal Memorial</Button></Link>
        </div>

        <!-- Filter: periode tanggal + sumber + status + cari -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label><input v-model="dateFrom" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label><input v-model="dateTo" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div class="flex-1 min-w-[160px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="No. Jurnal / Keterangan / Ref" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="display" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            <button @click="exportExcel" class="h-9 rounded-lg bg-success-600 px-5 text-sm font-semibold text-white hover:bg-success-700">Excel</button>
            <!-- Sumber & Status pindah ke saringan kolom, supaya semua kolom disaring di satu
                 tempat yang sama dan tidak ada dua kontrol untuk hal yang sama. -->
            <button v-if="jumlahSaringan" @click="bersihkanSaringan"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-4 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">
                <FilterX class="h-4 w-4" /> Bersihkan saringan ({{ jumlahSaringan }})
            </button>
        </div>

        <!-- Rekap per status. Tiap angka menautkan ke General Function, layar yang memuat
             hitungan yang sama untuk SETAHUN penuh beserta status closing tiap bulannya. -->
        <div v-if="adaRekap" class="flex flex-wrap items-center gap-2">
            <Link v-for="r in rekap" :key="r.k" :href="route('bs.gl.printhub', { year: tahunRekap })"
                  class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 transition hover:brightness-95"
                  :class="r.nada" :title="r.label + ': ' + r.n + ' jurnal pada rentang ini — buka General Function ' + tahunRekap">
                {{ r.label }} <b class="text-sm">{{ r.n }}</b>
            </Link>
            <span class="text-[11px] text-neutral-400">Klik untuk membuka General Function {{ tahunRekap }}.</span>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-neutral-500">Periode <span class="font-medium">{{ fmtDate(filters.date_from) }}</span> s/d <span class="font-medium">{{ fmtDate(filters.date_to) }}</span> · {{ journals.total }} jurnal</p>
            <button @click="showDetail = !showDetail" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">
                <ListTree class="h-3.5 w-3.5" /> {{ showDetail ? 'Sembunyikan Rincian' : 'Tampilkan Rincian' }}
            </button>
        </div>

        <!-- ══ Aksi massal ══
             Centang dulu, tombolnya menyesuaikan sendiri: semua Baru → Finalisasi Massal,
             semua Final → Approve Massal, semua Approved → Cancel Approved Massal. Bila
             status yang tercentang tercampur, tidak ada tombol aksi — layar meminta
             pilihannya dipersempit, sebab satu perintah massal hanya sah untuk satu
             status asal. -->
        <div class="card p-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2 text-sm font-semibold"><Layers class="h-4 w-4 text-primary-600" /> Aksi Massal</div>
                <span class="text-xs text-neutral-500">
                    {{ dipilih.length }} terpilih dari {{ bisaDipilih.length }} jurnal di halaman ini
                </span>
                <span v-if="aksiUtama" class="rounded-md bg-neutral-100 px-2 py-1 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                    {{ statuses[aksiUtama.dari] ?? aksiUtama.dari }} → {{ statuses[aksiUtama.jadi] ?? aksiUtama.jadi }}
                </span>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <button v-for="a in aksiLain" :key="a.key" type="button" @click="jalankanAksi(a)" :disabled="memproses"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40"
                            :class="a.nada">{{ a.label }}</button>
                    <button type="button" @click="jalankanAksi(aksiUtama)" :disabled="!aksiUtama || memproses"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg px-4 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-40"
                            :class="aksiUtama ? aksiUtama.nada : 'border border-neutral-300 dark:border-neutral-700'">
                        {{ memproses ? 'Memproses…' : (aksiUtama ? aksiUtama.label : 'Pilih jurnalnya dulu') }}<span v-if="aksiUtama && dipilih.length"> ({{ dipilih.length }})</span>
                    </button>
                </div>
            </div>

            <!-- Rule 3: pilihan bercampur → minta dipersempit, lengkap dengan jalan keluar satu klik. -->
            <div v-if="campuran" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                <span>Status yang Anda centang bercampur, padahal satu perintah massal hanya berlaku untuk <b>satu status</b>. Persempit dulu:</span>
                <button v-for="s in statusTerpilih" :key="s" type="button" @click="sisakan(s)"
                        class="inline-flex h-7 items-center rounded-md border border-amber-300 bg-white px-2.5 font-semibold text-amber-800 hover:bg-amber-100 dark:border-amber-600/50 dark:bg-transparent dark:text-amber-200">
                    Sisakan {{ statuses[s] ?? s }} saja ({{ perStatus[s].length }})
                </button>
            </div>
            <p v-else-if="dipilih.length && !aksiUtama" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                Tidak ada aksi massal yang tersedia untuk jurnal berstatus
                <b>{{ statuses[statusTerpilih[0]] ?? statusTerpilih[0] }}</b> dengan wewenang Anda saat ini.
            </p>
            <p class="mt-2 text-[11.5px] leading-relaxed text-neutral-500">
                Centang jurnalnya, tombol aksinya menyesuaikan sendiri: semua <b>Baru</b> → Finalisasi Massal,
                semua <b>Final</b> → Approve Massal, semua <b>Approved</b> → Cancel Approved Massal.
                Setiap aksi bergerak <b>satu tahap</b> pada rantai Baru → Final → Approved.
                Jurnal <b>Approved tidak bisa langsung kembali ke Baru</b>: batalkan approval-nya dulu
                (Approved → Final), baru finalisasinya dibatalkan (Final → Baru).
            </p>
        </div>

        <!-- ══ Tabel ══
             Rincian baris TIDAK lagi memakai tabel sendiri di dalam sel. Dulu begitu, dan
             akibatnya kolom Akun / Keterangan Baris / Debit / Kredit punya grid sendiri yang
             tidak pernah sejajar dengan No. Jurnal / Tanggal / Sumber / Keterangan / Nilai di
             atasnya — mata harus melompat tiap kali berpindah dari kepala jurnal ke isinya.

             Sekarang semuanya SATU tabel dengan satu grid kolom:

               No. Jurnal  Tanggal  Sumber │ Keterangan       │ Status  Baris │  Debit   Kredit
               └────────── Akun ──────────┘ └Keterangan Baris┘                └ Debit ┘└Kredit┘

             Kolom "Nilai" yang dulu satu dipecah menjadi Debit & Kredit, sehingga angka pada
             kepala jurnal berdiri tepat di atas angka barisnya dan penjumlahannya bisa
             diperiksa dengan menyusuri satu kolom lurus ke bawah. -->
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[78rem] table-fixed text-sm">
                <colgroup>
                    <col class="w-10" /><col class="w-10" />
                    <col class="w-[13rem]" /><col class="w-[7.5rem]" /><col class="w-[9rem]" />
                    <col /><col class="w-[7rem]" /><col class="w-[5rem]" />
                    <col class="w-[10rem]" /><col class="w-[10rem]" /><col class="w-[8.5rem]" /><col class="w-[5.5rem]" />
                </colgroup>
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                        <th class="px-2 py-3"><input v-model="semuaTercentang" type="checkbox" :disabled="!bisaDipilih.length" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" title="Pilih semua jurnal di halaman ini" /></th>
                        <th class="px-2 py-3"></th>
                        <th class="px-4 py-3">No. Jurnal</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">Sumber</th>
                        <th class="px-4 py-3">Keterangan</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Baris</th>
                        <th class="px-4 py-3 text-right">Debit</th>
                        <th class="px-4 py-3 text-right">Kredit</th>
                        <th class="px-4 py-3 text-center">Anggaran</th>
                        <th class="px-4 py-3"></th>
                    </tr>

                    <!-- ══ SARINGAN KOLOM ══
                         Satu saringan untuk SETIAP kolom, semuanya dropdown yang bisa diketik
                         untuk mencari. Dua baris, karena kolom rincian berbagi grid yang sama
                         dengan kolom induknya: baris pertama menyaring kepala jurnal, baris
                         kedua menyaring isinya — masing-masing tepat di bawah kolom yang
                         disaringnya, bukan di bilah terpisah yang harus dicocokkan sendiri. -->
                    <tr class="border-t border-neutral-200 bg-white/60 dark:border-neutral-700 dark:bg-neutral-900/40">
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2 text-[10px] font-semibold uppercase text-neutral-400">Sar.</th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_number" :options="opsi('number')" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_number', v)" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_date" :options="opsi('date')" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_date', v)" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="source" :options="opsiSumber" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => { source = v ?? ''; display(); }" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_desc" :options="opsi('desc')" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_desc', v)" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="statusFilter" :options="opsiStatus" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => { statusFilter = v ?? ''; display(); }" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_lines" :options="opsi('lines')" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_lines', v)" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_debit" :options="opsi('debit')" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_debit', v)" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_credit" :options="opsi('credit')" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_credit', v)" /></th>
                        <th class="px-2 py-2"><SearchSelect :model-value="kolomFilter.f_budget" :options="opsiAnggaran" option-value="id" placeholder="Semua" no-truncate @update:model-value="(v) => pilihKolom('f_budget', v)" /></th>
                        <th class="px-2 py-2"></th>
                    </tr>
                    <tr class="bg-white/60 dark:bg-neutral-900/40">
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2 text-[10px] font-semibold uppercase text-neutral-400">Rinci</th>
                        <th class="px-2 pb-2.5" colspan="3"><SearchSelect :model-value="kolomFilter.f_account" :options="opsi('account')" option-value="id" placeholder="Semua Akun" no-truncate @update:model-value="(v) => pilihKolom('f_account', v)" /></th>
                        <th class="px-2 pb-2.5"><SearchSelect :model-value="kolomFilter.f_line_desc" :options="opsi('line_desc')" option-value="id" placeholder="Semua Keterangan Baris" no-truncate @update:model-value="(v) => pilihKolom('f_line_desc', v)" /></th>
                        <th class="px-2 pb-2.5 text-center text-[10px] font-normal normal-case text-neutral-400" colspan="2">menjaring jurnalnya</th>
                        <th class="px-2 pb-2.5" colspan="4"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="j in journals.data" :key="j.id">
                        <!-- Kepala jurnal -->
                        <tr class="align-top hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40" :class="isOpen(j.id) ? 'bg-neutral-50/40 dark:bg-neutral-800/20' : ''">
                            <td class="px-2 py-2.5 text-center">
                                <input v-if="!terkunci(j)" v-model="dipilih" type="checkbox" :value="Number(j.id)" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :title="'Pilih jurnal ' + j.journal_number" />
                                <Lock v-else class="mx-auto h-3.5 w-3.5 text-neutral-300 dark:text-neutral-600" title="Periode sudah closed — jurnalnya tidak bisa diubah. Batalkan closing periodenya dulu di General Function." />
                            </td>
                            <td class="px-2 py-2.5 text-center"><button type="button" @click="toggle(j.id)" class="text-neutral-400 hover:text-primary-600"><ChevronDown class="h-4 w-4 transition-transform" :class="{ 'rotate-180': isOpen(j.id) }" /></button></td>
                            <td class="px-4 py-2.5 font-mono font-semibold break-words">{{ j.journal_number }}</td>
                            <td class="px-4 py-2.5">{{ fmtDate(j.journal_date) }}</td>
                            <td class="px-4 py-2.5"><Badge variant="info" size="sm">{{ sources[j.source] ?? j.source }}</Badge></td>
                            <!-- Keterangan dibiarkan MEMBUNGKUS, tidak dipotong: keterangan jurnal
                                 adalah satu-satunya penjelasan mengapa jurnalnya ada. -->
                            <td class="px-4 py-2.5 whitespace-normal break-words">{{ j.description }}<span v-if="j.reference" class="mt-0.5 block text-xs text-neutral-400">Ref: {{ j.reference }}</span></td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="statusBadge[j.status] ?? 'neutral'" size="sm">{{ statuses[j.status] ?? j.status }}</Badge></td>
                            <td class="px-4 py-2.5 text-center tabular-nums">{{ j.lines_count }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold tabular-nums">{{ fmtRp(j.total_debit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold tabular-nums">{{ fmtRp(j.total_credit) }}</td>
                            <!-- Over MAUPUN Under sama-sama membawa angkanya sendiri: anggaran,
                                 realisasi, dan sisanya. "Under Budget" tanpa menyebut anggaran
                                 mana yang dipatuhi hanya berbeda WARNA dari "Non Budget",
                                 padahal keduanya keadaan yang berbeda — yang satu beranggaran
                                 dan masih bersisa, yang satu memang tidak punya anggaran. -->
                            <td class="px-4 py-2.5 text-center">
                                <span v-if="j.budget_status" class="inline-block rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1"
                                      :class="NADA_ANGGARAN[j.budget_status]"
                                      :title="j.budget_note ?? 'Tidak ada anggaran disetujui untuk akun & cost center pada jurnal ini — tidak ada yang membatasi.'">
                                    {{ budgetStatuses?.[j.budget_status] ?? j.budget_status }}
                                </span>
                                <span v-else class="text-[11px] text-neutral-400">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-right"><Link :href="route('bs.gl.journal.show', j.id)" class="whitespace-nowrap text-sm text-primary-600 hover:text-primary-800">Lihat →</Link></td>
                        </tr>

                        <!-- Kepala rincian — nama kolomnya berdiri tepat di atas isinya, dan
                             tepat di bawah kolom induk yang sepadan. -->
                        <tr v-if="isOpen(j.id)" class="bg-primary-50/40 text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-primary-900/10">
                            <td class="px-2"></td>
                            <td class="px-2"></td>
                            <td class="border-l-2 border-primary-300 px-4 pt-2 pb-1 dark:border-primary-700" colspan="3">Akun</td>
                            <td class="px-4 pt-2 pb-1">Keterangan Baris</td>
                            <td class="px-4" colspan="2"></td>
                            <td class="px-4 pt-2 pb-1 text-right">Debit</td>
                            <td class="px-4 pt-2 pb-1 text-right">Kredit</td>
                            <td></td>
                            <td></td>
                        </tr>

                        <!-- Baris rincian -->
                        <tr v-for="ln in (isOpen(j.id) ? (j.lines ?? []) : [])" :key="ln.id"
                            class="align-top bg-primary-50/40 text-xs dark:bg-primary-900/10"
                            :class="barisCocok(ln) ? 'bg-amber-50 dark:bg-amber-900/20' : ''">
                            <td class="px-2"></td>
                            <td class="px-2"></td>
                            <td class="border-l-2 border-primary-300 px-4 py-1.5 font-mono whitespace-normal break-words dark:border-primary-700" colspan="3">
                                <span class="font-semibold">{{ ln.account?.code }}</span>
                                <span class="text-neutral-500 dark:text-neutral-400"> — {{ ln.account?.name }}</span>
                            </td>
                            <td class="px-4 py-1.5 whitespace-normal break-words text-neutral-600 dark:text-neutral-300">{{ ln.description ?? '—' }}</td>
                            <td class="px-4" colspan="2"></td>
                            <td class="px-4 py-1.5 text-right font-mono tabular-nums">{{ Number(ln.debit) > 0 ? fmtRp(ln.debit) : '' }}</td>
                            <td class="px-4 py-1.5 text-right font-mono tabular-nums">{{ Number(ln.credit) > 0 ? fmtRp(ln.credit) : '' }}</td>
                            <td></td>
                            <td></td>
                        </tr>

                        <!-- Total rincian — berdiri di kolom yang SAMA dengan total pada kepala
                             jurnal, sehingga cocok/tidaknya bisa dibaca lurus ke atas. -->
                        <tr v-if="isOpen(j.id)" class="bg-primary-50/40 text-xs font-semibold dark:bg-primary-900/10">
                            <td class="px-2"></td>
                            <td class="px-2"></td>
                            <td class="border-l-2 border-primary-300 border-t border-t-neutral-300 px-4 py-1.5 dark:border-primary-700 dark:border-t-neutral-700" colspan="4">Total</td>
                            <td class="border-t border-neutral-300 px-4 dark:border-neutral-700" colspan="2"></td>
                            <td class="border-t border-neutral-300 px-4 py-1.5 text-right font-mono tabular-nums dark:border-neutral-700">{{ fmtRp(j.total_debit) }}</td>
                            <td class="border-t border-neutral-300 px-4 py-1.5 text-right font-mono tabular-nums dark:border-neutral-700">{{ fmtRp(j.total_credit) }}</td>
                            <td class="border-t border-neutral-300 dark:border-neutral-700"></td>
                            <td class="border-t border-neutral-300 dark:border-neutral-700"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!journals.data?.length" title="Belum ada jurnal pada periode ini" description="Ubah rentang tanggal, longgarkan saringan kolomnya, atau buat Jurnal Memorial." />
            <p class="border-t border-neutral-200 px-4 py-2 text-[11px] leading-relaxed text-neutral-500 dark:border-neutral-800">
                <b>Anggaran</b> dibaca dari Master Budget yang sudah <b>disetujui</b>, per tahun + akun + cost center.
                <span class="font-semibold text-danger-600 dark:text-danger-400">Over Budget</span> — akunnya <b>punya anggaran</b> dan realisasinya
                sudah <b>melebihi</b> anggaran itu; input baru pada akun &amp; cost center tersebut <b>ditolak</b> sistem.
                <span class="font-semibold text-success-600 dark:text-success-400">Under Budget</span> — akunnya <b>punya anggaran</b> dan realisasinya
                masih <b>lebih kecil</b> daripada anggaran, jadi masih ada sisa dan input masih diterima.
                <span class="font-semibold">Non Budget</span> — akunnya <b>memang belum punya anggaran</b> disetujui sama sekali, jadi tidak ada
                yang dilampaui maupun disisakan. Ketiganya berlainan: arahkan kursor ke labelnya untuk melihat anggaran, realisasi, dan sisanya.
            </p>
            <p v-if="adaYangTerpotong" class="border-t border-neutral-200 px-4 py-2 text-[11px] text-neutral-400 dark:border-neutral-800">
                Sebagian daftar pilihan saringan dibatasi {{ batasOpsi }} nilai teratas. Persempit rentang tanggalnya bila nilai yang Anda cari belum muncul.
            </p>
        </div>
    </div>
</template>
