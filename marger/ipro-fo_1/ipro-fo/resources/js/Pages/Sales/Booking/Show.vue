<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    booking: { data: any };
    summary?: { total_tagihan: number; total_terbayar?: number; total_pembayaran: number; total_diskon: number; sisa_tagihan: number; persen_lunas: number };
    dp_memos?: Array<{ id: number; memo_number: string; type: string; type_label: string; status: string; status_label: string; status_color: string; amount: number; reason: string; memo_date: string; created_by: string; can_cancel: boolean }>;
    meta?: { created_at: string | null; updated_at: string | null; prev_id: number | null; prev_label: string | null; next_id: number | null; next_label: string | null };
}>();
const b = props.booking.data;

function fmtDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ── Audit Log viewer ────────────────────────────────────
const showAuditLog = ref(false);
const auditLogs = ref<any[]>([]);
const auditLoading = ref(false);

async function openAuditLog() {
    showAuditLog.value = true;
    auditLoading.value = true;
    try {
        const { data } = await axios.get(route('audit.record'), { params: { type: 'booking', id: b.id } });
        auditLogs.value = data.logs ?? [];
    } finally { auditLoading.value = false; }
}

const showCancel = ref(false);
const cancelForm = useForm({ reason: '', penalty_amount: 0 });

const showFreeDp = ref(false);
const freeDpForm = useForm({ reason: '' });

const showSubsidiDp = ref(false);
const subsidiDpForm = useForm({ amount: 0, reason: '' });

const showCancelMemo = ref<number | null>(null);
const cancelMemoForm = useForm({ reason: '' });

function applyFreeDp() {
    freeDpForm.post(route('memos.free-dp', b.id), {
        onSuccess: () => { showFreeDp.value = false; freeDpForm.reset(); },
        preserveScroll: true,
    });
}

function applySubsidiDp() {
    subsidiDpForm.post(route('memos.subsidi-dp', b.id), {
        onSuccess: () => { showSubsidiDp.value = false; subsidiDpForm.reset(); },
        preserveScroll: true,
    });
}

function cancelMemo(memoId: number) {
    cancelMemoForm.post(route('memos.cancel', memoId), {
        onSuccess: () => { showCancelMemo.value = null; cancelMemoForm.reset(); },
        preserveScroll: true,
    });
}

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function promote() {
    if (confirm('Promote booking ini ke kontrak (PPJB)?')) {
        router.post(route('bookings.promote', b.id));
    }
}

function cancel() {
    cancelForm.post(route('bookings.cancel', b.id), {
        onSuccess: () => { showCancel.value = false; cancelForm.reset(); },
    });
}

const isActive = b.status === 'active';
const isPpjb   = b.status === 'ppjb';
const canCancel = !['cancelled', 'closed', 'lunas'].includes(b.status);
</script>

<template>
    <div class="max-w-5xl mx-auto space-y-4">
        <!-- Top nav bar: Back + Prev/Next -->
        <div class="flex items-center justify-between gap-2">
            <Link :href="route('bookings.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali ke daftar</Link>
            <div class="flex gap-2 text-sm">
                <Link v-if="meta?.prev_id" :href="route('bookings.show', meta.prev_id)" class="text-primary-600 hover:underline" title="Booking sebelumnya">← {{ meta.prev_label }}</Link>
                <Link v-if="meta?.next_id" :href="route('bookings.show', meta.next_id)" class="text-primary-600 hover:underline" title="Booking berikutnya">{{ meta.next_label }} →</Link>
            </div>
        </div>

        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ b.booking_number }}</h1>
                <p class="text-sm text-neutral-500 mt-1">Booked {{ b.booking_date }}</p>
                <p v-if="meta" class="text-xs text-neutral-400 mt-1">
                    Input: {{ fmtDateTime(meta.created_at) }} · Edit: {{ fmtDateTime(meta.updated_at) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="openAuditLog" class="text-xs px-3 py-1.5 rounded-lg border border-neutral-300 dark:border-neutral-700 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition" title="Riwayat perubahan record">
                    🕑 Log
                </button>
                <Badge :variant="b.status_color" dot>{{ b.status_label }}</Badge>
            </div>
        </div>

        <div class="flex gap-2 flex-wrap">
            <Button v-if="isActive" @click="promote">Promote ke PPJB</Button>
            <Button variant="warning" @click="showFreeDp = true">🎁 Free DP</Button>
            <Button variant="warning" @click="showSubsidiDp = true">💰 Subsidi DP</Button>
            <Button v-if="canCancel" variant="danger" @click="showCancel = true">Batalkan Booking</Button>
        </div>

        <!-- Phase 3 — Summary Section (Java Core System Lama parity) -->
        <div v-if="summary" class="card p-5">
            <h2 class="font-semibold mb-3">Ringkasan Pembayaran</h2>
            <dl class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
                <div>
                    <dt class="text-neutral-500">Total Tagihan</dt>
                    <dd class="font-bold text-base mt-1">{{ fmtRp(summary.total_tagihan) }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Total Terbayar</dt>
                    <dd class="font-bold text-base mt-1 text-success-600">{{ fmtRp(summary.total_terbayar ?? (summary.total_pembayaran + summary.total_diskon)) }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Pembayaran</dt>
                    <dd class="font-bold text-base mt-1 text-success-600">{{ fmtRp(summary.total_pembayaran) }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Diskon (Free DP/Subsidi)</dt>
                    <dd class="font-bold text-base mt-1 text-warning-600">{{ fmtRp(summary.total_diskon) }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Sisa Tagihan</dt>
                    <dd class="font-bold text-base mt-1 text-danger-600">{{ fmtRp(summary.sisa_tagihan) }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Pelunasan</dt>
                    <dd class="font-bold text-base mt-1 text-primary-600">{{ summary.persen_lunas }}%</dd>
                </div>
            </dl>
        </div>

        <!-- DP Memos List -->
        <div v-if="dp_memos && dp_memos.length" class="card p-5">
            <h2 class="font-semibold mb-3">Memo Free DP / Subsidi DP</h2>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800 text-xs uppercase text-neutral-500">
                    <tr>
                        <th class="px-3 py-2 text-left">No. Memo</th>
                        <th class="px-3 py-2 text-left">Tanggal</th>
                        <th class="px-3 py-2 text-left">Tipe</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Alasan</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in dp_memos" :key="m.id">
                        <td class="px-3 py-2 font-mono text-xs">{{ m.memo_number }}</td>
                        <td class="px-3 py-2">{{ m.memo_date }}</td>
                        <td class="px-3 py-2">{{ m.type_label }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(m.amount) }}</td>
                        <td class="px-3 py-2"><Badge :variant="(m.status_color as any)">{{ m.status_label }}</Badge></td>
                        <td class="px-3 py-2 text-xs italic text-neutral-500">{{ m.reason }}</td>
                        <td class="px-3 py-2">
                            <button v-if="m.can_cancel" @click="showCancelMemo = m.id" class="text-danger-600 text-xs hover:underline">Cancel</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="card p-6 md:col-span-2">
                <h2 class="font-semibold mb-4">Info Booking</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-neutral-500">Customer</dt><dd class="font-medium">{{ b.customer?.full_name }}</dd></div>
                    <div><dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ b.unit?.unit_code }}</dd></div>
                    <div><dt class="text-neutral-500">Skema</dt><dd>{{ b.payment_scheme?.name }}</dd></div>
                    <div><dt class="text-neutral-500">Sumber</dt><dd>{{ b.booking_source }}</dd></div>
                </dl>

                <div class="border-t mt-4 pt-4">
                    <h3 class="font-semibold mb-3">Detail Harga</h3>
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between"><dt class="text-neutral-500">Harga Dasar:</dt><dd>{{ fmtRp(b.pricing.base) }}</dd></div>
                        <div class="flex justify-between text-danger-600"><dt>Diskon ({{ b.pricing.discount_pct }}%):</dt><dd>- {{ fmtRp(b.pricing.discount_amt) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Gimmick:</dt><dd>{{ fmtRp(b.pricing.gimmick) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">PPN ({{ b.pricing.ppn_pct }}%):</dt><dd>{{ fmtRp(b.pricing.ppn) }}</dd></div>
                        <div class="flex justify-between border-t pt-2 mt-2 font-bold text-lg">
                            <dt>Total:</dt><dd class="text-primary-600">{{ fmtRp(b.pricing.total) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="font-semibold mb-3">Riwayat Status</h2>
                <div class="space-y-3">
                    <div v-for="(h, i) in b.status_histories" :key="i" class="flex gap-3 text-sm">
                        <div class="w-1 bg-primary-200 rounded-full flex-shrink-0"></div>
                        <div>
                            <p><span class="text-neutral-500">{{ h.from ?? 'start' }}</span> → <strong>{{ h.to }}</strong></p>
                            <p class="text-xs text-neutral-500">{{ new Date(h.changed_at).toLocaleString('id-ID') }}</p>
                            <p v-if="h.notes" class="text-xs italic mt-1">{{ h.notes }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-4">Jadwal Pembayaran ({{ b.payment_schedules?.length ?? 0 }} termin)</h2>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-2">No</th>
                        <th class="px-4 py-2">Label</th>
                        <th class="px-4 py-2">Jatuh Tempo</th>
                        <th class="px-4 py-2 text-right">Nominal</th>
                        <th class="px-4 py-2 text-right">Terbayar</th>
                        <th class="px-4 py-2 text-right">Sisa</th>
                        <th class="px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="s in b.payment_schedules" :key="s.id">
                        <td class="px-4 py-2">{{ s.installment_no }}</td>
                        <td class="px-4 py-2">{{ s.label }}</td>
                        <td class="px-4 py-2">{{ s.due_date }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(s.amount) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-success-600">{{ fmtRp(s.paid_amount) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-bold">{{ fmtRp(s.outstanding) }}</td>
                        <td class="px-4 py-2">
                            <Badge :variant="s.status === 'paid' ? 'success' : s.status === 'overdue' ? 'danger' : s.status === 'partial' ? 'warning' : 'neutral'">
                                {{ s.status }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Audit Log modal -->
        <div v-if="showAuditLog" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showAuditLog = false">
            <div class="card p-6 max-w-2xl w-full max-h-[80vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">🕑 Riwayat Perubahan</h2>
                    <button @click="showAuditLog = false" class="text-neutral-400 hover:text-neutral-600">✕</button>
                </div>
                <div v-if="auditLoading" class="text-center py-8 text-neutral-500 text-sm">Memuat…</div>
                <div v-else-if="!auditLogs.length" class="text-center py-8 text-neutral-400 text-sm">Belum ada riwayat perubahan.</div>
                <ul v-else class="space-y-3">
                    <li v-for="log in auditLogs" :key="log.id" class="border-l-2 border-primary-200 pl-3 text-sm">
                        <div class="flex justify-between">
                            <span class="font-semibold capitalize">{{ log.event }}</span>
                            <span class="text-xs text-neutral-400">{{ fmtDateTime(log.created_at) }}</span>
                        </div>
                        <p class="text-xs text-neutral-500">oleh {{ log.user }} <span v-if="log.ip">· {{ log.ip }}</span></p>
                        <ul v-if="log.changes?.length" class="mt-1 text-xs space-y-0.5">
                            <li v-for="(ch, i) in log.changes" :key="i" class="text-neutral-600 dark:text-neutral-300">
                                <span class="font-mono">{{ ch.field }}</span>:
                                <span class="text-danger-500">{{ ch.from ?? '∅' }}</span> →
                                <span class="text-success-600">{{ ch.to ?? '∅' }}</span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Free DP modal -->
        <div v-if="showFreeDp" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showFreeDp = false">
            <div class="card p-6 max-w-md w-full">
                <h2 class="font-semibold mb-3">🎁 Berikan Free DP</h2>
                <p class="text-sm text-neutral-500 mb-4">Semua DP schedule yang belum lunas akan otomatis ter-cover via Diskon. Tindakan ini akan create Memo.</p>
                <div>
                    <label class="label-base">Alasan <span class="text-danger-500">*</span></label>
                    <textarea v-model="freeDpForm.reason" class="input-base h-24" placeholder="Contoh: Promo Akhir Tahun 2026 - Free DP untuk customer existing" required></textarea>
                    <p v-if="freeDpForm.errors.reason" class="text-xs text-danger-500 mt-1">{{ freeDpForm.errors.reason }}</p>
                </div>
                <div class="flex gap-2 justify-end mt-4">
                    <Button variant="secondary" @click="showFreeDp = false">Batal</Button>
                    <Button variant="warning" :loading="freeDpForm.processing" @click="applyFreeDp">Berikan Free DP</Button>
                </div>
            </div>
        </div>

        <!-- Subsidi DP modal -->
        <div v-if="showSubsidiDp" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showSubsidiDp = false">
            <div class="card p-6 max-w-md w-full">
                <h2 class="font-semibold mb-3">💰 Berikan Subsidi DP</h2>
                <p class="text-sm text-neutral-500 mb-4">Subsidi partial — distribusi ke DP schedule unpaid berurutan (FIFO).</p>
                <div class="space-y-3">
                    <div>
                        <label class="label-base">Nominal Subsidi (Rp) <span class="text-danger-500">*</span></label>
                        <input v-model.number="subsidiDpForm.amount" type="number" min="1" class="input-base" required />
                        <p v-if="subsidiDpForm.errors.amount" class="text-xs text-danger-500 mt-1">{{ subsidiDpForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="label-base">Alasan <span class="text-danger-500">*</span></label>
                        <textarea v-model="subsidiDpForm.reason" class="input-base h-20" required></textarea>
                        <p v-if="subsidiDpForm.errors.reason" class="text-xs text-danger-500 mt-1">{{ subsidiDpForm.errors.reason }}</p>
                    </div>
                </div>
                <div class="flex gap-2 justify-end mt-4">
                    <Button variant="secondary" @click="showSubsidiDp = false">Batal</Button>
                    <Button variant="warning" :loading="subsidiDpForm.processing" @click="applySubsidiDp">Berikan Subsidi</Button>
                </div>
            </div>
        </div>

        <!-- Cancel Memo modal -->
        <div v-if="showCancelMemo !== null" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showCancelMemo = null">
            <div class="card p-6 max-w-md w-full">
                <h2 class="font-semibold mb-3">Batalkan Memo</h2>
                <p class="text-sm text-neutral-500 mb-4">Semua allocation dari memo ini akan dibatalkan & schedule kembali ke unpaid.</p>
                <div>
                    <label class="label-base">Alasan Pembatalan <span class="text-danger-500">*</span></label>
                    <textarea v-model="cancelMemoForm.reason" class="input-base h-20" required></textarea>
                </div>
                <div class="flex gap-2 justify-end mt-4">
                    <Button variant="secondary" @click="showCancelMemo = null">Batal</Button>
                    <Button variant="danger" :loading="cancelMemoForm.processing" @click="cancelMemo(showCancelMemo!)">Konfirmasi Cancel</Button>
                </div>
            </div>
        </div>

        <!-- Cancel modal -->
        <div v-if="showCancel" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showCancel = false">
            <div class="card p-6 max-w-md w-full">
                <h2 class="font-semibold mb-3">Batalkan Booking</h2>
                <p class="text-sm text-neutral-500 mb-4">Tindakan ini tidak dapat dibatalkan.</p>
                <div class="space-y-3">
                    <div>
                        <label class="label-base">Alasan Pembatalan <span class="text-danger-500">*</span></label>
                        <textarea v-model="cancelForm.reason" class="input-base h-20" required></textarea>
                        <p v-if="cancelForm.errors.reason" class="text-xs text-danger-500 mt-1">{{ cancelForm.errors.reason }}</p>
                    </div>
                    <div>
                        <label class="label-base">Penalti (Rp)</label>
                        <input v-model.number="cancelForm.penalty_amount" type="number" class="input-base" />
                    </div>
                </div>
                <div class="flex gap-2 justify-end mt-4">
                    <Button variant="secondary" @click="showCancel = false">Batal</Button>
                    <Button variant="danger" :loading="cancelForm.processing" @click="cancel">Konfirmasi Pembatalan</Button>
                </div>
            </div>
        </div>
    </div>
</template>
