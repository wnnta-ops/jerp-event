<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

type StageState = 'done' | 'rejected' | 'current' | 'waiting' | 'skipped';
type Stage = { label: string; role: string | null; state: StageState; by: string | null; at: string | null; notes: string | null; signature: string | null };

const props = defineProps<{
    approval: { id: number; status: string; position: string; done: number; total: number };
    /** Isi dokumen — Memo Urgent, TTD Berkas, Invoice Hutang, atau Koreksi Budget (lihat ApprovalInboxQuery::detail). */
    doc: {
        number: string | null; title: string | null; type: string;
        fields: Array<{ label: string; value: string | null }>;
        body: string | null;
        files: Array<{ name: string; jenis: string | null; url: string }>;
        links: Array<{ label: string; url: string; tab_baru?: boolean }>;
    };
    stages: Stage[];
    canAct: boolean;
    /** Memo Urgent: isi lembar memo untuk preview & cetak (null untuk dokumen lain). */
    memo: { number: string; rincian?: string | null; date: string | null; department: string | null; pengaju: string | null; supplier: string | null;
        subject: string; body: string; amount: number | null; perusahaan: string; cetak_url: string } | null;
}>();


/* Tinggi iframe preview = tinggi lembar (satu domain, jadi isinya bisa diukur). */
const tinggiPreview = ref(900);
function sesuaikanTinggi(ev: Event) {
    try {
        const doc = (ev.target as HTMLIFrameElement).contentDocument;
        if (doc) tinggiPreview.value = Math.max(600, doc.documentElement.scrollHeight + 8);
    } catch { /* biarkan tinggi bawaan */ }
}

const rejecting = ref(false);
const form = useForm({ action: 'approve' as 'approve' | 'reject', notes: '' });

function decide(action: 'approve' | 'reject') {
    form.action = action;
    form.post(route('approval-inbox.act', props.approval.id), { preserveScroll: true });
}

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
    cancelled: { variant: 'neutral', text: 'Dibatalkan' },
};
</script>

<template>
    <Head :title="(doc.number ?? 'Dokumen') + ' — Approval Inbox'" />
    <div class="mx-auto max-w-5xl space-y-4">
        <Link :href="route('approval-inbox.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Approval Inbox</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-mono text-sm text-neutral-500">{{ doc.type }} · {{ doc.number }}</p>
                <h1 class="text-2xl font-bold">{{ doc.title }}</h1>
            </div>
            <Badge :variant="(statusBadge[approval.status]?.variant as any) ?? 'warning'" dot>{{ statusBadge[approval.status]?.text ?? approval.status }}</Badge>
        </div>

        <!-- Preview lembar Memo Urgent: TTD terisi sampai tahap approval yang sudah dilalui. -->
        <div v-if="memo" class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-2.5 dark:border-neutral-800">
                <p class="text-sm font-semibold">Preview Memo <span class="font-normal text-neutral-500">· TTD sampai tahap {{ approval.position }}</span></p>
                <a :href="memo.cetak_url" target="_blank" rel="noopener" class="inline-flex h-8 items-center rounded-lg border border-neutral-300 px-3 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Cetak / PDF</a>
            </div>
            <!-- Lembar yang sama dengan Cetak / PDF (pdf/memo-urgent.blade.php), tanpa dialog cetak. -->
            <div class="bg-neutral-100 p-3 sm:p-6 dark:bg-neutral-950">
                <iframe :src="memo.cetak_url + '?preview=1'" title="Preview Memo Urgent" class="mx-auto block w-full max-w-4xl rounded bg-white shadow-sm"
                    :style="{ height: tinggiPreview + 'px' }" @load="sesuaikanTinggi" />
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Isi dokumen -->
            <div class="card space-y-4 p-6 lg:col-span-2">
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div v-for="f in doc.fields" :key="f.label">
                        <dt class="text-xs uppercase text-neutral-500">{{ f.label }}</dt>
                        <dd class="font-medium tabular-nums">{{ f.value ?? '—' }}</dd>
                    </div>
                </dl>
                <div v-if="doc.body" class="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                    <p class="mb-2 text-xs uppercase text-neutral-500">Isi Memo</p>
                    <p class="whitespace-pre-line text-sm leading-relaxed">{{ doc.body }}</p>
                </div>
                <div v-if="doc.files.length" class="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                    <p class="mb-2 text-xs uppercase text-neutral-500">Dokumen pendukung ({{ doc.files.length }})</p>
                    <div class="flex flex-wrap gap-2">
                        <a v-for="(f, i) in doc.files" :key="i" :href="f.url" target="_blank" rel="noopener" class="rounded-md border border-neutral-200 px-2 py-1 text-xs text-primary-700 hover:border-primary-300 hover:underline dark:border-neutral-700 dark:text-primary-300">
                            📎 {{ f.jenis ? f.jenis + ' · ' : '' }}{{ f.name }}
                        </a>
                    </div>
                </div>
                <div v-if="doc.links.length" class="flex flex-wrap gap-3 text-sm">
                    <template v-for="l in doc.links" :key="l.url">
                        <a v-if="l.tab_baru" :href="l.url" target="_blank" rel="noopener" class="text-primary-600 hover:underline">{{ l.label }} ↗</a>
                        <Link v-else :href="l.url" class="text-primary-600 hover:underline">{{ l.label }} →</Link>
                    </template>
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
                                <!-- Tahap pembuka (pengaju / pengunggah / tim AP) bukan persetujuan — tampilkan perannya. -->
                                <template v-if="s.state === 'done' && ['Pengajuan', 'Upload berkas', 'Buat invoice'].includes(s.role ?? '')">{{ s.role }} · {{ s.by }}</template>
                                <template v-else>{{ look[s.state].caption }}<template v-if="s.by"> · {{ s.by }}</template></template>
                            </p>
                            <p v-if="s.at" class="text-xs text-neutral-400">{{ waktu(s.at) }}</p>
                            <img v-if="s.signature" :src="s.signature" :alt="'TTD ' + (s.by ?? '')" class="mt-1 h-10 max-w-[140px] object-contain" />
                            <p v-if="s.notes" class="mt-1 rounded bg-neutral-50 px-2 py-1 text-xs italic text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">“{{ s.notes }}”</p>
                        </div>
                    </li>
                </ol>
            </div>
        </div>
    </div>
</template>
