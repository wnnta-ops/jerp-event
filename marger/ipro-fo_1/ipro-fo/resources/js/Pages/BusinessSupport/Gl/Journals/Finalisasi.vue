<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileCheck2, CheckCircle2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ journals: any; sources: Record<string, string>; filters: Record<string, any> }>();
const source = ref(props.filters.source ?? '');
const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

function display() {
    router.get(route('bs.gl.journal.finalisasi'), {
        source: source.value || undefined, search: search.value || undefined,
        date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
}
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function balanced(j: any) { return Math.round(Number(j.total_debit) * 100) === Math.round(Number(j.total_credit) * 100); }

const rows = computed(() => props.journals.data ?? []);
const selected = ref<number[]>([]);
function isSel(id: number) { return selected.value.includes(id); }
function toggle(id: number) { const i = selected.value.indexOf(id); if (i >= 0) selected.value.splice(i, 1); else selected.value.push(id); }
const finalizable = computed(() => rows.value.filter(balanced));
const allSel = computed(() => finalizable.value.length > 0 && finalizable.value.every((j: any) => isSel(j.id)));
function toggleAll() {
    if (allSel.value) { const ids = new Set(finalizable.value.map((j: any) => j.id)); selected.value = selected.value.filter((id) => !ids.has(id)); }
    else { const cur = new Set(selected.value); finalizable.value.forEach((j: any) => cur.add(j.id)); selected.value = Array.from(cur); }
}

const processing = ref(false);
function finalizeSelected() {
    if (!selected.value.length) { alert('Pilih minimal 1 jurnal untuk difinalisasi.'); return; }
    if (!confirm(`Finalisasi ${selected.value.length} jurnal Baru menjadi Final? Jurnal harus sudah balance.`)) return;
    processing.value = true;
    router.post(route('bs.gl.journal.finalize-batch'), { ids: selected.value }, {
        preserveScroll: true, onSuccess: () => { selected.value = []; }, onFinish: () => { processing.value = false; },
    });
}
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCheck2 class="h-6 w-6 text-primary-600" /> Finalisasi Jurnal</h1>
            <p class="text-sm text-neutral-500">Jurnal <b>Baru</b> (draft) per periode. Pilih lalu <b>Finalisasi</b> — hanya jurnal yang sudah balance (debit = kredit) yang bisa difinalisasi menjadi <b>Final</b>.</p>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label><input v-model="dateFrom" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label><input v-model="dateTo" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber</label><select v-model="source" :class="inputCls"><option value="">Semua Jenis</option><option v-for="(label, val) in sources" :key="val" :value="val">{{ label }}</option></select></div>
            <div class="flex-1 min-w-[160px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="No. Jurnal / Keterangan / Ref" :class="[inputCls, 'w-full']" @keyup.enter="display" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">Periode <b>{{ fmtDate(filters.date_from) }}</b> s/d <b>{{ fmtDate(filters.date_to) }}</b> · {{ journals.total }} jurnal Baru<span v-if="selected.length"> · <span class="font-semibold text-primary-600">{{ selected.length }} dipilih</span></span></p>
            <Button v-if="rows.length" size="sm" variant="success" :loading="processing" @click="finalizeSelected"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Finalisasi Terpilih ({{ selected.length }})</Button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="w-10 px-3 py-3 text-center"><input type="checkbox" :checked="allSel" @change="toggleAll" class="h-4 w-4 rounded border-neutral-300" /></th>
                    <th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Dibuat Oleh</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th><th class="px-4 py-3 text-center">Balance</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="j in rows" :key="j.id">
                        <tr class="align-top" :class="isSel(j.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : 'hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40'">
                            <td class="px-3 py-2.5 text-center"><input type="checkbox" :disabled="!balanced(j)" :checked="isSel(j.id)" @change="toggle(j.id)" class="h-4 w-4 rounded border-neutral-300 disabled:opacity-30" /></td>
                            <td class="px-4 py-2.5 font-mono font-semibold">{{ j.journal_number }}<Link :href="route('bs.gl.journal.show', j.id)" class="ml-2 text-xs font-normal text-primary-600 hover:underline">lihat</Link></td>
                            <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(j.journal_date) }}</td>
                            <td class="px-4 py-2.5"><Badge variant="info" size="sm">{{ sources[j.source] ?? j.source }}</Badge></td>
                            <td class="px-4 py-2.5 text-xs text-neutral-500">{{ j.created_by?.name ?? '—' }}</td>
                            <td class="px-4 py-2.5">{{ j.description }}<span v-if="j.reference" class="block text-xs text-neutral-400">Ref: {{ j.reference }}</span></td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(j.total_debit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(j.total_credit) }}</td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="balanced(j) ? 'success' : 'danger'" size="sm">{{ balanced(j) ? 'Balance' : 'Tidak balance' }}</Badge></td>
                        </tr>
                        <!-- Rincian baris jurnal (selalu tampil) -->
                        <tr class="bg-neutral-50 dark:bg-neutral-900/40">
                            <td></td>
                            <td colspan="8" class="px-4 py-2">
                                <table class="w-full text-xs">
                                    <thead><tr class="text-left text-neutral-400"><th class="py-1 pl-2">Akun</th><th class="py-1">Keterangan Baris</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right pr-2">Kredit</th></tr></thead>
                                    <tbody>
                                        <tr v-for="ln in (j.lines ?? [])" :key="ln.id" class="border-t border-neutral-200/70 dark:border-neutral-800">
                                            <td class="py-1 pl-2 font-mono">{{ ln.account?.code }} — {{ ln.account?.name }}</td>
                                            <td class="py-1 text-neutral-500">{{ ln.description ?? '—' }}</td>
                                            <td class="py-1 text-right font-mono">{{ Number(ln.debit) > 0 ? fmtRp(ln.debit) : '' }}</td>
                                            <td class="py-1 text-right font-mono pr-2">{{ Number(ln.credit) > 0 ? fmtRp(ln.credit) : '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Tidak ada jurnal Baru pada periode ini" description="Semua jurnal sudah difinalisasi/di-approve, atau ubah rentang tanggal." />
        </div>
        <Pagination :data="journals" />
    </div>
</template>
