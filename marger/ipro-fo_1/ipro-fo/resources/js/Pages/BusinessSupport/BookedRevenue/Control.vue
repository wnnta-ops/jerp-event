<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileCheck, Stamp, Receipt, ClipboardCheck, ListChecks, Plus, Check } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    type: string;
    title: string;
    subtitle: string;
    extraLabel: string | null;
    extraIsMoney: boolean;
    rows: any;
    filters: { search?: string; status?: string };
    summary: { total: number; byStatus: Record<string, number> };
    // BAST/AJB (union sudah/belum) — opsional (tidak dikirim utk Faktur Pajak):
    belumBookings?: Array<{ booking_id: number; spr: string | null; unit: string | null; customer: string | null }>;
    issueRoute?: string;
    issueNeedsNotary?: boolean;
}>();

const routeName = computed(() => ({ bast: 'bs.booked-revenue.control.bast', ajb: 'bs.booked-revenue.control.ajb', fp: 'bs.booked-revenue.control.fp' } as Record<string, string>)[props.type] ?? 'bs.booked-revenue.control.bast');
const icon = computed(() => ({ bast: FileCheck, ajb: Stamp, fp: Receipt } as Record<string, any>)[props.type] ?? ClipboardCheck);
const isDoc = computed(() => props.type === 'bast' || props.type === 'ajb');
const docLabel = computed(() => (props.type === 'ajb' ? 'AJB' : 'BAST'));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function statusMeta(s: string): { label: string; variant: 'success' | 'info' | 'warning' | 'neutral' | 'danger' } {
    const m: Record<string, any> = {
        active: { label: 'Aktif', variant: 'success' }, issued: { label: 'Terbit', variant: 'success' },
        done: { label: 'Selesai', variant: 'success' }, ready: { label: 'Siap', variant: 'info' },
        draft: { label: 'Draft', variant: 'neutral' }, pending: { label: 'Pending', variant: 'warning' },
        scheduled: { label: 'Terjadwal', variant: 'warning' }, void: { label: 'Void', variant: 'danger' },
        cancelled: { label: 'Batal', variant: 'danger' }, rejected: { label: 'Ditolak', variant: 'danger' },
    };
    return m[s] ?? { label: s, variant: 'neutral' };
}
const statuses = computed(() => Object.keys(props.summary.byStatus ?? {}));
function outstandingDays(d: string): number { return Math.max(0, Math.floor((Date.now() - new Date(d).getTime()) / 86400000)); }

// Filter pencarian (server). Filter Sudah/Belum (client) khusus BAST/AJB.
const f = reactive({ search: props.filters.search ?? '' });
const kindFilter = ref('');
let t: any;
watch(f, (val) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route(routeName.value), { search: val.search || undefined }, { preserveState: true, replace: true }), 300);
});
const displayRows = computed(() => {
    const data = props.rows?.data ?? [];
    if (isDoc.value && kindFilter.value) return data.filter((r: any) => r.kind === kindFilter.value);
    return data;
});

// ── Input Massal / terbitkan (Sudah) BAST/AJB ──
const showModal = ref(false);
const selected = ref<Set<number>>(new Set());
const issueDate = ref(new Date().toISOString().slice(0, 10));
const notary = ref('');
const processing = ref(false);
function openBulk() { selected.value = new Set(); notary.value = ''; issueDate.value = new Date().toISOString().slice(0, 10); showModal.value = true; }
function openSingle(bookingId: number) { selected.value = new Set([bookingId]); notary.value = ''; issueDate.value = new Date().toISOString().slice(0, 10); showModal.value = true; }
function toggle(id: number) { selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); }
const allSelected = computed(() => (props.belumBookings?.length ?? 0) > 0 && props.belumBookings!.every((b) => selected.value.has(b.booking_id)));
function toggleAll() { allSelected.value ? (selected.value = new Set()) : (selected.value = new Set((props.belumBookings ?? []).map((b) => b.booking_id))); }
function submitIssue() {
    if (!selected.value.size || !props.issueRoute) return;
    processing.value = true;
    router.post(route(props.issueRoute), {
        booking_ids: [...selected.value],
        date: issueDate.value,
        notary_name: props.issueNeedsNotary ? (notary.value || undefined) : undefined,
    }, { preserveScroll: true, onSuccess: () => { showModal.value = false; }, onFinish: () => { processing.value = false; } });
}

const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const cardTints = ['text-primary-600', 'text-emerald-600', 'text-amber-600', 'text-sky-600', 'text-rose-600', 'text-violet-600'];
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><component :is="icon" class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
                <p class="text-sm text-neutral-500">{{ subtitle }} — monitoring dokumen per unit &amp; customer.</p>
            </div>
            <Button v-if="isDoc && (belumBookings?.length ?? 0) > 0" @click="openBulk"><template #icon-left><Plus class="h-4 w-4" /></template>Input Massal {{ docLabel }}</Button>
        </div>

        <!-- Ringkasan: total + per status/kind -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><ListChecks class="h-4 w-4" /> Total {{ isDoc ? 'Unit' : 'Dokumen' }}</div>
                <div class="mt-1 text-2xl font-bold text-primary-600">{{ summary.total }}</div>
            </div>
            <div v-for="(s, i) in statuses" :key="s" class="card p-4">
                <div class="text-xs uppercase text-neutral-400">{{ isDoc ? (s === 'sudah' ? 'Sudah ' + docLabel : 'Belum ' + docLabel) : statusMeta(s).label }}</div>
                <div class="mt-1 text-2xl font-bold" :class="cardTints[(i + 1) % cardTints.length]">{{ summary.byStatus[s] }}</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari no. dokumen / SPR / customer / unit…" :class="[inputCls, 'w-72']" />
            <select v-if="isDoc" v-model="kindFilter" :class="inputCls">
                <option value="">Semua ({{ summary.total }})</option>
                <option value="belum">Belum {{ docLabel }}</option>
                <option value="sudah">Sudah {{ docLabel }}</option>
            </select>
        </div>

        <!-- Tabel -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Dokumen</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Unit</th>
                    <th v-if="extraLabel" class="px-4 py-3" :class="extraIsMoney ? 'text-right' : ''">{{ extraLabel }}</th>
                    <th class="px-4 py-3">Status</th>
                    <th v-if="isDoc" class="px-4 py-3 text-right">Outstanding</th>
                    <th v-if="isDoc" class="px-4 py-3 text-center">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in displayRows" :key="(r.kind ?? '') + '-' + (r.id ?? r.booking_id)" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.number ?? '—' }}<div v-if="isDoc && r.spr" class="text-[11px] font-normal text-neutral-400">SPR {{ r.spr }}</div></td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <td v-if="extraLabel" class="px-4 py-2.5" :class="extraIsMoney ? 'text-right tabular-nums' : ''">{{ extraIsMoney ? fmtRp(r.extra) : (r.extra ?? '—') }}</td>
                        <td class="px-4 py-2.5">
                            <Badge v-if="isDoc" :variant="r.kind === 'sudah' ? 'success' : 'warning'" size="sm">{{ r.kind === 'sudah' ? 'Sudah ' + docLabel : 'Belum ' + docLabel }}</Badge>
                            <Badge v-else :variant="statusMeta(r.status).variant" size="sm">{{ statusMeta(r.status).label }}</Badge>
                        </td>
                        <td v-if="isDoc" class="px-4 py-2.5 text-right tabular-nums">
                            <span v-if="r.date" :class="outstandingDays(r.date) > 30 ? 'font-semibold text-rose-600' : 'text-neutral-600 dark:text-neutral-300'">{{ outstandingDays(r.date) }} hari</span>
                            <span v-else class="text-neutral-400">—</span>
                        </td>
                        <td v-if="isDoc" class="px-4 py-2.5 text-center">
                            <button v-if="r.kind === 'belum' && r.booking_id" type="button" @click="openSingle(r.booking_id)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300">
                                <Check class="h-3.5 w-3.5" /> Sudah {{ docLabel }}
                            </button>
                            <span v-else class="text-xs text-neutral-300">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!displayRows.length" :title="'Belum ada ' + title" description="Dokumen akan muncul di sini saat diterbitkan dari modul terkait." />
        </div>

        <div v-if="rows.links?.length > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in rows.links" :key="l.label" :href="l.url" v-html="l.label"
                class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>

        <!-- Modal Input Massal / terbitkan (Sudah) — pilih SPR final (booking) -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                        <h3 class="text-base font-semibold">Terbitkan {{ docLabel }} (Sudah) — pilih SPR final</h3>
                        <p class="text-xs text-neutral-500">Pilih booking (SPR final) dari Marketing &amp; Sales untuk dibuatkan {{ docLabel }} berstatus "Sudah".</p>
                    </div>
                    <div class="grid gap-3 px-5 py-3 sm:grid-cols-2">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal {{ docLabel }}</label><input v-model="issueDate" type="date" :class="[inputCls, 'w-full']" /></div>
                        <div v-if="issueNeedsNotary"><label class="mb-1 block text-xs font-medium text-neutral-500">Notaris</label><input v-model="notary" type="text" placeholder="Nama notaris (opsional)" :class="[inputCls, 'w-full']" /></div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                                <th class="px-2 py-2"><input type="checkbox" :checked="allSelected" @change="toggleAll" class="h-4 w-4 accent-primary-600" /></th>
                                <th class="px-2 py-2">SPR</th><th class="px-2 py-2">Unit</th><th class="px-2 py-2">Customer</th>
                            </tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                <tr v-for="b in (belumBookings ?? [])" :key="b.booking_id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/40" @click="toggle(b.booking_id)">
                                    <td class="px-2 py-2"><input type="checkbox" :checked="selected.has(b.booking_id)" class="h-4 w-4 accent-primary-600" @click.stop="toggle(b.booking_id)" /></td>
                                    <td class="px-2 py-2 font-mono">{{ b.spr ?? '—' }}</td><td class="px-2 py-2 font-mono">{{ b.unit ?? '—' }}</td><td class="px-2 py-2">{{ b.customer ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="!(belumBookings?.length)" class="py-6 text-center text-sm text-neutral-400">Tidak ada SPR final yang belum ber-{{ docLabel }}.</p>
                    </div>
                    <div class="flex items-center justify-between gap-2 border-t border-neutral-200 px-5 py-3 dark:border-neutral-800">
                        <span class="text-xs text-neutral-500">{{ selected.size }} dipilih</span>
                        <div class="flex gap-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button :disabled="!selected.size || processing" :loading="processing" @click="submitIssue">Terbitkan {{ docLabel }} ({{ selected.size }})</Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
