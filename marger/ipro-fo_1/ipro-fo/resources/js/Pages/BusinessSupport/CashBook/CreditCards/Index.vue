<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { CreditCard, Plus, Check, X, Clock, ShieldAlert } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Card = {
    id: number; account_id: number; account_code: string | null; account_name: string | null;
    card_number: string | null; name: string; label: string;
    is_active: boolean; balance: number; expired_date: string | null;
    expired: boolean; usable: boolean; note: string | null;
    /** Sisa saldo bulan sebelumnya — hanya bila bulan itu SUDAH di-closing. */
    saldo_awal: number;
    /** Nominal pembayaran Debit VA bulan ini (+ penyesuaian manual yang disetujui). */
    penambahan: number;
    /** Nominal Alokasi Kasbon bulan ini. */
    pemakaian: number;
    /** Saldo Awal + Penambahan − Pemakaian. */
    sisa_saldo: number;
    closed: boolean; closed_at: string | null; prev_closed: boolean;
};
type BarisRiwayat = {
    year: number; month: number; label: string;
    saldo_awal: number; penambahan: number; pemakaian: number; sisa_saldo: number;
    closed: boolean; closed_at: string | null; prev_closed: boolean;
};
type Permintaan = {
    id: number; credit_card_id: number; card: string; type: string; type_label: string;
    amount: number | null; expired_date: string | null; status: string; status_label: string;
    reference: string | null; source_type: string | null; note: string | null;
    created_at: string | null; approved_at: string | null;
};
const props = defineProps<{
    cards: Card[]; requests: Permintaan[];
    accounts: Array<{ id: number; label: string }>;
    types: Record<string, string>; canApprove: boolean;
    period: { year: number; month: number; label: string };
    riwayat: { card_id: number; label: string; rows: BarisRiwayat[] } | null;
}>();

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: any) { return v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

/* === Periode & riwayat ===
   Keempat angka kartu (Saldo Awal, Penambahan, Pemakaian, Sisa Saldo) selalu MILIK SATU BULAN.
   Karena itu bulannya dipilih di sini, dan pilihannya ikut masuk URL — tautan yang dibagikan
   harus menampilkan angka yang sama, bukan angka bulan berjalan orang yang membukanya. */
const bulanNama = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const bulan = ref(props.period.month);
const tahun = ref(props.period.year);
const tahunPilihan = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
const kartuRiwayat = ref<number | null>(props.riwayat?.card_id ?? null);
function muat() {
    router.get(route('bs.cashbook.credit-card.index'),
        { year: tahun.value, month: bulan.value, riwayat: kartuRiwayat.value ?? undefined },
        { preserveState: true, preserveScroll: true, replace: true });
}
function bukaRiwayat(c: Card) { kartuRiwayat.value = kartuRiwayat.value === c.id ? null : c.id; muat(); }

function closing(c: Card) {
    if (! confirm(`Closing kartu ${c.label} untuk ${props.period.label}?\n\n`
        + `Saldo Awal ${fmtRp(c.saldo_awal)} + Penambahan ${fmtRp(c.penambahan)} − Pemakaian ${fmtRp(c.pemakaian)} = Sisa ${fmtRp(c.sisa_saldo)}.\n`
        + 'Angkanya dibekukan, dan sisa itu menjadi Saldo Awal bulan berikutnya.')) return;
    router.post(route('bs.cashbook.credit-card.close', c.id),
        { year: props.period.year, month: props.period.month }, { preserveScroll: true });
}
function bukaClosing(c: Card) {
    if (! confirm(`Buka kembali closing ${props.period.label} pada kartu ${c.label}?\n\nAngkanya kembali dihitung dari dokumennya.`)) return;
    router.post(route('bs.cashbook.credit-card.reopen', c.id),
        { year: props.period.year, month: props.period.month }, { preserveScroll: true });
}
function closingRiwayat(b: BarisRiwayat) {
    if (! props.riwayat) return;
    if (! confirm(`Closing ${b.label}?\n\nSisa ${fmtRp(b.sisa_saldo)} menjadi Saldo Awal bulan berikutnya.`)) return;
    router.post(route('bs.cashbook.credit-card.close', props.riwayat.card_id),
        { year: b.year, month: b.month }, { preserveScroll: true });
}
function bukaClosingRiwayat(b: BarisRiwayat) {
    if (! props.riwayat) return;
    if (! confirm(`Buka kembali closing ${b.label}?`)) return;
    router.post(route('bs.cashbook.credit-card.reopen', props.riwayat.card_id),
        { year: b.year, month: b.month }, { preserveScroll: true });
}

/* Kartu baru selalu lahir NONAKTIF & bersaldo 0 — pengaktifan dan pengisian saldonya keputusan
   tersendiri, bukan efek samping dari menambahkannya ke daftar. */
const formKartu = useForm<any>({ account_id: '', card_number: '', name: '', note: '' });
function simpanKartu() { formKartu.post(route('bs.cashbook.credit-card.store'), { preserveScroll: true, onSuccess: () => formKartu.reset() }); }

// Nama kartu ikut terisi dari akun yang dipilih — hampir selalu sama, dan mengetiknya ulang
// hanya membuka peluang dua sebutan berbeda untuk rekening yang sama.
function pilihAkun(v: any) {
    formKartu.account_id = v;
    const a = props.accounts.find((x) => x.id === Number(v));
    if (a && ! formKartu.name) formKartu.name = a.label.replace(/^\d+\s+—\s+/, '');
}

const kartuAktif = ref<number | null>(null);
const formUbah = useForm<any>({ card_number: '', name: '', is_active: false, note: '' });
function bukaUbah(c: Card) {
    kartuAktif.value = c.id;
    formUbah.card_number = c.card_number ?? '';
    formUbah.name = c.name;
    formUbah.is_active = c.is_active;
    formUbah.note = c.note ?? '';
}
function simpanUbah() {
    if (! kartuAktif.value) return;
    formUbah.put(route('bs.cashbook.credit-card.update', kartuAktif.value), {
        preserveScroll: true, onSuccess: () => { kartuAktif.value = null; },
    });
}

/* Permintaan manual. Saldo TIDAK bisa disunting langsung di mana pun — setiap perubahannya
   lewat jalur ini supaya ada jejak siapa meminta, siapa menyetujui, dan atas dasar apa. */
const kartuPermintaan = ref<number | null>(null);
const formMinta = useForm<any>({ type: 'topup', amount: 0, expired_date: '', note: '' });
function bukaMinta(c: Card) { kartuPermintaan.value = c.id; formMinta.reset(); formMinta.type = 'topup'; }
function kirimMinta() {
    if (! kartuPermintaan.value) return;
    formMinta.post(route('bs.cashbook.credit-card.request', kartuPermintaan.value), {
        preserveScroll: true, onSuccess: () => { kartuPermintaan.value = null; },
    });
}

function setujui(r: Permintaan) {
    if (! confirm(`Setujui ${r.type_label} pada kartu ${r.card}?\n\nSaldo/masa berlaku kartunya akan langsung berubah.`)) return;
    router.post(route('bs.cashbook.credit-card.approve', r.id), {}, { preserveScroll: true });
}
function tolak(r: Permintaan) {
    const alasan = prompt('Alasan penolakan (opsional):') ?? '';
    router.post(route('bs.cashbook.credit-card.reject', r.id), { note: alasan }, { preserveScroll: true });
}

const menunggu = computed(() => props.requests.filter((r) => r.status === 'pending'));
/* Riwayat PERMINTAAN saldo/masa berlaku — sengaja bukan lagi bernama `riwayat`: prop
   `riwayat` adalah riwayat SALDO BULANAN kartunya, dan nama yang sama akan menutupi prop itu
   di template tanpa satu pun galat saat dibangun. */
const riwayatPermintaan = computed(() => props.requests.filter((r) => r.status !== 'pending'));
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><CreditCard class="h-6 w-6 text-primary-600" /> Set Up Credit Card</h1>
                <p class="text-sm text-neutral-500">
                    Kartu kredit / Virtual Account beserta saldonya <b>per bulan</b>.
                    <b>Saldo Awal</b> = sisa saldo bulan sebelumnya bila sudah di-closing ·
                    <b>Penambahan</b> = pembayaran Debit VA yang tersimpan ·
                    <b>Pemakaian</b> = Alokasi Kasbon ·
                    <b>Sisa Saldo</b> = Awal + Penambahan − Pemakaian.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select v-model.number="bulan" @change="muat" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(m, i) in bulanNama" :key="i" :value="i + 1">{{ m }}</option></select>
                <select v-model.number="tahun" @change="muat" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in tahunPilihan" :key="y" :value="y">{{ y }}</option></select>
            </div>
        </div>

        <!-- Daftar kartu -->
        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800"><h2 class="font-semibold">Kartu <span class="text-sm font-normal text-neutral-400">· {{ period.label }}</span></h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[86rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kartu</th>
                        <th class="px-4 py-3">Akun GL</th>
                        <!-- Empat angka, empat asal yang berbeda — dipisah kolomnya, bukan
                             disatukan menjadi satu "saldo" yang tidak bisa ditelusuri lagi. -->
                        <th class="px-4 py-3 text-right">Saldo Awal</th>
                        <th class="px-4 py-3 text-right">Penambahan</th>
                        <th class="px-4 py-3 text-right">Pemakaian</th>
                        <th class="px-4 py-3 text-right">Sisa Saldo</th>
                        <th class="px-4 py-3 text-center">Masa Berlaku</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Closing</th>
                        <th class="px-4 py-3 text-center">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="c in cards" :key="c.id" :class="riwayat?.card_id === c.id ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                            <td class="px-4 py-2.5">
                                <span class="font-medium">{{ c.card_number ?? '—' }}</span>
                                <span class="block text-xs text-neutral-500">{{ c.name }}</span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ c.account_code }}</td>
                            <td class="px-4 py-2.5 text-right font-mono"
                                :title="c.prev_closed ? 'Sisa saldo bulan sebelumnya (sudah di-closing).' : 'Bulan sebelumnya belum di-closing — saldo awal dihitung nol.'">
                                {{ fmtRp(c.saldo_awal) }}
                                <span v-if="! c.prev_closed" class="block text-[10px] font-sans text-amber-600 dark:text-amber-400">blm closing</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-success-600"
                                title="Nominal pembayaran Debit VA bulan ini + penyesuaian saldo manual yang disetujui.">{{ fmtRp(c.penambahan) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-danger-600"
                                title="Total Alokasi Kasbon bulan ini.">{{ fmtRp(c.pemakaian) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold"
                                :class="Number(c.sisa_saldo) > 0 ? 'text-primary-600 dark:text-primary-400' : 'text-neutral-400'"
                                title="Saldo Awal + Penambahan − Pemakaian.">{{ fmtRp(c.sisa_saldo) }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span :class="c.expired ? 'text-danger-600 font-medium' : 'text-neutral-500'">{{ fmtTgl(c.expired_date) }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <!-- Tiga keadaan yang berbeda akibatnya, jadi dibedakan tampilannya:
                                     belum aktif, aktif tapi kedaluwarsa, dan siap pakai. -->
                                <Badge v-if="! c.is_active" variant="warning" size="sm">Belum Aktif</Badge>
                                <Badge v-else-if="c.expired" variant="danger" size="sm">Expired</Badge>
                                <Badge v-else variant="success" size="sm">Aktif</Badge>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <button v-if="c.closed" type="button" class="rounded-lg border border-success-300 px-2 py-1 text-xs font-medium text-success-700 hover:bg-success-50 dark:border-success-700 dark:text-success-400"
                                        :title="'Sudah di-closing ' + (c.closed_at ?? '') + ' — klik untuk membukanya kembali'" @click="bukaClosing(c)">Closed</button>
                                <button v-else type="button" class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-50 dark:border-neutral-700"
                                        title="Bekukan angka bulan ini; sisanya menjadi saldo awal bulan berikutnya" @click="closing(c)">Closing</button>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <div class="flex flex-wrap items-center justify-center gap-1">
                                    <button type="button" class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-50 dark:border-neutral-700" @click="bukaRiwayat(c)">{{ riwayat?.card_id === c.id ? 'Tutup Riwayat' : 'Riwayat' }}</button>
                                    <button type="button" class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-50 dark:border-neutral-700" @click="bukaUbah(c)">Ubah</button>
                                    <button type="button" class="rounded-lg border border-primary-300 px-2 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:border-primary-700" @click="bukaMinta(c)">Ajukan Saldo / Expired</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="! cards.length" title="Belum ada kartu" description="Tambahkan kartu kredit/VA di bawah, lalu aktifkan dan isi saldonya." />
        </div>

        <!-- RIWAYAT DETAIL per bulan — saldo awal → penambahan → pemakaian → sisa saldo. -->
        <div v-if="riwayat" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Riwayat Detail — {{ riwayat.label }}</h2>
                <p class="text-xs text-neutral-500">Sisa saldo sebuah bulan menjadi saldo awal bulan berikutnya <b>hanya setelah bulan itu di-closing</b>.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Bulan</th>
                        <th class="px-4 py-3 text-right">Saldo Awal</th>
                        <th class="px-4 py-3 text-right">Penambahan</th>
                        <th class="px-4 py-3 text-right">Pemakaian</th>
                        <th class="px-4 py-3 text-right">Sisa Saldo</th>
                        <th class="px-4 py-3 text-center">Closing</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="b in riwayat.rows" :key="b.year + '-' + b.month">
                            <td class="px-4 py-2">{{ b.label }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ fmtRp(b.saldo_awal) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-success-600">{{ fmtRp(b.penambahan) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-danger-600">{{ fmtRp(b.pemakaian) }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ fmtRp(b.sisa_saldo) }}</td>
                            <td class="px-4 py-2 text-center">
                                <button v-if="b.closed" type="button" class="rounded-lg border border-success-300 px-2 py-0.5 text-xs font-medium text-success-700 hover:bg-success-50 dark:border-success-700 dark:text-success-400" @click="bukaClosingRiwayat(b)">Closed</button>
                                <button v-else type="button" class="rounded-lg border border-neutral-300 px-2 py-0.5 text-xs hover:bg-neutral-50 dark:border-neutral-700" @click="closingRiwayat(b)">Closing</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="! riwayat.rows.length" title="Belum ada mutasi" description="Kartu ini belum pernah menerima pembayaran Debit VA maupun dipakai lewat Alokasi Kasbon." />
        </div>

        <!-- Tambah kartu -->
        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Tambah Kartu</h2>
            <form @submit.prevent="simpanKartu" class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Akun GL *</label>
                    <SearchSelect :model-value="formKartu.account_id" :options="accounts" option-value="id" placeholder="— pilih akun bank —" @update:model-value="pilihAkun" />
                    <p v-if="formKartu.errors.account_id" class="mt-1 text-xs text-danger-500">{{ formKartu.errors.account_id }}</p>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Kartu / VA</label><input v-model="formKartu.card_number" :class="inputCls" placeholder="mis. 141-00-9988886-9" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="formKartu.name" required :class="inputCls" /><p v-if="formKartu.errors.name" class="mt-1 text-xs text-danger-500">{{ formKartu.errors.name }}</p></div>
                <div class="md:col-span-4">
                    <Button type="submit" :disabled="formKartu.processing || ! formKartu.account_id"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Kartu</Button>
                    <span class="ml-2 text-xs text-neutral-400">Kartu baru dibuat nonaktif & bersaldo 0.</span>
                </div>
            </form>
        </div>

        <!-- Ubah kartu -->
        <div v-if="kartuAktif" class="card p-5">
            <h2 class="mb-3 font-semibold">Ubah Kartu</h2>
            <form @submit.prevent="simpanUbah" class="grid gap-3 md:grid-cols-4">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Kartu / VA</label><input v-model="formUbah.card_number" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="formUbah.name" required :class="inputCls" /></div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Status</label>
                    <label class="flex h-9 items-center gap-2 text-sm"><input v-model="formUbah.is_active" type="checkbox" class="rounded accent-primary-600" /> Aktif</label>
                </div>
                <div class="md:col-span-4 flex flex-wrap items-center gap-2">
                    <Button type="submit" :disabled="formUbah.processing">Simpan</Button>
                    <button type="button" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700" @click="kartuAktif = null">Batal</button>
                    <!-- Saldo & masa berlaku sengaja TIDAK ada di form ini. -->
                    <span class="text-xs text-neutral-400">Saldo &amp; masa berlaku hanya berubah lewat permintaan yang disetujui.</span>
                </div>
            </form>
        </div>

        <!-- Ajukan permintaan -->
        <div v-if="kartuPermintaan" class="card p-5">
            <h2 class="mb-3 font-semibold">Ajukan Permintaan</h2>
            <form @submit.prevent="kirimMinta" class="grid gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Jenis *</label>
                    <select v-model="formMinta.type" :class="inputCls"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
                </div>
                <div v-if="formMinta.type !== 'expiry'">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Nilai (Rp) *</label>
                    <input v-model.number="formMinta.amount" type="number" min="0" step="1" :class="inputCls" />
                    <p v-if="formMinta.errors.amount" class="mt-1 text-xs text-danger-500">{{ formMinta.errors.amount }}</p>
                </div>
                <div v-else>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Expired *</label>
                    <input v-model="formMinta.expired_date" type="date" :class="inputCls" />
                    <p v-if="formMinta.errors.expired_date" class="mt-1 text-xs text-danger-500">{{ formMinta.errors.expired_date }}</p>
                </div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="formMinta.note" :class="inputCls" /></div>
                <div class="md:col-span-4 flex gap-2">
                    <Button type="submit" :disabled="formMinta.processing">Ajukan</Button>
                    <button type="button" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700" @click="kartuPermintaan = null">Batal</button>
                </div>
            </form>
        </div>

        <!-- Menunggu persetujuan -->
        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <Clock class="h-4 w-4 text-amber-600" />
                <h2 class="font-semibold">Menunggu Persetujuan</h2>
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ menunggu.length }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kartu</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3 text-right">Nilai</th>
                        <th class="px-4 py-3 text-center">Tgl Expired</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3 text-center">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in menunggu" :key="r.id">
                            <td class="px-4 py-2.5">{{ r.card }}</td>
                            <td class="px-4 py-2.5">{{ r.type_label }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ r.amount !== null ? fmtRp(r.amount) : '—' }}</td>
                            <td class="px-4 py-2.5 text-center">{{ fmtTgl(r.expired_date) }}</td>
                            <td class="px-4 py-2.5 text-xs text-neutral-500">
                                <span v-if="r.reference" class="font-mono">{{ r.reference }}</span>
                                <span v-else>manual</span>
                                <span v-if="r.note" class="block">{{ r.note }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <div v-if="canApprove" class="flex items-center justify-center gap-1">
                                    <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-success-300 px-2 py-1 text-xs font-medium text-success-700 hover:bg-success-50 dark:border-success-700" @click="setujui(r)"><Check class="h-3.5 w-3.5" /> Setujui</button>
                                    <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-danger-300 px-2 py-1 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700" @click="tolak(r)"><X class="h-3.5 w-3.5" /> Tolak</button>
                                </div>
                                <span v-else class="inline-flex items-center gap-1 text-xs text-neutral-400"><ShieldAlert class="h-3.5 w-3.5" /> tanpa hak approve</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="! menunggu.length" title="Tidak ada permintaan menunggu" description="Permintaan saldo & masa berlaku akan muncul di sini." />
        </div>

        <!-- Riwayat -->
        <div v-if="riwayatPermintaan.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800"><h2 class="font-semibold">Riwayat Permintaan</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kartu</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3 text-right">Nilai</th>
                        <th class="px-4 py-3 text-center">Tgl Expired</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3 text-center">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in riwayatPermintaan" :key="r.id">
                            <td class="px-4 py-2.5">{{ r.card }}</td>
                            <td class="px-4 py-2.5">{{ r.type_label }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ r.amount !== null ? fmtRp(r.amount) : '—' }}</td>
                            <td class="px-4 py-2.5 text-center">{{ fmtTgl(r.expired_date) }}</td>
                            <td class="px-4 py-2.5 text-xs text-neutral-500"><span v-if="r.reference" class="font-mono">{{ r.reference }}</span><span v-else>manual</span></td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'approved' ? 'success' : 'danger'" size="sm">{{ r.status_label }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
