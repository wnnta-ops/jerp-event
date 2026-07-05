<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CheckSquare, CheckCircle2, XCircle, Undo2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    journals: any; sources: Record<string, string>; statuses: Record<string, string>;
    statusTab: string; counts: { final: number; posted: number }; canApprove: boolean; filters: Record<string, any>;
}>();

const source = ref(props.filters.source ?? '');
const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const isFinal = computed(() => props.statusTab !== 'posted');

function reload(extra: Record<string, any> = {}) {
    router.get(route('bs.gl.journal.approval'), {
        status: props.statusTab, source: source.value || undefined, search: search.value || undefined,
        date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined, ...extra,
    }, { preserveState: true, replace: true });
}
function switchTab(s: string) { if (s !== props.statusTab) { selected.value = []; reload({ status: s }); } }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const rows = computed(() => props.journals.data ?? []);
const selected = ref<number[]>([]);
function isSel(id: number) { return selected.value.includes(id); }
function toggle(id: number) { const i = selected.value.indexOf(id); if (i >= 0) selected.value.splice(i, 1); else selected.value.push(id); }
const allSel = computed(() => rows.value.length > 0 && rows.value.every((j: any) => isSel(j.id)));
function toggleAll() { selected.value = allSel.value ? [] : rows.value.map((j: any) => j.id); }

const processing = ref(false);
function runBatch(routeName: string, ids: number[], confirmMsg: string) {
    if (!ids.length) { alert('Pilih minimal 1 voucher.'); return; }
    if (!confirm(confirmMsg)) return;
    processing.value = true;
    router.post(route(routeName), { ids }, { preserveScroll: true, onSuccess: () => { selected.value = []; }, onFinish: () => { processing.value = false; } });
}
function batchApprove() { runBatch('bs.gl.journal.approve-batch', selected.value, `Approve ${selected.value.length} voucher? Jurnal menjadi efektif di GL.`); }
function batchCancel() { runBatch('bs.gl.journal.cancel-batch', selected.value, `Batalkan approval ${selected.value.length} voucher? Jurnal kembali ke Final (keluar dari GL efektif).`); }
function approveOne(id: number) { runBatch('bs.gl.journal.approve-batch', [id], 'Approve voucher ini? Jurnal menjadi efektif di GL.'); }
function cancelOne(id: number) { runBatch('bs.gl.journal.cancel-batch', [id], 'Batalkan approval voucher ini? Jurnal kembali ke Final.'); }

const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CheckSquare class="h-6 w-6 text-primary-600" /> Approval Jurnal</h1>
            <p class="text-sm text-neutral-500">Voucher berstatus <b>Final</b> (menunggu approval) dari semua sumber. Approve agar <b>efektif di GL</b>, atau batalkan approval voucher yang sudah Approved.</p>
        </div>

        <!-- Tab status -->
        <div class="flex w-fit gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
            <button @click="switchTab('final')" class="rounded px-3 py-1.5 font-medium transition" :class="isFinal ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Menunggu Approval <span class="opacity-70">({{ counts.final }})</span></button>
            <button @click="switchTab('posted')" class="rounded px-3 py-1.5 font-medium transition" :class="!isFinal ? 'bg-success-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Sudah Approved <span class="opacity-70">({{ counts.posted }})</span></button>
        </div>

        <!-- Filter -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label><input v-model="dateFrom" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label><input v-model="dateTo" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber</label><select v-model="source" :class="inputCls"><option value="">Semua Jenis</option><option v-for="(label, val) in sources" :key="val" :value="val">{{ label }}</option></select></div>
            <div class="flex-1 min-w-[160px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="No. Voucher / Keterangan / Ref" :class="[inputCls, 'w-full']" @keyup.enter="reload()" /></div>
            <button @click="reload()" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <!-- Toolbar aksi batch (ala legacy) -->
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ journals.total }} voucher<span v-if="selected.length"> · <span class="font-semibold text-primary-600">{{ selected.length }} dipilih</span></span></p>
            <div class="flex gap-2">
                <Button v-if="isFinal" size="sm" variant="success" :disabled="!canApprove" :loading="processing" @click="batchApprove"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Batch Approval ({{ selected.length }})</Button>
                <Button v-else size="sm" variant="danger" :disabled="!canApprove" :loading="processing" @click="batchCancel"><template #icon-left><XCircle class="h-4 w-4" /></template>Batch Cancel Approval ({{ selected.length }})</Button>
            </div>
        </div>
        <p v-if="!canApprove" class="text-xs text-amber-600">Anda tidak punya wewenang approval (businesssupport.approval.act) — tombol dinonaktifkan.</p>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="w-10 px-3 py-3 text-center"><input type="checkbox" :checked="allSel" @change="toggleAll" class="h-4 w-4 rounded border-neutral-300" /></th>
                    <th class="px-4 py-3">No. Voucher</th><th class="px-4 py-3">No. Manual</th><th class="px-4 py-3">Tgl Voucher</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Dibuat Oleh</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-center">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="j in rows" :key="j.id" :class="isSel(j.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : 'hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40'">
                        <td class="px-3 py-2.5 text-center"><input type="checkbox" :checked="isSel(j.id)" @change="toggle(j.id)" class="h-4 w-4 rounded border-neutral-300" /></td>
                        <td class="px-4 py-2.5 font-mono font-semibold"><Link :href="route('bs.gl.journal.show', j.id)" class="text-primary-600 hover:underline">{{ j.journal_number }}</Link></td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ j.reference ?? '—' }}</td>
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(j.journal_date) }}</td>
                        <td class="px-4 py-2.5"><Badge variant="info" size="sm">{{ sources[j.source] ?? j.source }}</Badge></td>
                        <td class="px-4 py-2.5 text-xs text-neutral-500">{{ j.created_by?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ j.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(j.total_debit) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <button v-if="isFinal" :disabled="!canApprove || processing" @click="approveOne(j.id)" class="inline-flex items-center gap-1 rounded-md border border-success-600 px-2.5 py-1 text-xs font-medium text-success-700 hover:bg-success-50 disabled:opacity-40 dark:text-success-400"><CheckCircle2 class="h-3.5 w-3.5" /> Approve</button>
                            <button v-else :disabled="!canApprove || processing" @click="cancelOne(j.id)" class="inline-flex items-center gap-1 rounded-md border border-danger-600 px-2.5 py-1 text-xs font-medium text-danger-700 hover:bg-danger-50 disabled:opacity-40 dark:text-danger-400"><Undo2 class="h-3.5 w-3.5" /> Cancel</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" :title="isFinal ? 'Tidak ada voucher menunggu approval' : 'Tidak ada voucher Approved pada periode ini'" description="Ubah rentang tanggal / sumber, atau cek tab lainnya." />
        </div>

        <div v-if="journals.links?.length > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in journals.links" :key="l.label" :href="l.url" v-html="l.label"
                class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>
    </div>
</template>
