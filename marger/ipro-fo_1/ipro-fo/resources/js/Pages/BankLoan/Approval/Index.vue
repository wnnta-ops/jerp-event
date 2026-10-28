<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Rincian = { label: string; lama: string; baru: string; berubah: boolean };
type Row = {
    id: number; kpr_id: number; kpr_number: string; customer: string | null; unit: string | null; bank: string | null;
    label: string; rincian: Rincian[]; reason: string | null; status: string;
    requested_by_id: number; requested_by: string | null; requested_at: string | null;
    decided_by: string | null; decided_at: string | null; decision_note: string | null;
};
const props = defineProps<{
    stage: string;
    stageLabel: string;
    stages: Array<{ stage: string; label: string }>;
    pending: Row[];
    selesai: Row[];
    canAct: boolean;
    meId: number;
}>();

const tab = ref<'pending' | 'selesai'>('pending');

// Satu pengajuan diputuskan sekali: catatan opsional saat setuju, wajib saat tolak.
const memutus = ref<{ id: number; aksi: 'setujui' | 'tolak' } | null>(null);
const catatan = ref('');
const proses = ref(false);
function buka(id: number, aksi: 'setujui' | 'tolak') { memutus.value = { id, aksi }; catatan.value = ''; }
function putuskan() {
    if (!memutus.value) return;
    if (memutus.value.aksi === 'tolak' && !catatan.value.trim()) return;
    proses.value = true;
    const r = memutus.value.aksi === 'setujui' ? 'kpr.approval.approve' : 'kpr.approval.reject';
    router.post(route(r, memutus.value.id), { catatan: catatan.value }, {
        preserveScroll: true,
        onSuccess: () => { memutus.value = null; },
        onFinish: () => { proses.value = false; },
    });
}

function fmtDateTime(iso: string | null) { return iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
</script>

<template>
    <div class="space-y-4">
        <h1 class="flex items-center gap-2 text-2xl font-bold">
            <ShieldCheck class="h-6 w-6 text-primary-600" /> Approval {{ stageLabel }}
        </h1>
        <p class="text-sm text-neutral-500">Pengajuan perubahan data Proses KPR tahap <b>{{ stageLabel }}</b>. Data lama tetap berlaku sampai pengajuan disetujui; saat disetujui, perubahan langsung diterapkan.</p>

        <div v-if="stages.length > 1" class="flex flex-wrap gap-1.5">
            <Link v-for="s in stages" :key="s.stage" :href="route('kpr.approval.index', { stage: s.stage })"
                :class="['rounded-lg border px-3 py-1.5 text-xs font-medium', s.stage === stage ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300']">{{ s.label }}</Link>
        </div>

        <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-800">
            <button type="button" @click="tab = 'pending'" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', tab === 'pending' ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500']">Menunggu ({{ pending.length }})</button>
            <button type="button" @click="tab = 'selesai'" :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', tab === 'selesai' ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500']">Sudah Diputuskan</button>
        </div>

        <p v-if="!canAct" class="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:border-warning-700 dark:bg-warning-900/30 dark:text-warning-300">
            Anda bukan approver tahap ini — hanya dapat melihat. Approver diatur di Set Up → Setup Approval KPR.
        </p>

        <div v-for="r in (tab === 'pending' ? pending : selesai)" :key="r.id" class="card p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-semibold">{{ r.label }}</p>
                    <p class="text-sm text-neutral-500">
                        <Link :href="route('kpr.show', r.kpr_id)" class="font-mono text-primary-600 hover:underline">{{ r.kpr_number }}</Link>
                        · {{ r.customer ?? '—' }} · Unit {{ r.unit ?? '—' }} · {{ r.bank ?? '—' }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-400">Diajukan {{ r.requested_by ?? '—' }} · {{ fmtDateTime(r.requested_at) }}</p>
                </div>
                <div v-if="tab === 'pending'" class="flex gap-2">
                    <template v-if="canAct && r.requested_by_id !== meId">
                        <Button size="sm" variant="secondary" @click="buka(r.id, 'tolak')">Tolak</Button>
                        <Button size="sm" variant="success" @click="buka(r.id, 'setujui')">Setujui</Button>
                    </template>
                    <span v-else-if="r.requested_by_id === meId" class="text-xs text-neutral-400">Pengajuan Anda — diputuskan approver lain</span>
                </div>
                <Badge v-else :variant="r.status === 'approved' ? 'success' : 'danger'">{{ r.status === 'approved' ? 'Disetujui' : 'Ditolak' }}</Badge>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-1.5 pr-3">Isian</th><th class="py-1.5 pr-3">Data Sekarang</th><th class="py-1.5">Diubah Menjadi</th></tr></thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="d in r.rincian" :key="d.label" :class="d.berubah ? 'bg-warning-50/60 dark:bg-warning-900/15' : ''">
                            <td class="py-1.5 pr-3 text-neutral-500">{{ d.label }}</td>
                            <td class="py-1.5 pr-3" :class="d.berubah ? 'text-neutral-400 line-through' : ''">{{ d.lama }}</td>
                            <td class="py-1.5" :class="d.berubah ? 'font-semibold text-warning-700 dark:text-warning-300' : ''">{{ d.baru }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm"><span class="text-neutral-500">Alasan:</span> {{ r.reason ?? '—' }}</p>
            <p v-if="tab === 'selesai'" class="mt-1 text-xs text-neutral-500">
                {{ r.status === 'approved' ? 'Disetujui' : 'Ditolak' }} oleh {{ r.decided_by ?? '—' }} · {{ fmtDateTime(r.decided_at) }}<template v-if="r.decision_note"> — {{ r.decision_note }}</template>
            </p>

            <!-- Putuskan (inline) -->
            <div v-if="memutus?.id === r.id" class="mt-3 space-y-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800">
                <label class="block text-sm font-medium">{{ memutus.aksi === 'setujui' ? 'Catatan (opsional)' : 'Alasan penolakan *' }}</label>
                <textarea v-model="catatan" rows="2" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                <div class="flex justify-end gap-2">
                    <Button size="sm" variant="secondary" @click="memutus = null">Batal</Button>
                    <Button size="sm" :variant="memutus.aksi === 'setujui' ? 'success' : 'danger'" :loading="proses"
                        :disabled="memutus.aksi === 'tolak' && !catatan.trim()" @click="putuskan">{{ memutus.aksi === 'setujui' ? 'Setujui & Terapkan' : 'Tolak Pengajuan' }}</Button>
                </div>
            </div>
        </div>

        <EmptyState v-if="!(tab === 'pending' ? pending : selesai).length" :title="tab === 'pending' ? 'Tidak ada pengajuan yang menunggu' : 'Belum ada pengajuan yang diputuskan'" />
    </div>
</template>
