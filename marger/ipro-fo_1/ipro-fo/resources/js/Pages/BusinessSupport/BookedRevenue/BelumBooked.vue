<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleDollarSign, ArrowRight, CheckCircle2, FileSpreadsheet } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = { no: number; booking_id: number; unit_id: number | null; spr: string | null; unit: string | null; customer: string | null; ajb_date: string | null; bast_date: string | null };
const props = defineProps<{
    rows: Array<Row>;
    filters: { search?: string };
}>();

function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
// Booked Revenue hanya aktif bila tanggal BAST DAN tanggal AJB sudah terisi keduanya.
const ready = (r: { bast_date: string | null; ajb_date: string | null }) => !!(r.bast_date && r.ajb_date);
const f = reactive({ search: props.filters.search ?? '' });

/* Ekspor mengikuti kata pencarian yang sedang berlaku, supaya berkasnya berisi persis
   apa yang sedang dilihat. */
function exportExcel() {
    window.location.href = route('bs.booked-revenue.belum.export', { search: f.search || undefined });
}
let t: any;
watch(f, (val) => { clearTimeout(t); t = setTimeout(() => router.get(route('bs.booked-revenue.belum'), { search: val.search || undefined }, { preserveState: true, replace: true }), 300); });
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// ── Checklist massal ─────────────────────────────────────────────────────────
const selected = ref<Set<number>>(new Set());
const bookDate = ref<string>(new Date().toISOString().slice(0, 10));
const processing = ref(false);
const eligibleIds = computed(() => props.rows.filter(ready).map((r) => r.booking_id));
const allChecked = computed(() => eligibleIds.value.length > 0 && eligibleIds.value.every((id) => selected.value.has(id)));
// Saat daftar berubah (mis. pencarian), buang pilihan yang tak lagi tampil/eligible.
watch(() => props.rows, () => {
    const vis = new Set(eligibleIds.value);
    selected.value = new Set([...selected.value].filter((id) => vis.has(id)));
});
function toggle(id: number) { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; }
function toggleAll() { selected.value = allChecked.value ? new Set() : new Set(eligibleIds.value); }
function submitMassal() {
    if (!selected.value.size || processing.value) return;
    if (!window.confirm(`Booked Revenue untuk ${selected.value.size} unit terpilih (tgl ${fmtDate(bookDate.value)})?\nJurnal otomatis (Dr Piutang / Cr Pendapatan Diterima Dimuka) diposting per unit.`)) return;
    processing.value = true;
    router.post(route('bs.booked-revenue.book-massal'),
        { booking_ids: [...selected.value], booking_date: bookDate.value },
        { preserveScroll: true, onFinish: () => { processing.value = false; } });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Sudah Booked Revenue</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CircleDollarSign class="h-6 w-6 text-amber-500" /> Belum Booked Revenue</h1>
            <button type="button" @click="exportExcel"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700">
                <FileSpreadsheet class="h-4 w-4" /> Excel
            </button>
            <p class="text-sm text-neutral-500">Unit yang sudah <b>BAST</b> atau <b>AJB</b> namun belum di-booking pendapatannya. Tombol <b>Booked Revenue</b> aktif setelah tanggal BAST &amp; AJB terisi keduanya. Centang beberapa unit siap untuk <b>Booked Revenue Massal</b>.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari SPR / customer / unit…" :class="[inputCls, 'w-72']" />
            <span class="text-xs text-neutral-500">{{ eligibleIds.length }} dari {{ rows.length }} unit siap di-booking</span>
        </div>

        <!-- Bar aksi massal (muncul saat ada yang dicentang) -->
        <div v-if="selected.size" class="flex flex-wrap items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-500/30 dark:bg-primary-500/10">
            <span class="text-sm font-semibold text-primary-800 dark:text-primary-300">{{ selected.size }} unit terpilih</span>
            <label class="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300">Tgl booking
                <input type="date" v-model="bookDate" :class="inputCls" />
            </label>
            <button type="button" :disabled="processing" @click="submitMassal"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                <CheckCircle2 class="h-4 w-4" /> Booked Revenue Massal ({{ selected.size }})
            </button>
            <button type="button" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200" @click="selected = new Set()">Batal</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3 w-10 text-center">
                        <input type="checkbox" :checked="allChecked" :disabled="!eligibleIds.length" @change="toggleAll" title="Pilih semua yang siap" class="h-4 w-4 rounded border-neutral-300" />
                    </th>
                    <th class="px-4 py-3 w-12">No</th>
                    <th class="px-4 py-3">No. SPR</th>
                    <th class="px-4 py-3">Unit Rumah</th>
                    <th class="px-4 py-3">Nama Customer</th>
                    <th class="px-4 py-3">Tgl AJB</th>
                    <th class="px-4 py-3">Tgl BAST</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.booking_id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="{ 'bg-primary-50/50 dark:bg-primary-500/5': selected.has(r.booking_id) }">
                        <td class="px-4 py-2.5 text-center">
                            <input v-if="ready(r)" type="checkbox" :checked="selected.has(r.booking_id)" @change="toggle(r.booking_id)" class="h-4 w-4 rounded border-neutral-300" />
                        </td>
                        <td class="px-4 py-2.5 tabular-nums text-neutral-500">{{ r.no }}</td>
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.spr ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.ajb_date) }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.bast_date) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <Link v-if="ready(r)" :href="route('bs.booked-revenue.create', { unit_id: r.unit_id })"
                                  class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-700">
                                Booked Revenue <ArrowRight class="h-3.5 w-3.5" />
                            </Link>
                            <span v-else class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg bg-neutral-200 px-3 py-1.5 text-xs font-semibold text-neutral-400 dark:bg-neutral-800 dark:text-neutral-600" title="Perlu tanggal BAST dan AJB terisi keduanya">
                                Booked Revenue <ArrowRight class="h-3.5 w-3.5" />
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada unit untuk Booked Revenue" description="Unit muncul di sini setelah BAST atau AJB terbit, dan belum di-booking pendapatannya." />
        </div>
    </div>
</template>
