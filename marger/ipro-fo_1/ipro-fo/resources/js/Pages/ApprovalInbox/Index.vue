<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck, Search, X, FileText, ChevronDown } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Stage = { label: string; state: 'done' | 'rejected' | 'current' | 'waiting' | 'skipped' };
type Row = {
    id: number; number: string | null; subject: string | null; department: string | null;
    amount: number | null; type: string; status: string; submitter: string | null;
    submitted_at: string | null; position: string; done: number; total: number; stages: Stage[];
    /** Alokasi / koreksi budget turunan invoice (diputuskan bersama invoicenya). */
    turunan?: Array<{ jenis: string; id: number; doc_no: string; dari: string | null; ke: string | null; nilai: number }>;
};

const props = defineProps<{
    tab: 'perlu' | 'pengajuan' | 'riwayat';
    counts: Record<string, number>;
    rows: Row[];
    filters: { q: string; jenis: string; status: string; dari: string; sampai: string };
    pilihanJenis: Record<string, string>;
    canSubmit: boolean;
}>();

/* Cari & filter (sama dengan daftar Memo Urgent) — dikirim ke server, tab tetap. */
const f = ref({ ...props.filters });
let jeda: ReturnType<typeof setTimeout> | null = null;
function saring(segera = false) {
    if (jeda) clearTimeout(jeda);
    const kirim = () => router.get(route('approval-inbox.index'), { tab: props.tab, ...Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')) },
        { preserveState: true, preserveScroll: true, replace: true });
    segera ? kirim() : (jeda = setTimeout(kirim, 400));
}
watch(() => f.value.q, () => saring());
watch(() => [f.value.jenis, f.value.status, f.value.dari, f.value.sampai], () => saring(true));
const adaFilter = () => Object.values(f.value).some((v) => v !== '');
function reset() { f.value = { q: '', jenis: '', status: '', dari: '', sampai: '' }; }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

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
    router.get(route('approval-inbox.index'), { tab, ...Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')) }, { preserveState: true, replace: true });
}

const rupiah = (v: number) => 'Rp ' + v.toLocaleString('id-ID');
// Baris invoice yang turunannya (alokasi / koreksi) sedang dibuka.
const terbuka = ref<Record<number, boolean>>({});
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
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-primary-600" /> Approval Inbox</h1>
                <p class="text-sm text-neutral-500">Memo Urgent, TTD Berkas, Invoice Hutang, Alokasi &amp; Koreksi Budget — alur tiap dokumen diatur di System › Approval Chain.</p>
            </div>
            <Link v-if="canSubmit" :href="route('approval-inbox.memo')"><Button><template #icon-left><FileText class="h-4 w-4" /></template>Memo Urgent Saya</Button></Link>
        </div>

        <div class="card space-y-3 p-4">
            <div class="flex gap-1 overflow-x-auto border-b border-neutral-200 dark:border-neutral-800">
                <button v-for="t in tabs" :key="t.key" type="button" @click="go(t.key)"
                    :class="['-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', props.tab === t.key ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200']">
                    {{ t.label }} <span class="ml-1 rounded-full bg-neutral-100 px-1.5 text-[11px] dark:bg-neutral-800">{{ counts[t.key] ?? 0 }}</span>
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[16rem] flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="f.q" type="search" placeholder="Cari no. dokumen, perihal, pengaju…" :class="[inputCls, 'w-full pl-9']" />
                </div>
                <select v-model="f.jenis" :class="inputCls" title="Jenis dokumen">
                    <option value="">Semua jenis dokumen</option>
                    <option v-for="(label, k) in pilihanJenis" :key="k" :value="k">{{ label }}</option>
                </select>
                <select v-model="f.status" :class="inputCls" title="Status">
                    <option value="">Semua status</option>
                    <option value="diproses">Diproses</option>
                    <option value="disetujui">Disetujui</option>
                    <option value="ditolak">Ditolak</option>
                    <option value="dibatalkan">Dibatalkan</option>
                </select>
                <label class="flex items-center gap-1.5 text-xs text-neutral-500">Dari <input v-model="f.dari" type="date" :class="inputCls" /></label>
                <label class="flex items-center gap-1.5 text-xs text-neutral-500">s.d. <input v-model="f.sampai" type="date" :class="inputCls" /></label>
                <button v-if="adaFilter()" type="button" class="inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-danger-600" @click="reset"><X class="h-3.5 w-3.5" /> Reset</button>
            </div>
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
                    <template v-for="r in rows" :key="r.id">
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-semibold">{{ r.number ?? '#' + r.id }}</p>
                            <p class="text-xs text-neutral-500">{{ r.type }}</p>
                            <button v-if="r.turunan?.length" type="button" class="mt-1 inline-flex items-center gap-0.5 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700 hover:bg-primary-100 dark:bg-primary-950/40 dark:text-primary-300"
                                @click="terbuka[r.id] = !terbuka[r.id]">
                                + {{ r.turunan.length }} alokasi/koreksi <ChevronDown :class="['h-3 w-3 transition', terbuka[r.id] ? 'rotate-180' : '']" />
                            </button>
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
                    <!-- Turunan: alokasi / koreksi budget yang ikut disetujui bersama invoice ini. -->
                    <tr v-for="t in (terbuka[r.id] ? r.turunan ?? [] : [])" :key="r.id + t.jenis + t.id" class="bg-neutral-50/70 text-xs dark:bg-neutral-800/30">
                        <td class="py-2 pl-8 pr-4"><span class="text-neutral-400">↳</span> <span class="font-mono">{{ t.doc_no }}</span><div class="pl-4 capitalize text-neutral-500">{{ t.jenis }} budget</div></td>
                        <td class="px-4 py-2" colspan="1">{{ t.dari ? t.dari + ' → ' : '' }}{{ t.ke }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rupiah(t.nilai) }}</td>
                        <td class="px-4 py-2 text-neutral-500" colspan="4">Ikut diputuskan bersama invoice ini</td>
                    </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" :title="adaFilter() ? 'Tidak ada dokumen yang cocok dengan pencarian / filter.' : emptyText[props.tab]" />
        </div>
    </div>
</template>
