<!-- Cash Book → Transaksi Bon Sementara → Alokasi Bon Sementara: Alokasi Kasbon satu bon. -->
<script setup lang="ts">
/**
 * Blok "Alokasi Kasbon" DIPINDAH ke sini dari layar Realisasi Bon Sementara, beserta seluruh
 * penjaganya — termasuk gerbang jumlah-melebihi-sisa yang sama persis dengan servernya
 * (ALLOC_EXCEEDS) dan kalimat konfirmasi hapus yang membedakan jurnal sendiri dari jurnal
 * gabungan.
 *
 * Tambahannya satu: tombol "Lanjut Realisasikan Bon". Bon tidak akan sampai ke Realisasi
 * sebelum tombol itu ditekan — dan yang benar-benar menahan ada di
 * CashAdvanceService::realize(), bukan di sini. Layar ini hanya mencerminkannya.
 */
import { computed } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Wallet, Plus, Trash2, ArrowRight, CheckCircle2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{
    advance: any;
    cashAccounts: Array<any>;
    allocations: { rows: Array<any>; total: number; sisa: number };
    lanjutAlokasi: { perlu: boolean; sudah: boolean; pada: string | null };
    bisaUbah: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);
const alokForm = useForm<any>({ alloc_date: today, description: '', amount: 0, cash_account_id: '' });
const lanjutForm = useForm<any>({});

const sisaAlokasi = computed(() => Number(props.allocations?.sisa ?? 0));
const totalAlokasi = computed(() => Number(props.allocations?.total ?? 0));
const adaAlokasi = computed(() => (props.allocations?.rows ?? []).length > 0);

const alokKurang = computed<string[]>(() => {
    const k: string[] = [];
    if (! alokForm.alloc_date) k.push('tanggal');
    if (! String(alokForm.description ?? '').trim()) k.push('keterangan');
    if (! (Number(alokForm.amount) > 0)) k.push('jumlah');
    if (! alokForm.cash_account_id) k.push('bank pengeluaran');
    // Gerbang yang sama dengan servernya (ALLOC_EXCEEDS) — bukan lebih longgar.
    if (Number(alokForm.amount) > sisaAlokasi.value + 0.001) k.push('jumlah melebihi sisa bon');

    return k;
});

function tambahAlokasi() {
    if (alokKurang.value.length) return;
    alokForm.post(route('bs.advance.allocation.store', props.advance.id), {
        preserveScroll: true,
        onSuccess: () => { alokForm.reset('description', 'amount'); alokForm.amount = 0; },
    });
}

function hapusAlokasi(a: any) {
    /* Alokasi bertanggal & berbank sama berbagi SATU jurnal, jadi menghapus satu di antaranya
       belum tentu menghapus jurnalnya — kalimatnya menyesuaikan supaya tidak menjanjikan yang
       tidak terjadi. */
    const berbagi = ((props.allocations?.rows ?? []) as any[]).filter(
        (x: any) => x.alloc_date === a.alloc_date && x.account_id === a.account_id).length > 1;
    const akibat = berbagi
        ? 'Barisnya dicabut dari jurnal gabungan tanggal itu; jurnalnya tetap ada untuk alokasi lain.'
        : 'Jurnalnya ikut terhapus.';
    if (! confirm(`Hapus alokasi ${fmtDate(a.alloc_date)} — ${fmtRp(a.amount)}?\n\n${akibat}`)) return;
    router.delete(route('bs.advance.allocation.destroy', a.id), { preserveScroll: true });
}

/** Alasan tombol "Lanjut Realisasikan Bon" mati — dikatakan, bukan dibiarkan ditebak. */
const lanjutKurang = computed<string | null>(() => {
    if (props.lanjutAlokasi.sudah) return null;
    if (! props.bisaUbah) return 'Butuh izin cashbook.advance.realize.';
    if (totalAlokasi.value <= 0) return 'Belum ada Alokasi Kasbon — Uang Muka Operasional baru terbentuk dari alokasi.';

    return null;
});

function lanjut() {
    if (lanjutKurang.value) return;
    if (! confirm(`Lanjutkan bon ${props.advance.advance_number} ke Realisasi Bon Sementara?\n\n`
        + `Total alokasi ${fmtRp(totalAlokasi.value)} menjadi acuan realisasinya. `
        + `Sisa ${fmtRp(sisaAlokasi.value)} tetap menjadi saldo kartu.`)) return;
    lanjutForm.post(route('bs.advance.allocation.lanjut', props.advance.id));
}

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
// Penanda "belum diisi" adalah kelas PENGGANTI, bukan tambahan — dua kelas Tailwind yang
// bertabrakan tidak ditentukan pemenangnya oleh urutan di atribut.
const inputKurang = 'h-9 w-full rounded-lg border border-amber-400 bg-amber-50 px-3 text-sm dark:border-amber-500 dark:bg-amber-900/20';
const pilihKurang = 'rounded-lg ring-1 ring-amber-400';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.advance.allocation.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Alokasi Bon Sementara
        </Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <Wallet class="h-6 w-6 text-primary-600" /> Alokasi Kasbon · {{ advance.advance_number }}
            </h1>
            <Badge v-if="lanjutAlokasi.sudah" variant="success" dot>Sudah dilanjutkan ke Realisasi</Badge>
            <Badge v-else variant="warning" dot>Belum dilanjutkan</Badge>
        </div>

        <div class="card grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><div class="text-[11px] uppercase text-neutral-400">Pemegang</div><div class="font-medium">{{ advance.holder }}</div></div>
            <div><div class="text-[11px] uppercase text-neutral-400">Keperluan</div><div class="font-medium">{{ advance.purpose }}</div></div>
            <div><div class="text-[11px] uppercase text-neutral-400">Tanggal Bon</div><div class="font-medium">{{ fmtDate(advance.advance_date) }}</div></div>
            <div><div class="text-[11px] uppercase text-neutral-400">Kartu Debit/VA</div><div class="font-mono text-sm">{{ advance.credit_card?.name ?? '—' }}</div></div>
        </div>

        <div class="card p-5">
            <h2 class="mb-1 flex items-center gap-2 font-semibold"><Wallet class="h-5 w-5 text-primary-600" /> Alokasi Kasbon</h2>
            <p class="mb-3 text-xs text-neutral-500">
                Pembayaran Debit VA belum mengeluarkan uang dari bank. Catat tiap pengeluarannya di sini —
                jurnal <b>Dr Uang Muka Operasional · Cr bank pengeluaran</b> terbentuk per baris, dan totalnya
                menjadi acuan realisasi bon ini.
            </p>

            <div class="mb-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 px-4 py-2.5 dark:border-neutral-700"><div class="text-[11px] uppercase text-neutral-400">Nominal Bon</div><div class="font-mono font-semibold">{{ fmtRp(advance.amount) }}</div></div>
                <div class="rounded-lg border border-neutral-200 px-4 py-2.5 dark:border-neutral-700"><div class="text-[11px] uppercase text-neutral-400">Sudah Dialokasikan</div><div class="font-mono font-semibold text-primary-600">{{ fmtRp(totalAlokasi) }}</div></div>
                <!-- Sisa ini punya DUA arti sekaligus, dan keduanya disebutkan: batas alokasi
                     berikutnya, sekaligus bagian bon yang tetap tinggal sebagai saldo kartu. -->
                <div class="rounded-lg border border-neutral-200 px-4 py-2.5 dark:border-neutral-700"><div class="text-[11px] uppercase text-neutral-400">Belum Dialokasikan</div><div class="font-mono font-semibold">{{ fmtRp(sisaAlokasi) }}</div><div class="text-[10px] text-neutral-400">tetap menjadi saldo kartu</div></div>
            </div>

            <table v-if="adaAlokasi" class="mb-3 w-full text-sm">
                <thead class="border-b border-neutral-200 text-left text-xs uppercase text-neutral-500 dark:border-neutral-700">
                    <tr><th class="py-1.5">Tanggal</th><th class="py-1.5">Keterangan</th><th class="py-1.5">Bank Pengeluaran</th><th class="py-1.5 text-right">Jumlah</th><th class="py-1.5 text-right">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="a in allocations?.rows ?? []" :key="a.id">
                        <td class="py-1.5">{{ fmtDate(a.alloc_date) }}</td>
                        <td class="py-1.5 text-neutral-500">{{ a.description }}</td>
                        <td class="py-1.5 font-mono text-xs text-neutral-500">{{ a.account }}</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(a.amount) }}</td>
                        <td class="py-1.5 text-right">
                            <button v-if="bisaUbah && ! lanjutAlokasi.sudah" type="button" class="inline-flex items-center rounded-lg border border-danger-300 px-2 py-1 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700" title="Hapus alokasi beserta jurnalnya" @click="hapusAlokasi(a)"><Trash2 class="h-3.5 w-3.5" /></button>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-neutral-200 font-semibold dark:border-neutral-700">
                    <tr><td colspan="3" class="py-1.5 text-right">Total Alokasi</td><td class="py-1.5 text-right font-mono">{{ fmtRp(totalAlokasi) }}</td><td></td></tr>
                </tfoot>
            </table>
            <p v-else class="mb-3 rounded-lg bg-amber-50 px-4 py-2.5 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                Belum ada alokasi. Bon ini belum bisa direalisasi — Uang Muka Operasional baru terbentuk dari alokasi.
            </p>

            <form v-if="bisaUbah && ! lanjutAlokasi.sudah" class="grid gap-3 md:grid-cols-5" @submit.prevent="tambahAlokasi">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="alokForm.alloc_date" type="date" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="alokForm.description" type="text" placeholder="mis. Beli ATK" :class="String(alokForm.description ?? '').trim() ? inputCls : inputKurang" /></div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Jumlah *</label>
                    <!-- MoneyInput dengan decimals=2 (kolomnya decimal(20,2) dan servernya
                         membulatkan 2 angka) — MoneyInput bawaan yang bilangan bulat akan
                         menghapus sen diam-diam, dan sisa bon bersen jadi tidak pernah habis
                         teralokasi. Penanda "belum diisi" dipasang sebagai CINCIN pembungkus,
                         bukan menimpa border/background lewat kelas ber-awalan ! — varian
                         gelapnya tidak ikut terbentuk. -->
                    <div :class="Number(alokForm.amount) > 0 ? '' : 'rounded-lg ring-1 ring-amber-400'">
                        <MoneyInput v-model="alokForm.amount" :decimals="2" placeholder="0" />
                    </div>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bank Pengeluaran *</label><SearchSelect :model-value="alokForm.cash_account_id" :options="cashAccounts" placeholder="— pilih rekening —" :class="alokForm.cash_account_id ? '' : pilihKurang" @update:model-value="(v) => alokForm.cash_account_id = v" /></div>
                <div class="md:col-span-5 flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="alokForm.processing || alokKurang.length > 0"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Alokasi</Button>
                    <span v-if="alokKurang.length" class="text-xs text-amber-600 dark:text-amber-400">Lengkapi dulu — {{ alokKurang.join(', ') }}.</span>
                    <span v-else class="text-xs text-neutral-500">Jurnal: Dr Uang Muka Operasional · Cr bank pengeluaran · {{ fmtRp(alokForm.amount) }}</span>
                </div>
            </form>
            <p v-else-if="lanjutAlokasi.sudah" class="text-xs text-neutral-500">
                Bon ini sudah dilanjutkan ke Realisasi — alokasinya dikunci supaya acuan realisasi tidak berubah di belakang.
            </p>
        </div>

        <!-- LANJUT REALISASIKAN BON — pintu satu-satunya menuju Realisasi Bon Sementara. -->
        <div class="card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Lanjut Realisasikan Bon</h2>
                    <p class="mt-1 text-xs text-neutral-500">
                        Selama tombol ini belum ditekan, bon <b>tidak bisa direalisasi</b> — penjaganya ada di server,
                        bukan sekadar tombol yang disembunyikan.
                    </p>
                    <p v-if="lanjutAlokasi.sudah" class="mt-2 inline-flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 class="h-4 w-4" /> Sudah dilanjutkan{{ lanjutAlokasi.pada ? ' pada ' + lanjutAlokasi.pada : '' }}.
                    </p>
                    <p v-else-if="lanjutKurang" class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ lanjutKurang }}</p>
                </div>
                <Link v-if="lanjutAlokasi.sudah" :href="route('bs.advance.show', advance.id)">
                    <Button variant="secondary">Buka Realisasi Bon<template #icon-right><ArrowRight class="h-4 w-4" /></template></Button>
                </Link>
                <Button v-else :disabled="lanjutForm.processing || !! lanjutKurang" @click="lanjut">
                    Lanjut Realisasikan Bon<template #icon-right><ArrowRight class="h-4 w-4" /></template>
                </Button>
            </div>
            <p v-if="lanjutForm.errors.lanjut" class="mt-2 text-xs text-danger-600">{{ lanjutForm.errors.lanjut }}</p>
        </div>
    </div>
</template>
