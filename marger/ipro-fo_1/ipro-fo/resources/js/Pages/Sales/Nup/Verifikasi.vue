<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ShieldCheck, ListChecks } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    registrations: any;
    filters: { verification_status: string; from: string | null; to: string | null };
    counts: { pending: number; approved: number; rejected: number };
}>();

const form = reactive({
    verification_status: props.filters.verification_status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v ?? 0));
}
function fmtDate(d: string) {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}
function vBadge(s: string) {
    return ({ approved: 'success', pending: 'warning', rejected: 'danger' } as any)[s] ?? 'neutral';
}
function payBadge(s: string) {
    return ({ paid: 'success', pending: 'info', unpaid: 'neutral', expired: 'danger', failed: 'danger' } as any)[s] ?? 'neutral';
}
function unit(r: any, i: number) {
    return r.form_data?.pilihan_unit?.[i] ?? '-';
}

function applyFilter() {
    router.get(route('nup.verifikasi'), { ...form }, { preserveState: true, preserveScroll: true, replace: true });
}

const opts = { preserveScroll: true };

function decide(r: any, decision: 'approve' | 'pending' | 'reject') {
    const label = { approve: 'APPROVE', pending: 'kembalikan ke PENDING', reject: 'REJECT' }[decision];
    if (!confirm(`Yakin ${label} NUP ${r.nup_number} (${r.customer?.full_name})?`)) return;
    router.post(route('nup.verify', [r.id, decision]), {}, opts);
}
function doDelete(r: any) {
    if (!confirm(`Hapus NUP ${r.nup_number}? Tindakan ini dapat dipulihkan oleh admin.`)) return;
    router.delete(route('nup.destroy', r.id), opts);
}
function doRefund(r: any) {
    const reason = window.prompt(`Alasan refund NUP ${r.nup_number} (min. 5 karakter):`, '');
    if (reason === null) return;
    if (reason.trim().length < 5) { alert('Alasan minimal 5 karakter.'); return; }
    router.post(route('nup.refund', r.id), { reason }, opts);
}
function doPayment(r: any) {
    if (!confirm(`Proses Payment Xendit untuk ${r.nup_number}?`)) return;
    router.post(route('nup.paymentXendit', r.id), {}, opts);
}
function doExpire(r: any) {
    if (!confirm(`Expire link pembayaran ${r.nup_number}?`)) return;
    router.post(route('nup.expireXendit', r.id), {}, opts);
}
function doSendLink(r: any) {
    if (!confirm(`Kirim instruksi pembayaran (email + WhatsApp) ke ${r.customer?.full_name}?`)) return;
    router.post(route('nup.sendLink', r.id), {}, opts);
}
function isPaid(r: any) { return r.payment_status === 'paid'; }

const btn = 'rounded px-2.5 py-1 text-xs font-semibold text-white transition disabled:opacity-40 disabled:cursor-not-allowed';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <ShieldCheck class="h-6 w-6 text-primary-600" /> Verifikasi Data Customer (NUP)
                </h1>
                <p class="text-sm text-neutral-500">Approve / Pending / Reject pendaftaran sebelum masuk Daftar NUP.</p>
            </div>
            <Link :href="route('nup.index')" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                <ListChecks class="h-4 w-4" /> Daftar NUP (Approved)
            </Link>
        </div>

        <!-- Ringkasan -->
        <div class="grid grid-cols-3 gap-3">
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-500">Pending</div>
                <div class="text-2xl font-bold text-warning-600">{{ counts.pending }}</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-500">Approved</div>
                <div class="text-2xl font-bold text-success-600">{{ counts.approved }}</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-500">Rejected</div>
                <div class="text-2xl font-bold text-danger-600">{{ counts.rejected }}</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Status NUP</label>
                <select v-model="form.verification_status" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="">Semua</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Dari</label>
                <input v-model="form.from" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Sampai</label>
                <input v-model="form.to" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button @click="applyFilter" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <!-- Tabel -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3">No.</th>
                        <th class="px-3 py-3">Nama</th>
                        <th class="px-3 py-3">Tanggal Input</th>
                        <th class="px-3 py-3">Cara Bayar</th>
                        <th class="px-3 py-3">Tipe NUP</th>
                        <th class="px-3 py-3 text-right">Nilai</th>
                        <th class="px-3 py-3">Pilihan Unit 1</th>
                        <th class="px-3 py-3">Pilihan Unit 2</th>
                        <th class="px-3 py-3">Pilihan Unit 3</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3">Xendit</th>
                        <th class="px-3 py-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, idx) in registrations.data" :key="r.id" class="align-top">
                        <td class="px-3 py-3 text-neutral-500">{{ (registrations.from ?? 1) + idx }}</td>
                        <td class="px-3 py-3">
                            <div class="font-semibold">{{ r.customer?.full_name }}</div>
                            <div class="font-mono text-[11px] text-neutral-400">{{ r.nup_number }} · #{{ r.queue_no }}</div>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ fmtDate(r.registered_at) }}</td>
                        <td class="px-3 py-3">{{ r.form_data?.cara_bayar ?? '-' }}</td>
                        <td class="px-3 py-3">{{ r.form_data?.jenis_nup ?? r.scheme?.name ?? '-' }}</td>
                        <td class="px-3 py-3 text-right font-mono whitespace-nowrap">{{ fmtRp(r.scheme?.nup_amount) }}</td>
                        <td class="px-3 py-3 max-w-[160px] truncate" :title="unit(r,0)">{{ unit(r, 0) }}</td>
                        <td class="px-3 py-3 max-w-[160px] truncate" :title="unit(r,1)">{{ unit(r, 1) }}</td>
                        <td class="px-3 py-3 max-w-[160px] truncate" :title="unit(r,2)">{{ unit(r, 2) }}</td>
                        <td class="px-3 py-3 space-y-1 whitespace-nowrap">
                            <Badge :variant="isPaid(r) ? 'success' : 'warning'" dot>{{ isPaid(r) ? 'PAID' : 'UNPAID' }}</Badge>
                            <div><Badge :variant="vBadge(r.verification_status)" size="sm">{{ r.verification_status }}</Badge></div>
                            <div v-if="r.form_data?.payment_instructions" class="text-[10px] text-neutral-400">
                                Link terkirim ✓
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-col gap-1">
                                <button :class="[btn, 'bg-rose-500 hover:bg-rose-600']" :disabled="isPaid(r)" @click="doPayment(r)">Payment Xendit</button>
                                <button :class="[btn, 'bg-rose-400 hover:bg-rose-500']" :disabled="isPaid(r)" @click="doExpire(r)">Expire Xendit</button>
                                <button :class="[btn, 'bg-emerald-600 hover:bg-emerald-700']" :disabled="isPaid(r)" @click="doSendLink(r)">Kirim Link</button>
                                <a v-if="r.form_data?.payment_instructions?.wa_link" :href="r.form_data.payment_instructions.wa_link" target="_blank" rel="noopener" :class="[btn, 'bg-green-600 hover:bg-green-700 text-center']">WA Manual</a>
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                <button
                                    :class="[btn, 'bg-blue-600 hover:bg-blue-700']"
                                    :disabled="!isPaid(r) || r.verification_status === 'approved'"
                                    :title="!isPaid(r) ? 'Harus LUNAS dulu sebelum bisa di-Approve' : 'Approve NUP'"
                                    @click="decide(r, 'approve')"
                                >Approve</button>
                                <button :class="[btn, 'bg-amber-500 hover:bg-amber-600']" :disabled="r.verification_status === 'pending'" @click="decide(r, 'pending')">Pending</button>
                                <button :class="[btn, 'bg-slate-500 hover:bg-slate-600']" :disabled="r.verification_status === 'rejected'" @click="decide(r, 'reject')">Reject</button>
                                <button :class="[btn, 'bg-purple-600 hover:bg-purple-700']" @click="doRefund(r)">Refund</button>
                                <button :class="[btn, 'bg-red-700 hover:bg-red-800']" @click="doDelete(r)">Delete</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!registrations.data?.length" title="Tidak ada data verifikasi" description="Belum ada pendaftaran NUP pada filter ini." />
        </div>

        <!-- Pagination sederhana -->
        <div v-if="registrations.last_page > 1" class="flex items-center justify-between text-sm">
            <span class="text-neutral-500">Menampilkan {{ registrations.from }}–{{ registrations.to }} dari {{ registrations.total }}</span>
            <div class="flex gap-2">
                <Link v-if="registrations.prev_page_url" :href="registrations.prev_page_url" preserve-scroll class="rounded-lg border border-neutral-300 px-3 py-1.5 hover:bg-neutral-50 dark:border-neutral-700">← Sebelumnya</Link>
                <Link v-if="registrations.next_page_url" :href="registrations.next_page_url" preserve-scroll class="rounded-lg border border-neutral-300 px-3 py-1.5 hover:bg-neutral-50 dark:border-neutral-700">Berikutnya →</Link>
            </div>
        </div>
    </div>
</template>
