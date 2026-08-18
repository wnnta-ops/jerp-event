<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, FileText, ChevronDown, ListTree, Layers, Lock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ journals: any; sources: Record<string, string>; statuses: Record<string, string>; filters: Record<string, any>; defaultDetail?: boolean; listRoute?: string; title?: string; canApprove?: boolean; jumlahStatus?: Record<string, number>; tahunRekap?: number }>();

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

function display() {
    router.get(route(listRoute), {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
}
function exportExcel() {
    window.location.href = route('bs.gl.journal.export', {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    });
}
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusBadge: Record<string, any> = { draft: 'warning', final: 'info', posted: 'success' };

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
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber</label><select v-model="source" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua Jenis</option><option v-for="(label, val) in sources" :key="val" :value="val">{{ label }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Status</label><select v-model="statusFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua</option><option v-for="(label, val) in statuses" :key="val" :value="val">{{ label }}</option></select></div>
            <div class="flex-1 min-w-[160px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="No. Jurnal / Keterangan / Ref" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="display" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            <button @click="exportExcel" class="h-9 rounded-lg bg-success-600 px-5 text-sm font-semibold text-white hover:bg-success-700">Excel</button>
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

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-2 py-3 w-8"><input v-model="semuaTercentang" type="checkbox" :disabled="!bisaDipilih.length" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" title="Pilih semua jurnal di halaman ini" /></th><th class="px-2 py-3 w-8"></th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-center">Baris</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-right"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="j in journals.data" :key="j.id">
                        <tr class="hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40">
                            <td class="px-2 py-2.5 text-center"><input v-if="!terkunci(j)" v-model="dipilih" type="checkbox" :value="Number(j.id)" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :title="'Pilih jurnal ' + j.journal_number" /><Lock v-else class="mx-auto h-3.5 w-3.5 text-neutral-300 dark:text-neutral-600" title="Periode sudah closed — jurnalnya tidak bisa diubah. Batalkan closing periodenya dulu di General Function." /></td><td class="px-2 py-2.5 text-center"><button type="button" @click="toggle(j.id)" class="text-neutral-400 hover:text-primary-600"><ChevronDown class="h-4 w-4 transition-transform" :class="{ 'rotate-180': isOpen(j.id) }" /></button></td>
                            <td class="px-4 py-2.5 font-mono font-semibold">{{ j.journal_number }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(j.journal_date) }}</td>
                            <td class="px-4 py-2.5"><Badge variant="info" size="sm">{{ sources[j.source] ?? j.source }}</Badge></td>
                            <td class="px-4 py-2.5">{{ j.description }}<span v-if="j.reference" class="block text-xs text-neutral-400">Ref: {{ j.reference }}</span></td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="statusBadge[j.status] ?? 'neutral'" size="sm">{{ statuses[j.status] ?? j.status }}</Badge></td>
                            <td class="px-4 py-2.5 text-center">{{ j.lines_count }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(j.total_debit) }}</td>
                            <td class="px-4 py-2.5 text-right"><Link :href="route('bs.gl.journal.show', j.id)" class="text-primary-600 text-sm">Lihat →</Link></td>
                        </tr>
                        <!-- Rincian baris jurnal (akun debit/kredit) -->
                        <tr v-if="isOpen(j.id)" class="bg-neutral-50 dark:bg-neutral-900/40">
                            <td></td>
                            <td colspan="9" class="px-4 py-2">
                                <table class="w-full text-xs">
                                    <thead><tr class="text-left text-neutral-400"><th class="py-1 pl-2">Akun</th><th class="py-1">Keterangan Baris</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right pr-2">Kredit</th></tr></thead>
                                    <tbody>
                                        <tr v-for="ln in (j.lines ?? [])" :key="ln.id" class="border-t border-neutral-200/70 dark:border-neutral-800">
                                            <td class="py-1 pl-2 font-mono">{{ ln.account?.code }} — {{ ln.account?.name }}</td>
                                            <td class="py-1 text-neutral-500">{{ ln.description ?? '—' }}</td>
                                            <td class="py-1 text-right font-mono">{{ Number(ln.debit) > 0 ? fmtRp(ln.debit) : '' }}</td>
                                            <td class="py-1 text-right font-mono pr-2">{{ Number(ln.credit) > 0 ? fmtRp(ln.credit) : '' }}</td>
                                        </tr>
                                        <tr class="border-t border-neutral-300 font-semibold dark:border-neutral-700">
                                            <td class="py-1 pl-2" colspan="2">Total</td>
                                            <td class="py-1 text-right font-mono">{{ fmtRp(j.total_debit) }}</td>
                                            <td class="py-1 text-right font-mono pr-2">{{ fmtRp(j.total_credit) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!journals.data?.length" title="Belum ada jurnal pada periode ini" description="Ubah rentang tanggal atau buat Jurnal Memorial." />
        </div>
    </div>
</template>
