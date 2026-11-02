<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Stage = { label: string; state: 'done' | 'rejected' | 'current' | 'waiting' | 'skipped' };
type Row = {
    id: number; number: string | null; subject: string | null; department: string | null;
    amount: number | null; type: string; status: string; submitter: string | null;
    submitted_at: string | null; position: string; done: number; total: number; stages: Stage[];
};

const props = defineProps<{
    tab: 'perlu' | 'pengajuan' | 'riwayat';
    counts: Record<string, number>;
    rows: Row[];
}>();

const tabs = [
    { key: 'perlu', label: 'Perlu Persetujuan Saya' },
    { key: 'pengajuan', label: 'Pengajuan Saya' },
    { key: 'riwayat', label: 'Riwayat Keputusan' },
] as const;

const emptyText: Record<string, string> = {
    perlu: 'Tidak ada dokumen yang menunggu persetujuan Anda.',
    pengajuan: 'Anda belum mengajukan dokumen.',
    riwayat: 'Anda belum pernah menyetujui atau menolak dokumen.',
};

function go(tab: string) {
    router.get(route('approval-inbox.index'), { tab }, { preserveState: true, replace: true });
}

const rupiah = (v: number) => 'Rp ' + v.toLocaleString('id-ID');
const tanggal = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

const statusBadge: Record<string, { variant: string; text: string }> = {
    pending: { variant: 'warning', text: 'Diproses' },
    in_review: { variant: 'warning', text: 'Diproses' },
    approved: { variant: 'success', text: 'Disetujui' },
    rejected: { variant: 'danger', text: 'Ditolak' },
    cancelled: { variant: 'neutral', text: 'Dibatalkan' },
};

const dot: Record<Stage['state'], string> = {
    done: 'bg-emerald-500',
    rejected: 'bg-red-500',
    current: 'bg-amber-400 ring-2 ring-amber-200 dark:ring-amber-900',
    waiting: 'bg-neutral-300 dark:bg-neutral-700',
    skipped: 'bg-neutral-200 dark:bg-neutral-800',
};
</script>

<template>
    <Head title="Approval Inbox" />
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Approval Inbox</h1>
                <p class="text-sm text-neutral-500">Memo Internal, TTD Berkas & Invoice Hutang — Accounting → Finance → GM → Direktur → MW / BA</p>
            </div>
        </div>

        <div class="flex gap-2 overflow-x-auto border-b border-neutral-200 dark:border-neutral-800">
            <button
                v-for="t in tabs" :key="t.key" @click="go(t.key)"
                :class="['whitespace-nowrap px-4 py-2 text-sm font-medium', props.tab === t.key ? 'border-b-2 border-primary-600 text-primary-600' : 'text-neutral-500 hover:text-neutral-700']"
            >
                {{ t.label }}
                <span class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs dark:bg-neutral-800">{{ counts[t.key] ?? 0 }}</span>
            </button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No. Dokumen</th>
                        <th class="px-4 py-3">Perihal</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Diajukan</th>
                        <th class="px-4 py-3">Posisi Approval</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-semibold">{{ r.number ?? '#' + r.id }}</p>
                            <p class="text-xs text-neutral-500">{{ r.type }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ r.subject }}</p>
                            <p class="text-xs text-neutral-500">{{ r.department }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ r.amount != null ? rupiah(r.amount) : '—' }}</td>
                        <td class="px-4 py-3">
                            <p>{{ r.submitter ?? '—' }}</p>
                            <p class="text-xs text-neutral-500">{{ tanggal(r.submitted_at) }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1" :title="r.stages.map((s) => s.label).join(' → ')">
                                <span v-for="(s, i) in r.stages" :key="i" :class="['h-2.5 w-2.5 rounded-full', dot[s.state]]" />
                            </div>
                            <p class="mt-1 text-xs text-neutral-500">{{ r.position }} · {{ r.done }}/{{ r.total }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="(statusBadge[r.status]?.variant as any) ?? 'warning'" dot>{{ statusBadge[r.status]?.text ?? r.status }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('approval-inbox.show', r.id)" class="text-sm text-primary-600 hover:underline">
                                {{ props.tab === 'perlu' ? 'Periksa →' : 'Detail →' }}
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" :title="emptyText[props.tab]" />
        </div>
    </div>
</template>
