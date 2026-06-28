<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, FileWarning, Printer, ShieldCheck, Clock, ShieldX } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

type ApprovalStep = { id: number; level: number; status: string; notes: string | null; acted_at: string | null };
type Approval = { id: number; status: string; steps: ApprovalStep[]; is_approved: boolean };

const props = defineProps<{
    sp: any;
    units?: string;
    approval?: Approval | null;
    isApproved?: boolean;
}>();

function colorFor(level: string) { return level === 'SP3' ? 'danger' : level === 'SP2' ? 'warning' : 'info'; }
const levelLabel: Record<string, string> = { SP1: 'SP1 — Reminder', SP2: 'SP2 — Warning', SP3: 'SP3 — Final Notice' };
const catColor: Record<string, any> = { berat: 'danger', sedang: 'warning', ringan: 'info' };
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
function fmtDateTime(d: string | null) { return d ? new Date(d).toLocaleString('id-ID') : ''; }

const approvalStatus = computed(() => props.approval?.status ?? null);
const canPrint = computed(() => props.isApproved === true || approvalStatus.value === 'approved');
const isRejected = computed(() => approvalStatus.value === 'rejected');
function approvalVariant(s: string | null): 'success' | 'danger' | 'warning' {
    return s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : 'warning';
}
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('sp.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Surat Peringatan</Link>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><FileWarning class="h-6 w-6 text-amber-600" /> {{ sp.sp_number }}</h1>
            <div class="flex items-center gap-2">
                <a v-if="canPrint" :href="route('sp.print', sp.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> Cetak SP</a>
                <span v-else class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-1.5 text-sm font-medium text-neutral-400 cursor-not-allowed dark:border-neutral-700 dark:bg-neutral-800/50" :title="isRejected ? 'Approval finance ditolak' : 'Menunggu persetujuan finance'"><Printer class="h-4 w-4" /> Cetak SP</span>
                <Badge :variant="colorFor(sp.level)" dot>{{ sp.level }}</Badge>
                <Badge variant="neutral" class="capitalize">{{ sp.status }}</Badge>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <div><div class="text-neutral-500">Level</div><div class="font-medium">{{ levelLabel[sp.level] ?? sp.level }}</div></div>
            <div><div class="text-neutral-500">Status</div><div class="font-medium capitalize">{{ sp.status }}</div></div>
            <div>
                <div class="text-neutral-500">Customer</div>
                <div class="font-medium"><span class="font-mono text-xs text-neutral-400">{{ sp.customer?.code }}</span> {{ sp.customer?.full_name ?? ('#' + sp.customer_id) }}</div>
            </div>
            <div><div class="text-neutral-500">Unit (Rumah/Ruha)</div><div class="font-medium font-mono">{{ units ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Telepon</div><div class="font-medium">{{ sp.customer?.phone ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Tanggal Terbit</div><div class="font-medium">{{ fmtDate(sp.issued_at) }}</div></div>
            <div><div class="text-neutral-500">Batas Waktu (Deadline)</div><div class="font-medium">{{ fmtDate(sp.deadline_at) }}</div></div>
            <div><div class="text-neutral-500">Ditandatangani Oleh</div><div class="font-medium">{{ sp.signer?.name ?? '—' }}</div></div>
        </div>

        <!-- #118: Status persetujuan finance -->
        <div class="card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">Persetujuan Finance</h2>
                <Badge v-if="approvalStatus" :variant="approvalVariant(approvalStatus)" dot class="capitalize">{{ approvalStatus }}</Badge>
                <Badge v-else variant="neutral" dot>belum diajukan</Badge>
            </div>

            <div v-if="canPrint" class="flex items-center gap-2 rounded-lg bg-success-500/10 px-3 py-2 text-sm text-success-600 dark:text-success-500">
                <ShieldCheck class="h-5 w-5 shrink-0" />
                <span>Disetujui finance{{ sp.signer?.name ? ' oleh ' + sp.signer.name : '' }}. SP siap dicetak.</span>
            </div>
            <div v-else-if="isRejected" class="flex items-center gap-2 rounded-lg bg-danger-500/10 px-3 py-2 text-sm text-danger-600 dark:text-danger-500">
                <ShieldX class="h-5 w-5 shrink-0" />
                <span>Approval finance ditolak. SP tidak dapat dicetak.</span>
            </div>
            <div v-else class="flex items-center gap-2 rounded-lg bg-warning-500/10 px-3 py-2 text-sm text-warning-600 dark:text-warning-500">
                <Clock class="h-5 w-5 shrink-0" />
                <span>Menunggu persetujuan finance.</span>
            </div>

            <div v-if="approval?.steps?.length" class="mt-4 space-y-3">
                <div v-for="s in approval.steps" :key="s.id" class="flex items-center gap-3 text-sm">
                    <div :class="['flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold',
                        s.status === 'approved' ? 'bg-success-500 text-white' :
                        s.status === 'rejected' ? 'bg-danger-500 text-white' : 'bg-neutral-200 text-neutral-500 dark:bg-neutral-700']">
                        {{ s.level }}
                    </div>
                    <div class="flex-1">
                        <p class="font-medium">Finance Manager</p>
                        <p class="text-xs capitalize text-neutral-500">{{ s.status }} {{ s.acted_at ? '· ' + fmtDateTime(s.acted_at) : '' }}</p>
                        <p v-if="s.notes" class="text-xs italic text-neutral-500">"{{ s.notes }}"</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tunggakan terkait -->
        <div v-if="sp.tunggakan" class="card p-5">
            <h2 class="mb-3 font-semibold">Tunggakan Terkait</h2>
            <div class="grid gap-4 text-sm md:grid-cols-4">
                <div><div class="text-neutral-500">Tipe Transaksi</div><div class="font-medium capitalize">{{ sp.tunggakan.transaction_type }}</div></div>
                <div><div class="text-neutral-500">Kategori</div><div><Badge :variant="catColor[sp.tunggakan.category] ?? 'neutral'" dot>{{ sp.tunggakan.category }}</Badge></div></div>
                <div><div class="text-neutral-500">Termin Tunggak</div><div class="font-medium">{{ sp.tunggakan.term_overdue_count }}</div></div>
                <div><div class="text-neutral-500">Total Tunggakan</div><div class="font-mono font-bold text-danger-600">{{ fmtRp(sp.tunggakan.total_amount) }}</div></div>
            </div>
        </div>

        <p class="text-xs text-neutral-400">Surat Peringatan diterbitkan dari data Tunggakan customer. SP1 (reminder) → SP2 (warning) → SP3 (final notice).</p>
    </div>
</template>
