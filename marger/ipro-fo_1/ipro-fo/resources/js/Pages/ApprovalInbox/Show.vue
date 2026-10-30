<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

type StageState = 'done' | 'rejected' | 'current' | 'waiting' | 'skipped';
type Stage = { label: string; role: string | null; state: StageState; by: string | null; at: string | null; notes: string | null };

const props = defineProps<{
    approval: { id: number; status: string; position: string; done: number; total: number };
    memo: { number: string; date: string; department: string; subject: string; body: string; amount: number | null; status: string } | null;
    stages: Stage[];
    canAct: boolean;
}>();

const rejecting = ref(false);
const form = useForm({ action: 'approve' as 'approve' | 'reject', notes: '' });

function decide(action: 'approve' | 'reject') {
    form.action = action;
    form.post(route('approval-inbox.act', props.approval.id), { preserveScroll: true });
}

const rupiah = (v: number) => 'Rp ' + v.toLocaleString('id-ID');
const waktu = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '');

const look: Record<StageState, { circle: string; text: string; caption: string }> = {
    done: { circle: 'bg-emerald-500 text-white', text: '', caption: 'Disetujui' },
    rejected: { circle: 'bg-red-500 text-white', text: 'text-red-600', caption: 'Ditolak' },
    current: { circle: 'bg-amber-400 text-white ring-4 ring-amber-100 dark:ring-amber-900/50', text: 'font-semibold', caption: 'Menunggu persetujuan' },
    waiting: { circle: 'bg-neutral-200 text-neutral-500 dark:bg-neutral-700', text: 'text-neutral-500', caption: 'Belum sampai' },
    skipped: { circle: 'bg-neutral-100 text-neutral-400 dark:bg-neutral-800', text: 'text-neutral-400 line-through', caption: 'Tidak dilanjutkan' },
};

const statusBadge: Record<string, { variant: string; text: string }> = {
    pending: { variant: 'warning', text: 'Diproses' },
    in_review: { variant: 'warning', text: 'Diproses' },
    approved: { variant: 'success', text: 'Disetujui' },
    rejected: { variant: 'danger', text: 'Ditolak' },
};
</script>

<template>
    <Head :title="(memo?.number ?? 'Memo') + ' — Approval Inbox'" />
    <div class="mx-auto max-w-5xl space-y-4">
        <Link :href="route('approval-inbox.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Approval Inbox</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-mono text-sm text-neutral-500">{{ memo?.number }}</p>
                <h1 class="text-2xl font-bold">{{ memo?.subject }}</h1>
            </div>
            <Badge :variant="(statusBadge[approval.status]?.variant as any) ?? 'warning'" dot>{{ statusBadge[approval.status]?.text ?? approval.status }}</Badge>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Isi memo -->
            <div class="card space-y-4 p-6 lg:col-span-2">
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs uppercase text-neutral-500">Tanggal</dt><dd class="font-medium">{{ memo?.date }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">Departemen</dt><dd class="font-medium">{{ memo?.department }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">Nominal</dt><dd class="font-medium tabular-nums">{{ memo?.amount != null ? rupiah(memo.amount) : 'Tanpa nominal' }}</dd></div>
                </dl>
                <div class="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                    <p class="mb-2 text-xs uppercase text-neutral-500">Isi Memo</p>
                    <p class="whitespace-pre-line text-sm leading-relaxed">{{ memo?.body }}</p>
                </div>

                <!-- Keputusan -->
                <div v-if="canAct" class="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                    <p class="text-sm font-semibold">Keputusan Anda — tahap {{ approval.position }}</p>
                    <div v-if="rejecting" class="space-y-2">
                        <label class="label-base">Alasan penolakan <span class="text-red-500">*</span></label>
                        <textarea v-model="form.notes" rows="3" class="input-base h-auto w-full py-2" placeholder="Jelaskan kenapa ditolak supaya pengaju bisa memperbaiki." />
                        <p v-if="form.errors.notes" class="text-xs text-red-600">{{ form.errors.notes }}</p>
                        <div class="flex justify-end gap-2">
                            <Button variant="secondary" @click="rejecting = false">Batal</Button>
                            <Button variant="danger" :loading="form.processing" @click="decide('reject')">Tolak Dokumen</Button>
                        </div>
                    </div>
                    <div v-else class="space-y-2">
                        <textarea v-model="form.notes" rows="2" class="input-base h-auto w-full py-2" placeholder="Catatan (opsional)" />
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" @click="rejecting = true">Tolak</Button>
                            <Button variant="success" :loading="form.processing" @click="decide('approve')">Setujui</Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tracker tahap -->
            <div class="card p-6">
                <p class="mb-4 text-sm font-semibold">Alur Approval <span class="font-normal text-neutral-500">· {{ approval.done }}/{{ approval.total }}</span></p>
                <ol class="relative space-y-5">
                    <li v-for="(s, i) in stages" :key="i" class="relative flex gap-3">
                        <span v-if="i < stages.length - 1" class="absolute left-[13px] top-7 h-[calc(100%-4px)] w-0.5 bg-neutral-200 dark:bg-neutral-700" />
                        <span :class="['relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', look[s.state].circle]">
                            {{ s.state === 'done' ? '✓' : s.state === 'rejected' ? '✕' : i + 1 }}
                        </span>
                        <div class="min-w-0 text-sm">
                            <p :class="look[s.state].text">{{ s.label }}</p>
                            <p class="text-xs text-neutral-500">
                                <template v-if="i === 0">Pengajuan · {{ s.by }}</template>
                                <template v-else>{{ look[s.state].caption }}<template v-if="s.by"> · {{ s.by }}</template></template>
                            </p>
                            <p v-if="s.at" class="text-xs text-neutral-400">{{ waktu(s.at) }}</p>
                            <p v-if="s.notes" class="mt-1 rounded bg-neutral-50 px-2 py-1 text-xs italic text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">“{{ s.notes }}”</p>
                        </div>
                    </li>
                </ol>
            </div>
        </div>
    </div>
</template>
