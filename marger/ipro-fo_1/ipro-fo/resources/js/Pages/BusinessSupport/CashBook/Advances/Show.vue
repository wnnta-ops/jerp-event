<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Wallet, ClipboardCheck, Printer, HandCoins, Lock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{ advance: any; expenseAccounts: Array<any>; costCenters: Array<any>; cashAccounts: Array<any>; signers?: Record<string, string>; requiredSigners?: string[]; fullyApproved?: boolean; canApprove?: boolean; canEditDocDate?: boolean;
    /** 'online' = berjenjang di aplikasi · 'offline' = tanda tangan di kertas. */
    approvalMode?: string;
    /** Kartu debit/VA yang boleh dipakai (aktif & belum expired) — hanya untuk Debit VA. */
    creditCards?: Array<{ id: number; label: string; balance: number }>;
    /** Baris Alokasi Kasbon + totalnya + sisa bon yang belum dialokasikan. */
    allocations?: { rows: Array<any>; total: number; sisa: number };
    /** Bon Debit VA model baru — pembayarannya tidak berjurnal, pengeluarannya lewat Alokasi Kasbon. */
    allocationMode?: boolean;
    /** Gerbang "Lanjut Realisasikan Bon": perlu = bon ini melewati tahap alokasi; sudah = tombolnya ditekan. */
    lanjutAlokasi?: { perlu: boolean; sudah: boolean; pada: string | null } }>();
const showForm = ref(false);
// #125: simpan tanggal terima dokumen (hanya finance) tanpa berpindah halaman.
function saveDocDate(e: Event) {
    router.post(route('bs.advance.received-date', props.advance.id), { received_document_date: (e.target as HTMLInputElement).value || null }, { preserveScroll: true, preserveState: false });
}
// #127: persetujuan berjenjang
const approveForm = useForm<any>({ slot: '' });
function approveSlot(slot: string) { approveForm.slot = slot; approveForm.post(route('bs.advance.approve', props.advance.id), { preserveScroll: true }); }
function canApproveSlot(key: string): boolean {
    const req = props.requiredSigners ?? [];
    const idx = req.indexOf(key);
    for (let i = 0; i < idx; i++) { if (!props.advance.approvals?.[req[i]]) return false; }
    return true;
}
const today = new Date().toISOString().slice(0, 10);
// defer_settlement selalu true: penyelesaian sisa/kekurangan ditangani dept Finance (Refund/Reimburse).
const form = useForm<any>({ realized_date: today, defer_settlement: true, lines: [{ expense_account_id: '', cost_center_id: '', description: '', amount: 0 }] });
const settleForm = useForm<any>({ settle_date: today, cash_account_id: '' });
// #49: pembayaran oleh Finance (tanggal transfer + sumber bank/kas) → jurnal terbentuk.
/* Cara pembayaran dipilih Finance saat mencatat. Bawaannya reguler — sama dengan satu-satunya
   cara yang ada sebelum pilihan ini. Debit VA menambah satu syarat: tanggal expired VA. */
const payForm = useForm<any>({ paid_date: today, cash_account_id: '', payment_method: 'reguler', va_expired_date: '', credit_card_id: '' });
function doPay() { payForm.post(route('bs.advance.pay', props.advance.id)); }
/* Debit VA belum lengkap selama tanggal expired-nya kosong. Dihitung tersendiri supaya tombol
   DAN pesan penjelasnya memakai syarat yang sama persis — tombol mati tanpa penjelasan pada
   kartu pembayaran membuat orang menyangka layarnya rusak. */
const vaKurangExpired = computed(() => payForm.payment_method === 'debit_va' && ! payForm.va_expired_date);
/* ...begitu pula nomor kartunya. Permintaan penambahan saldo & masa berlaku yang lahir dari
   pembayaran ini menempel pada kartu tertentu; tanpa kartu, tidak jelas saldo siapa yang
   bertambah. */
const vaKurangKartu = computed(() => payForm.payment_method === 'debit_va' && ! payForm.credit_card_id);
const kartuDipilih = computed(() => (props.creditCards ?? []).find((c) => c.id === Number(payForm.credit_card_id)));
const statusLabel: Record<string, string> = { requested: 'Diajukan', outstanding: 'Outstanding', realized: 'Realized' };
const statusVariant: Record<string, any> = { requested: 'info', outstanding: 'warning', realized: 'success' };

const realized = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));
/* ACUAN PANJARNYA BUKAN SELALU NOMINAL BON. Bon Debit VA model baru menaruh uang di Uang Muka
   Operasional lewat ALOKASI KASBON, jadi yang bisa direalisasi & diselesaikan adalah sebesar
   total alokasinya. Sama persis dengan CashAdvanceService::acuanPanjar() — bila layar memakai
   angka lain, sisa yang ditawarkan di sini akan berbeda dari yang dijurnal server. */
const acuanPanjar = computed(() => (props.allocationMode
    ? Number(props.allocations?.total ?? 0)
    : Number(props.advance.amount)));
const settlement = computed(() => acuanPanjar.value - realized.value); // + kembali / - nombok
function addLine() { form.lines.push({ expense_account_id: '', cost_center_id: '', description: '', amount: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
// Gerbangnya dipasang DUA kali, bukan sekali di tombol saja: menekan Enter di kolom teks
// memicu pengiriman implisit, dan itu tidak melewati tombolnya.
function submit() {
    if (!bisaSimpan.value) return;
    form.post(route('bs.advance.realize', props.advance.id), { onSuccess: () => { showForm.value = false; } });
}

/**
 * Kelengkapan baris realisasi — penentu hidup-matinya tombol "Simpan Realisasi".
 *
 * COST CENTER WAJIB. Ia yang menentukan anggaran mana yang terpakai: nilainya diteruskan ke
 * bs_journal_lines.cost_center_id, lalu dibaca BudgetRealizationService untuk mencocokkan
 * realisasi dengan plafon per (akun, cost center). Baris tanpa cost center tetap jadi beban
 * di GL tetapi TIDAK PERNAH muncul sebagai realisasi di Budget Control — anggarannya seolah
 * tidak pernah terpakai. Server memvalidasinya required juga, jadi gerbang ini tidak lebih
 * longgar maupun lebih ketat daripada servernya.
 *
 * Keterangan diwajibkan meski server membolehkannya kosong. Lebih ketat daripada server tidak
 * masalah: gerbang layar menolong operator melengkapi isian, bukan menggantikan validasi.
 * Yang tidak boleh justru kebalikannya — melonggarkan di layar apa yang akan ditolak server.
 */
function kurangnya(l: any): string[] {
    const k: string[] = [];
    if (!l.expense_account_id) k.push('akun beban');
    if (!l.cost_center_id) k.push('cost center');
    if (!String(l.description ?? '').trim()) k.push('keterangan');
    if (!(Number(l.amount) > 0)) k.push('jumlah');

    return k;
}
const barisKurang = computed(() => form.lines
    .map((l: any, i: number) => ({ no: i + 1, kurang: kurangnya(l) }))
    .filter((b: any) => b.kurang.length));
const bisaSimpan = computed(() => form.lines.length > 0 && barisKurang.value.length === 0);
const alasanKunci = computed(() => (barisKurang.value.length
    ? 'Lengkapi dulu — ' + barisKurang.value.map((b: any) => `baris ${b.no}: ${b.kurang.join(', ')}`).join('; ')
    : 'Simpan realisasi bon sementara'));
function doRefund() { if (confirm('Catat pengembalian sisa panjar ke kas?')) settleForm.post(route('bs.advance.refund', props.advance.id)); }
function doReimburse() { if (confirm('Catat penggantian kekurangan panjar dari kas?')) settleForm.post(route('bs.advance.reimburse', props.advance.id)); }

/* MASUK SALDO — sisa bon Debit VA dikembalikan ke saldo kartunya, bukan ke kas.
   Syaratnya sama persis dengan yang ditegakkan server (payment_method debit_va + ada kartunya +
   sisanya > 0); menawarkan tombol yang pasti ditolak hanya memindahkan penolakannya ke belakang.

   HANYA BON MODEL LAMA (yang pembayarannya dulu berjurnal ke akun kartu, jadi punya journal_id).
   Pada bon model baru sisanya berada di Uang Muka Operasional, bukan di kartu — diselesaikan
   lewat Refund. Bagian bon yang memang belum dialokasikan tetap menjadi sisa saldo kartu dengan
   sendirinya, tanpa tombol apa pun. */
const bisaMasukSaldo = computed(() => props.advance?.payment_method === 'debit_va'
    && !! props.advance?.credit_card_id
    && !! props.advance?.journal_id
    && Number(props.advance?.settlement) > 0);
function doMasukSaldo() {
    if (! confirm(`Masukkan sisa ${fmtRp(Math.abs(Number(props.advance.settlement)))} ke saldo kartu?\n\n`
        + 'Saldonya langsung bisa dipakai untuk pengajuan berikutnya.\n'
        + 'TIDAK ada jurnal tambahan — uangnya memang masih berada di akun kartu itu.')) return;
    settleForm.post(route('bs.advance.masuk-saldo', props.advance.id));
}

/* === ALOKASI KASBON ===
   Pencatatannya pindah ke sub-modul Alokasi Bon Sementara; di layar ini tinggal ringkasannya.
   sisaAlokasi tetap dibaca dari server — dihitung ulang di layar akan melahirkan selisih sen,
   sebab servernya membulatkan ke 2 angka. */
const sisaAlokasi = computed(() => Number(props.allocations?.sisa ?? 0));
/** Bon yang punya tahap alokasi tetapi belum ditekan "Lanjut Realisasikan Bon". */
const terkunciAlokasi = computed(() => !! props.lanjutAlokasi?.perlu && ! props.lanjutAlokasi?.sudah);

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
// Penanda "belum diisi" adalah kelas PENGGANTI, bukan tambahan. Dua kelas Tailwind yang
// bertabrakan (border-neutral-300 vs border-amber-400) tidak ditentukan pemenangnya oleh
// urutan di atribut, melainkan oleh urutan di berkas CSS — menumpuknya akan tampak jalan
// di satu tempat lalu diam-diam gagal di tempat lain.
const inputKurang = 'h-9 w-full rounded-lg border border-amber-400 bg-amber-50/50 px-3 text-sm dark:border-amber-500/60 dark:bg-amber-900/10';
/** Cincin kuning pada SearchSelect — aditif, jadi aman ditumpuk di atas bingkainya. */
const pilihKurang = 'rounded-lg ring-2 ring-amber-300 dark:ring-amber-500/40';

// #80: navigasi kembali yang anti-gagal — pakai router.visit, fallback ke URL literal bila route() gagal resolve.
function backToIndex() {
    let url = '/business-support/bon-sementara';
    try { url = route('bs.advance.index'); } catch (e) { /* ziggy belum siap → pakai URL literal */ }
    router.visit(url);
}
</script>
<template>
    <div class="max-w-5xl space-y-4">
        <button type="button" @click="backToIndex" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali ke Bon Sementara</button>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><Wallet class="h-6 w-6 text-amber-600" /> {{ advance.advance_number }}</h1>
            <div class="flex items-center gap-2">
                <!-- Bon yang SUDAH direalisasi tidak lagi menawarkan cetak pengeluaran: yang berlaku
                     baginya adalah Cetak Realisasi di sebelah. Membiarkan keduanya membuat dua dokumen
                     berbeda beredar untuk satu bon yang sama. -->
                <a v-if="advance.status !== 'realized'" :href="route('bs.advance.print', advance.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> {{ advance.status === 'requested' ? 'Cetak Pengajuan' : 'Cetak Pengeluaran' }}</a>
                <a v-if="advance.status === 'realized'" :href="route('bs.advance.print-realisasi', advance.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-success-300 bg-success-50 px-3 py-1.5 text-sm font-medium text-success-700 hover:bg-success-100 dark:border-success-700/50 dark:bg-success-900/30 dark:text-success-300"><Printer class="h-4 w-4" /> Cetak Realisasi</a>
                <Badge :variant="statusVariant[advance.status] ?? 'neutral'">{{ statusLabel[advance.status] ?? advance.status }}</Badge>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(advance.advance_date) }}</div></div>
            <div><div class="text-neutral-500">Pemegang</div><div class="font-medium">{{ advance.holder }}</div></div>
            <div class="md:col-span-2"><div class="text-neutral-500">Keperluan</div><div class="font-medium">{{ advance.purpose }}</div></div>
            <div><div class="text-neutral-500">Dari Kas/Bank</div><div class="font-medium">{{ advance.cash_account?.name ?? '— (belum dibayar)' }}</div></div>
            <div><div class="text-neutral-500">Nominal</div><div class="font-mono text-lg font-bold text-amber-600">{{ fmtRp(advance.amount) }}</div></div>
            <div v-if="advance.paid_date"><div class="text-neutral-500">Tanggal Transfer (Dibayar Finance)</div><div class="font-medium">{{ fmtDate(advance.paid_date) }}</div></div>
            <!-- Kartu & masa berlaku VA hanya ditampilkan setelah bon BENAR-BENAR dibayar:
                 payment_method sudah berisi 'reguler' sejak bonnya dibuat, jadi menampilkannya
                 lebih awal akan melabeli bon yang belum dibayar. -->
            <div v-if="advance.paid_date && advance.payment_method === 'debit_va'" class="md:col-span-2 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500">Pembayaran Debit VA</div>
                <div class="font-medium">{{ advance.credit_card ? ((advance.credit_card.card_number ? advance.credit_card.card_number + ' — ' : '') + advance.credit_card.name) : '— (kartu tidak tercatat)' }}</div>
                <div class="mt-0.5 text-xs text-neutral-500">Expired VA: {{ advance.va_expired_date ? fmtDate(advance.va_expired_date) : '—' }}</div>
            </div>
            <!-- #125: tanggal terima dokumen — editable (finance) / read-only -->
            <div>
                <div class="text-neutral-500">Tanggal Terima Dokumen</div>
                <input v-if="canEditDocDate" type="date" :value="advance.received_document_date" @change="saveDocDate" :class="[inputCls,'mt-0.5 w-auto']" />
                <div v-else class="font-medium">{{ advance.received_document_date ? fmtDate(advance.received_document_date) : '—' }}</div>
            </div>
        </div>

        <!-- Bon berpersetujuan OFFLINE: jenjangnya memang tidak berjalan di aplikasi. Disebut
             apa adanya — tanpa kartu ini, layarnya hanya kehilangan blok persetujuan dan
             terbaca seperti data yang belum lengkap. -->
        <div v-if="approvalMode === 'offline'" class="card p-5">
            <h2 class="mb-1 flex items-center gap-2 font-semibold"><Lock class="h-4 w-4 text-neutral-400" /> Persetujuan Offline</h2>
            <p class="text-xs text-neutral-500">
                Tanda tangannya dikumpulkan pada <b>formulir cetak</b> (tombol Cetak Pengajuan), bukan di aplikasi.
                Karena itu jenjang persetujuan di sini dimatikan, dan Finance boleh mencatat pembayaran serta
                membentuk jurnalnya tanpa menunggu tanda tangan digital.
            </p>
        </div>

        <!-- #127: persetujuan berjenjang (muncul setelah bon diajukan & disimpan) -->
        <div v-if="signers && requiredSigners?.length" class="card p-5">
            <h2 class="mb-1 font-semibold">Persetujuan Online</h2>
            <p class="mb-3 text-xs text-neutral-400">{{ Number(advance.amount) > 1000000 ? 'Nominal > Rp 1 juta → persetujuan lengkap termasuk Moch Winarto & Boy Arifin.' : 'Nominal ≤ Rp 1 juta → tanpa Moch Winarto & Boy Arifin.' }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="key in requiredSigners" :key="key" class="rounded-lg border border-neutral-200 p-3 text-center text-sm dark:border-neutral-700">
                    <div class="text-xs font-medium text-neutral-500">{{ signers[key] }}</div>
                    <template v-if="advance.approvals?.[key]">
                        <div class="mt-2 flex items-center justify-center gap-1 font-medium text-success-600"><ClipboardCheck class="h-4 w-4" /> {{ advance.approvals[key].name }}</div>
                        <div class="text-[10px] text-neutral-400">{{ String(advance.approvals[key].approved_at).slice(0, 10) }}</div>
                    </template>
                    <button v-else-if="canApprove && canApproveSlot(key)" type="button" :disabled="approveForm.processing" @click="approveSlot(key)" class="mt-2 rounded-lg border border-primary-300 px-3 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:border-primary-700">Setujui</button>
                    <div v-else class="mt-2 text-[10px] text-neutral-400">Menunggu giliran</div>
                </div>
            </div>
        </div>

        <!-- Realisasi (jika sudah) -->
        <div v-if="advance.status === 'realized'" class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold"><ClipboardCheck class="h-5 w-5 text-success-600" /> Realisasi {{ fmtDate(advance.realized_date) }}</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="l in advance.lines" :key="l.id">
                        <td class="py-1.5">{{ l.expense_account?.code }} {{ l.expense_account?.name }}</td>
                        <!-- Cost center ikut ditampilkan supaya terbaca anggaran mana yang terpakai;
                             bon lama tidak punya isian ini, jadi ditandai '—' apa adanya. -->
                        <td class="py-1.5 text-neutral-500">
                            <span v-if="l.cost_center" class="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ l.cost_center.code }} · {{ l.cost_center.name }}</span>
                            <span v-else class="text-neutral-300 dark:text-neutral-600">—</span>
                        </td>
                        <td class="py-1.5 text-neutral-500">{{ l.description }}</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-neutral-200 font-semibold dark:border-neutral-700">
                    <tr><td colspan="3" class="py-1.5 text-right">Total Realisasi</td><td class="py-1.5 text-right font-mono">{{ fmtRp(advance.realized_amount) }}</td></tr>
                    <tr v-if="advance.settlement_status !== 'pending'"><td colspan="3" class="py-1.5 text-right" :class="advance.settlement >= 0 ? 'text-success-600' : 'text-danger-600'">{{ advance.settlement >= 0 ? 'Dikembalikan ke kas (Refund)' : 'Reimburse (diganti dari kas)' }}</td><td class="py-1.5 text-right font-mono">{{ fmtRp(Math.abs(advance.settlement)) }}</td></tr>
                </tfoot>
            </table>
            <div v-if="advance.settlement_status === 'pending'" class="mt-3 space-y-2 rounded-lg bg-amber-50 px-4 py-3 dark:bg-amber-900/20">
                <span class="block text-sm font-medium text-amber-700 dark:text-amber-300">{{ Number(advance.settlement) > 0 ? 'Sisa panjar belum dikembalikan' : 'Kekurangan belum diganti' }}: {{ fmtRp(Math.abs(advance.settlement)) }}</span>
                <div class="flex flex-wrap items-end gap-2">
                    <!-- #89/#93: pilih bank penerima refund / sumber reimburse -->
                    <div class="min-w-56 flex-1">
                        <label class="mb-1 block text-xs font-medium text-neutral-500">{{ Number(advance.settlement) > 0 ? 'Bank Penerima Refund' : 'Bank Sumber Reimburse' }}</label>
                        <SearchSelect :model-value="settleForm.cash_account_id" :options="cashAccounts" placeholder="— default: bank sumber bayar —" @update:model-value="(v) => settleForm.cash_account_id = v" />
                    </div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal</label><input v-model="settleForm.settle_date" type="date" :class="[inputCls,'w-auto']" /></div>
                    <Button v-if="Number(advance.settlement) > 0" size="sm" variant="success" :disabled="settleForm.processing" @click="doRefund">Refund Sisa</Button>
                    <Button v-else size="sm" variant="warning" :disabled="settleForm.processing" @click="doReimburse">Reimburse</Button>
                    <!-- MASUK SALDO — hanya untuk bon Debit VA yang bersisa. Bon reguler tidak
                         punya kartu yang bisa menampung sisanya. Tanpa isian apa pun: nominalnya
                         sudah pasti (sisa bon ini) dan kartunya sudah ditentukan saat pembayaran. -->
                    <Button v-if="bisaMasukSaldo" size="sm" variant="primary" :disabled="settleForm.processing" @click="doMasukSaldo">Masuk Saldo</Button>
                </div>
                <p v-if="bisaMasukSaldo" class="text-[11px] text-amber-700 dark:text-amber-300">
                    <b>Refund Sisa</b> memindahkan sisanya kembali ke bank (berjurnal).
                    <b>Masuk Saldo</b> membiarkannya di kartu {{ advance.credit_card ? ((advance.credit_card.card_number ? advance.credit_card.card_number + ' — ' : '') + advance.credit_card.name) : 'yang dipakai' }}
                    supaya bisa dipakai pengajuan berikutnya — tanpa jurnal tambahan, sebab uangnya memang masih di akun kartu itu.
                </p>
            </div>
            <div v-else-if="advance.settlement_status === 'card'" class="mt-3 text-xs font-medium text-primary-600 dark:text-primary-400">
                ● Sisa panjar {{ fmtRp(Math.abs(Number(advance.settlement))) }} sudah masuk ke saldo kartu — siap dipakai pengajuan berikutnya. Tidak ada jurnal tambahan.
            </div>
            <div v-else-if="advance.settlement_status === 'settled' && Math.abs(Number(advance.settlement)) >= 0.01" class="mt-3 text-xs font-medium text-success-600">● Sisa / kekurangan sudah diselesaikan (Refund/Reimburse)</div>
        </div>

        <!-- #49: Pembayaran oleh Finance (status requested) → jurnal terbentuk -->
        <div v-if="advance.status === 'requested'" class="card p-5">
            <h2 class="mb-1 flex items-center gap-2 font-semibold"><HandCoins class="h-5 w-5 text-amber-600" /> Pembayaran (Dept Finance)</h2>
            <p class="mb-3 text-xs text-neutral-500">
                <b>Reguler</b>: isi tanggal transfer &amp; sumber bank/kas — jurnal <b>Dr Uang Muka Operasional · Cr Bank/Kas</b> terbentuk saat disimpan.
                <b>Debit VA</b>: <b>tidak ada jurnal</b> dan tidak perlu sumber bank/kas — yang terbentuk hanya saldo di kartu/VA-nya (Set Up Debit Card). Uangnya keluar dari bank lewat <b>Alokasi Kasbon</b> sesudah ini.
            </p>
            <!-- Gate: tombol baru aktif setelah SELURUH persetujuan (pengaju → Manager Finance) lengkap. -->
            <!-- Pesan penahan hanya untuk bon ONLINE. Pada bon offline gerbangnya memang
                 terbuka, jadi memasang peringatan "menunggu persetujuan" di sana akan
                 memberi tahu hal yang tidak benar. -->
            <div v-if="!fullyApproved && approvalMode !== 'offline'" class="mb-3 flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-xs font-medium text-amber-700 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300"><Lock class="h-4 w-4 shrink-0" /> Menunggu persetujuan lengkap — tombol "Catat Pembayaran &amp; Buat Jurnal" aktif setelah seluruh tanda tangan (pengaju s.d. Manager Finance) terpenuhi.</div>
            <div v-else-if="approvalMode === 'offline'" class="mb-3 flex items-center gap-2 rounded-lg border border-neutral-300 bg-neutral-50 px-4 py-2.5 text-xs text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-300"><ClipboardCheck class="h-4 w-4 shrink-0" /> Persetujuan Offline — pembayaran boleh dicatat tanpa menunggu tanda tangan di aplikasi. Pastikan formulir cetaknya sudah ditandatangani.</div>
            <form @submit.prevent="doPay" class="grid gap-3 md:grid-cols-2">
                <!-- Cara pembayaran. Reguler = alur seperti selama ini; Debit VA menambah satu
                     syarat, yaitu tanggal expired VA-nya. Jurnalnya sama untuk keduanya —
                     yang berbeda cara uangnya berpindah, bukan akuntansinya. -->
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Cara Pembayaran *</label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm transition-colors"
                               :class="[payForm.payment_method === 'reguler'
                                   ? 'border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-900/20'
                                   : 'border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800',
                                   !fullyApproved ? 'pointer-events-none opacity-50' : '']">
                            <input v-model="payForm.payment_method" type="radio" value="reguler" :disabled="!fullyApproved" class="mt-0.5 accent-primary-600" />
                            <span>
                                <span class="block font-medium">Pembayaran Reguler</span>
                                <span class="mt-0.5 block text-xs text-neutral-500">Transfer / tunai seperti biasa.</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm transition-colors"
                               :class="[payForm.payment_method === 'debit_va'
                                   ? 'border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-900/20'
                                   : 'border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800',
                                   !fullyApproved ? 'pointer-events-none opacity-50' : '']">
                            <input v-model="payForm.payment_method" type="radio" value="debit_va" :disabled="!fullyApproved" class="mt-0.5 accent-primary-600" />
                            <span>
                                <span class="block font-medium">Pembayaran Debit VA</span>
                                <span class="mt-0.5 block text-xs text-neutral-500">Lewat Virtual Account — tanggal expired VA wajib diisi.</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="payForm.errors.payment_method" class="mt-1 text-xs text-danger-500">{{ payForm.errors.payment_method }}</p>
                </div>

                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Transfer *</label><input v-model="payForm.paid_date" type="date" required :disabled="!fullyApproved" :class="[inputCls, !fullyApproved ? 'opacity-50' : '']" /><p v-if="payForm.errors.paid_date" class="mt-1 text-xs text-danger-500">{{ payForm.errors.paid_date }}</p></div>

                <!-- Muncul HANYA pada Debit VA: pada pembayaran reguler tidak ada VA yang bisa
                     kedaluwarsa, dan kolom kosong yang menetap di layar hanya mengundang isian
                     yang tidak berarti apa-apa. -->
                <div v-if="payForm.payment_method === 'debit_va'">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Expired VA <span class="text-danger-500">*</span></label>
                    <input v-model="payForm.va_expired_date" type="date" required :min="payForm.paid_date" :disabled="!fullyApproved"
                           :class="[inputCls, !fullyApproved ? 'opacity-50' : '', vaKurangExpired ? 'border-danger-400 ring-1 ring-danger-400' : '']" />
                    <p v-if="payForm.errors.va_expired_date" class="mt-1 text-xs text-danger-500">{{ payForm.errors.va_expired_date }}</p>
                    <p v-else-if="vaKurangExpired" class="mt-1 text-xs text-danger-500">Wajib diisi sebelum pembayaran dicatat.</p>
                    <p v-else class="mt-1 text-[11px] text-neutral-400">Setelah tanggal ini VA tidak bisa lagi didebit.</p>
                </div>

                <!-- Nomor kartu debit/VA yang didebit. Hanya kartu AKTIF & belum expired yang
                     dikirim server, jadi daftar kosong berarti Set Up Debit Card-nya memang
                     belum disiapkan — dikatakan terang-terangan supaya tidak dikira kolomnya rusak. -->
                <div v-if="payForm.payment_method === 'debit_va'" class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Nomor Kartu Debit / VA <span class="text-danger-500">*</span></label>
                    <SearchSelect :model-value="payForm.credit_card_id" :options="(creditCards ?? []).map((c) => ({ id: c.id, label: c.label }))" option-value="id"
                                  :disabled="!fullyApproved" placeholder="— pilih kartu —"
                                  :class="vaKurangKartu ? 'rounded-lg ring-1 ring-danger-400' : ''"
                                  @update:model-value="(v) => payForm.credit_card_id = v" />
                    <p v-if="payForm.errors.credit_card_id" class="mt-1 text-xs text-danger-500">{{ payForm.errors.credit_card_id }}</p>
                    <p v-else-if="!(creditCards ?? []).length" class="mt-1 text-xs text-danger-500">Belum ada kartu aktif — aktifkan kartunya dulu di <b>Cash Book → Setup → Set Up Debit Card</b>.</p>
                    <p v-else-if="vaKurangKartu" class="mt-1 text-xs text-danger-500">Wajib dipilih sebelum pembayaran dicatat.</p>
                    <p v-else class="mt-1 text-[11px] text-neutral-400">
                        Nominal {{ fmtRp(advance.amount) }} langsung menjadi <b>Penambahan</b> saldo kartu ini pada bulan tanggal transfernya
                        di Set Up Debit Card — tanpa perlu persetujuan tersendiri, sebab pembayarannya sendiri yang menjadi dasarnya.
                        Yang tetap menunggu persetujuan hanya <b>masa berlakunya</b>.
                    </p>
                </div>

                <!-- Sumber Bank/Kas HANYA untuk pembayaran reguler. Pembayaran Debit VA tidak
                     membentuk jurnal sama sekali — banknya baru berkurang saat Alokasi Kasbon
                     dibuat, dan bank itu dipilih di sana. Menampilkan kolom ini pada Debit VA
                     akan menanyakan rekening yang tidak pernah dipakai. -->
                <div v-if="payForm.payment_method !== 'debit_va'"><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber Bank/Kas *</label><SearchSelect :model-value="payForm.cash_account_id" :options="cashAccounts" :disabled="!fullyApproved" placeholder="— pilih rekening —" @update:model-value="(v) => payForm.cash_account_id = v" /><p v-if="payForm.errors.cash_account_id" class="mt-1 text-xs text-danger-500">{{ payForm.errors.cash_account_id }}</p></div>
                <div class="md:col-span-2 flex items-center justify-between rounded-lg bg-amber-50 px-4 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    <span v-if="payForm.payment_method === 'debit_va'">Tidak ada jurnal — saldo masuk ke {{ kartuDipilih?.label ?? 'kartu debit / VA' }}. Jurnalnya terbentuk saat <b>Alokasi Kasbon</b> (Dr Uang Muka Operasional · Cr bank pengeluaran).</span>
                    <span v-else>Jurnal: Dr Uang Muka Operasional · Cr Bank/Kas</span>
                    <span class="font-mono font-bold">{{ fmtRp(advance.amount) }}</span>
                </div>
                <div class="md:col-span-2 flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="payForm.processing || (payForm.payment_method !== 'debit_va' && !payForm.cash_account_id) || !fullyApproved || vaKurangExpired || vaKurangKartu"><template #icon-left><HandCoins class="h-4 w-4" /></template>{{ payForm.payment_method === 'debit_va' ? 'Simpan Pembayaran Debit VA' : 'Catat Pembayaran &amp; Buat Jurnal' }}</Button>
                    <span v-if="vaKurangExpired && fullyApproved" class="text-xs text-danger-500">Isi Tanggal Expired VA dulu.</span>
                    <span v-else-if="vaKurangKartu && fullyApproved" class="text-xs text-danger-500">Pilih Nomor Kartu Debit / VA dulu.</span>
                </div>
            </form>
        </div>

        <!-- ALOKASI KASBON — sekarang sub-modul sendiri (Cash Book → Transaksi Bon Sementara →
             Alokasi Bon Sementara). Di sini tinggal ringkasannya + pintu menuju ke sana, supaya
             operator tahu sudah sampai mana tanpa harus menebak. -->
        <div v-if="lanjutAlokasi?.perlu && advance.status === 'outstanding'" class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="mb-1 flex items-center gap-2 font-semibold"><Wallet class="h-5 w-5 text-primary-600" /> Alokasi Kasbon</h2>
                    <p class="text-xs text-neutral-500">
                        Dicatat di sub-modul <b>Alokasi Bon Sementara</b>. Total alokasi menjadi acuan realisasi bon ini.
                    </p>
                </div>
                <Link :href="route('bs.advance.allocation.show', advance.id)">
                    <Button variant="secondary">Buka Alokasi Kasbon</Button>
                </Link>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 px-4 py-2.5 dark:border-neutral-700"><div class="text-[11px] uppercase text-neutral-400">Nominal Bon</div><div class="font-mono font-semibold">{{ fmtRp(advance.amount) }}</div></div>
                <div class="rounded-lg border border-neutral-200 px-4 py-2.5 dark:border-neutral-700"><div class="text-[11px] uppercase text-neutral-400">Sudah Dialokasikan</div><div class="font-mono font-semibold text-primary-600">{{ fmtRp(allocations?.total ?? 0) }}</div></div>
                <div class="rounded-lg border border-neutral-200 px-4 py-2.5 dark:border-neutral-700"><div class="text-[11px] uppercase text-neutral-400">Belum Dialokasikan</div><div class="font-mono font-semibold">{{ fmtRp(sisaAlokasi) }}</div><div class="text-[10px] text-neutral-400">tetap menjadi saldo kartu</div></div>
            </div>
        </div>

        <!-- Form realisasi (jika outstanding) -->
        <div v-if="advance.status === 'outstanding'" class="card p-5">
            <!-- GERBANG: bon yang punya tahap alokasi baru boleh direalisasi setelah tombol
                 "Lanjut Realisasikan Bon" ditekan di sub-modul Alokasi Bon Sementara. Yang
                 benar-benar menahan ada di CashAdvanceService::realize() — tombol yang mati di
                 sini hanya mencerminkannya, sebab menyembunyikan tombol tidak menahan POST. -->
            <div v-if="!showForm">
                <Button :disabled="terkunciAlokasi" @click="showForm = true"><template #icon-left><ClipboardCheck class="h-4 w-4" /></template>Realisasikan Bon</Button>
                <p v-if="terkunciAlokasi" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                    Bon ini belum dilanjutkan dari Alokasi Kasbon.
                    <Link :href="route('bs.advance.allocation.show', advance.id)" class="font-medium underline">Buka Alokasi Bon Sementara</Link>
                    lalu tekan "Lanjut Realisasikan Bon".
                </p>
            </div>
            <form v-else @submit.prevent="submit" class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Realisasi Bon Sementara</h2>
                    <input :value="today" type="date" readonly title="Tanggal realisasi dikunci pada hari ini" :class="[inputCls,'w-auto cursor-not-allowed bg-neutral-100 dark:bg-neutral-800']" />
                </div>
                <!-- Lebar kolom ditetapkan lewat <colgroup>, bukan diserahkan ke isi sel.
                     Akun Beban paling lebar (38%) karena isinya "kode - nama akun" yang panjang
                     dan paling sering memotong; nama akun yang terpotong membuat operator
                     harus menerka akun mana yang sedang dipilihnya. Tabel di-scroll mendatar
                     pada layar sempit agar kolomnya tidak saling menghimpit. -->
                <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] text-sm">
                    <colgroup>
                        <col style="width:38%"><col style="width:20%"><col style="width:24%">
                        <col style="width:14%"><col style="width:4%">
                    </colgroup>
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr>
                        <th class="py-1">Akun Beban</th>
                        <th class="py-1">Cost Center</th>
                        <th class="py-1">Keterangan</th>
                        <th class="py-1 text-right">Jumlah</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i">
                            <td class="py-1 pr-2"><SearchSelect :model-value="l.expense_account_id" :options="expenseAccounts" placeholder="— cari akun —" no-truncate :class="l.expense_account_id ? '' : pilihKurang" @update:model-value="(v) => l.expense_account_id = v" /></td>
                            <!-- Cost center WAJIB: dialah yang menghubungkan baris ini ke plafon
                                 anggarannya di Budget Control. -->
                            <td class="py-1 pr-2"><SearchSelect :model-value="l.cost_center_id" :options="costCenters" placeholder="— pilih cost center —" :class="l.cost_center_id ? '' : pilihKurang" @update:model-value="(v) => l.cost_center_id = v" /></td>
                            <td class="py-1 pr-2"><input v-model="l.description" :class="String(l.description ?? '').trim() ? inputCls : inputKurang" /></td>
                            <td class="py-1 pr-2"><input v-model.number="l.amount" type="number" step="1" min="0" :class="[Number(l.amount) > 0 ? inputCls : inputKurang,'text-right']" /></td>
                            <td class="py-1 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length===1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <p class="text-xs text-neutral-400">
                    <b>Cost Center wajib diisi</b> — dialah yang menghubungkan tiap baris ke plafon anggarannya,
                    sehingga realisasi ini langsung terbaca di <b>Budget Control</b>. Baris tanpa cost center akan
                    tetap menjadi beban di GL tetapi anggarannya seolah tidak pernah terpakai.
                </p>
                <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                <div class="space-y-1 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
                    <!-- Acuannya total ALOKASI untuk bon Debit VA model baru: hanya sebesar itulah
                         Uang Muka Operasional yang benar-benar terbentuk. Menampilkan nominal bon
                         di sini akan menjanjikan sisa refund atas uang yang belum keluar dari bank. -->
                    <div class="flex justify-between"><span>{{ allocationMode ? 'Total Alokasi Kasbon' : 'Nominal' }}</span><span class="font-mono">{{ fmtRp(acuanPanjar) }}</span></div>
                    <div v-if="allocationMode" class="flex justify-between text-xs text-neutral-400"><span>Nominal bon (sisanya tetap di saldo kartu)</span><span class="font-mono">{{ fmtRp(advance.amount) }}</span></div>
                    <div class="flex justify-between"><span>Total Realisasi (beban)</span><span class="font-mono">{{ fmtRp(realized) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 font-semibold dark:border-neutral-700" :class="settlement >= 0 ? 'text-success-700' : 'text-danger-600'"><span>{{ settlement >= 0 ? 'Sisa dikembalikan ke kas (Refund)' : 'Kekurangan (Reimburse)' }}</span><span class="font-mono">{{ fmtRp(Math.abs(settlement)) }}</span></div>
                </div>
                <p class="text-xs text-neutral-400">Penyelesaian sisa/kekurangan ditangani dept Finance lewat tombol <b>Refund</b> / <b>Reimburse</b> setelah dana ditransfer.</p>
                <!-- Tombol mati selama masih ada baris yang belum lengkap. Alasannya WAJIB
                     terbaca: tombol mati tanpa keterangan membuat operator menebak-nebak
                     apa yang kurang, apalagi bila barisnya sudah banyak. -->
                <p v-if="barisKurang.length" class="text-xs text-amber-700 dark:text-amber-400">
                    Lengkapi dulu sebelum bisa disimpan —
                    <template v-for="(b, i) in barisKurang" :key="b.no"><span v-if="i">; </span><b>baris {{ b.no }}</b>: {{ b.kurang.join(', ') }}</template>.
                </p>
                <div class="flex gap-2">
                    <span :title="alasanKunci"><Button type="submit" :disabled="form.processing || !bisaSimpan">Simpan Realisasi</Button></span>
                    <button type="button" @click="showForm = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                </div>
            </form>
        </div>
    </div>
</template>
