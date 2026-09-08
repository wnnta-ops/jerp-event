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
    /** Dari mutasi akun GL kartunya (jumlah kredit) — yang benar-benar terpakai. */
    pemakaian: number;
    /** Nominal yang dikembalikan lewat tombol "Masuk Saldo" pada realisasi bon Debit VA. */
    sisa_saldo: number;
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
}>();

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: any) { return v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

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
const riwayat = computed(() => props.requests.filter((r) => r.status !== 'pending'));
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CreditCard class="h-6 w-6 text-primary-600" /> Set Up Credit Card</h1>
            <p class="text-sm text-neutral-500">
                Kartu kredit / Virtual Account beserta saldonya. Kartu harus <b>diaktifkan</b> dan <b>diisi saldonya</b>
                sebelum bisa dipakai. Saldo hanya berubah lewat <b>permintaan yang disetujui</b> — pembayaran bon
                sementara lewat Debit VA melahirkan permintaannya secara otomatis.
            </p>
        </div>

        <!-- Daftar kartu -->
        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800"><h2 class="font-semibold">Kartu</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[74rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kartu</th>
                        <th class="px-4 py-3">Akun GL</th>
                        <th class="px-4 py-3 text-right">Saldo</th>
                        <!-- PEMAKAIAN dari mutasi akun kartunya di buku besar; SISA SALDO dari
                             nominal yang dikembalikan lewat tombol "Masuk Saldo". Dua angka yang
                             berbeda asalnya, jadi dipisah kolomnya — bukan disatukan menjadi satu
                             "saldo" yang tidak bisa ditelusuri lagi dari mana datangnya. -->
                        <th class="px-4 py-3 text-right">Pemakaian</th>
                        <th class="px-4 py-3 text-right">Sisa Saldo</th>
                        <th class="px-4 py-3 text-center">Masa Berlaku</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="c in cards" :key="c.id">
                            <td class="px-4 py-2.5">
                                <span class="font-medium">{{ c.card_number ?? '—' }}</span>
                                <span class="block text-xs text-neutral-500">{{ c.name }}</span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ c.account_code }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(c.balance) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-neutral-600 dark:text-neutral-300"
                                title="Dari mutasi akun GL kartu ini — jumlah kredit, yaitu yang benar-benar terpakai.">{{ fmtRp(c.pemakaian) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono"
                                :class="Number(c.sisa_saldo) > 0 ? 'text-primary-600 dark:text-primary-400' : 'text-neutral-400'"
                                title="Sisa realisasi bon Debit VA yang dikembalikan lewat tombol Masuk Saldo.">{{ fmtRp(c.sisa_saldo) }}</td>
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
                                <div class="flex flex-wrap items-center justify-center gap-1">
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
        <div v-if="riwayat.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800"><h2 class="font-semibold">Riwayat Permintaan</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kartu</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3 text-right">Nilai</th>
                        <th class="px-4 py-3 text-center">Tgl Expired</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3 text-center">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in riwayat" :key="r.id">
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
