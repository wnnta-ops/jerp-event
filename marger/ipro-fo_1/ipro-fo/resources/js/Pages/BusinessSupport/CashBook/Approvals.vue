<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck, Check, X, ChevronDown, ChevronRight } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: any;
    apRows: any[];
    canApprove: boolean;
    summary: { count: number; total: number; ap_count: number; ap_total: number };
}>();

function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// ── BKK (Registrasi Pengeluaran Kas & Bank) ──
function approve(r: any) { if (!confirm(`Setujui pengeluaran ${r.trx_number} sebesar Rp ${fmtRp(r.amount)}?`)) return; router.post(route('bs.cashbook.approve', r.id), {}, { preserveScroll: true }); }
function reject(r: any) { if (!confirm(`Tolak pengeluaran ${r.trx_number} sebesar Rp ${fmtRp(r.amount)}?`)) return; router.post(route('bs.cashbook.reject', r.id), {}, { preserveScroll: true }); }
const expanded = ref<Record<number, boolean>>({});
function toggle(id: number) { expanded.value[id] = !expanded.value[id]; }

// ── AP (Registrasi Pengeluaran Kas & Bank (AP) — Kuitansi Hutang) ──
function approveAp(r: any) { if (!confirm(`Setujui kuitansi ${r.kuitansi_number} sebesar Rp ${fmtRp(r.amount)}? Jurnal JAP akan otomatis terbentuk.`)) return; router.post(route('bs.ap.kuitansi.approve', r.id), {}, { preserveScroll: true }); }
function rejectAp(r: any) { if (!confirm(`Tolak kuitansi ${r.kuitansi_number} sebesar Rp ${fmtRp(r.amount)}?`)) return; router.post(route('bs.ap.kuitansi.reject', r.id), {}, { preserveScroll: true }); }
const expandedAp = ref<Record<number, boolean>>({});
function toggleAp(id: number) { expandedAp.value[id] = !expandedAp.value[id]; }
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-primary-600" /> Approval Pengeluaran Kas &amp; Bank</h1>
        <p class="text-sm text-neutral-500">
            Menunggu persetujuan — <b>BKK</b>: {{ summary.count }} transaksi · <span class="font-mono">Rp {{ fmtRp(summary.total) }}</span>
            &nbsp;·&nbsp; <b>AP / Kuitansi</b>: {{ summary.ap_count }} kuitansi · <span class="font-mono">Rp {{ fmtRp(summary.ap_total) }}</span>.
        </p>

        <!-- ── REGISTRASI PENGELUARAN KAS & BANK (BKK) ── -->
        <div>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">Registrasi Pengeluaran Kas &amp; Bank</h2>
            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3 w-8"></th><th class="px-4 py-3">No (BKK)</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Rekening</th><th class="px-4 py-3">Penerima/Keterangan</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <template v-for="r in rows.data" :key="r.id">
                            <tr>
                                <td class="px-4 py-2.5">
                                    <button @click="toggle(r.id)" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800" :title="expanded[r.id] ? 'Tutup rincian' : 'Lihat rincian'">
                                        <ChevronDown v-if="expanded[r.id]" class="h-4 w-4" /><ChevronRight v-else class="h-4 w-4" />
                                    </button>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs">{{ r.trx_number }}</td>
                                <td class="px-4 py-2.5">{{ fmtDate(r.trx_date) }}</td>
                                <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ r.cash_account?.code }}</span> {{ r.cash_account?.name }}</td>
                                <td class="px-4 py-2.5 text-neutral-500">{{ r.payee || r.description }}</td>
                                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.amount) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <div v-if="canApprove" class="inline-flex items-center gap-1.5">
                                        <button @click="approve(r)" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"><Check class="h-3.5 w-3.5" /> Setujui</button>
                                        <button @click="reject(r)" class="inline-flex items-center gap-1 rounded-lg bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger-700"><X class="h-3.5 w-3.5" /> Tolak</button>
                                    </div>
                                    <span v-else class="text-xs text-neutral-400">menunggu</span>
                                </td>
                            </tr>
                            <tr v-if="expanded[r.id]" class="bg-neutral-50/70 dark:bg-neutral-800/40">
                                <td></td>
                                <td colspan="6" class="px-4 py-3">
                                    <div class="rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <p class="mb-2 text-xs font-semibold uppercase text-neutral-500">Rincian Pengeluaran</p>
                                        <table class="w-full text-xs">
                                            <thead><tr class="text-left text-neutral-400"><th class="pb-1.5">Akun</th><th class="pb-1.5">Keterangan</th><th class="pb-1.5 text-right">Nominal</th></tr></thead>
                                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                                <tr v-for="(l, i) in (r.lines ?? [])" :key="l.id ?? i">
                                                    <td class="py-1.5"><span class="font-mono text-neutral-400">{{ l.account?.code }}</span> {{ l.account?.name }}</td>
                                                    <td class="py-1.5 text-neutral-500">{{ l.description || '—' }}</td>
                                                    <td class="py-1.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                                                </tr>
                                                <tr v-if="!(r.lines ?? []).length"><td colspan="3" class="py-1.5 text-neutral-400">Tidak ada rincian baris.</td></tr>
                                            </tbody>
                                            <tfoot><tr class="border-t border-neutral-200 dark:border-neutral-700"><td colspan="2" class="pt-2 text-right font-semibold">Total</td><td class="pt-2 text-right font-mono font-semibold">{{ fmtRp(r.amount) }}</td></tr></tfoot>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <EmptyState v-if="!rows.data?.length" title="Tidak ada BKK menunggu persetujuan" description="Semua registrasi pengeluaran kas & bank sudah disetujui." />
            </div>
        </div>

        <!-- ── REGISTRASI PENGELUARAN KAS & BANK (AP) — KUITANSI HUTANG ── -->
        <div>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">Registrasi Pengeluaran Kas &amp; Bank (AP)</h2>
            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3 w-8"></th><th class="px-4 py-3">No (Kuitansi)</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Dari Kas/Bank</th><th class="px-4 py-3">Supplier / Keterangan</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <template v-for="r in apRows" :key="r.id">
                            <tr>
                                <td class="px-4 py-2.5">
                                    <button @click="toggleAp(r.id)" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800" :title="expandedAp[r.id] ? 'Tutup rincian' : 'Lihat invoice'">
                                        <ChevronDown v-if="expandedAp[r.id]" class="h-4 w-4" /><ChevronRight v-else class="h-4 w-4" />
                                    </button>
                                </td>
                                <td class="px-4 py-2.5"><Link :href="route('bs.ap.kuitansi.show', r.id)" class="font-mono text-xs text-primary-600 hover:underline">{{ r.kuitansi_number }}</Link></td>
                                <td class="px-4 py-2.5">{{ fmtDate(r.kuitansi_date) }}</td>
                                <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ r.cash_account?.code }}</span> {{ r.cash_account?.name }}</td>
                                <td class="px-4 py-2.5 text-neutral-500">{{ r.supplier }}<span v-if="r.description"> · {{ r.description }}</span></td>
                                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.amount) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <div v-if="canApprove" class="inline-flex items-center gap-1.5">
                                        <button @click="approveAp(r)" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"><Check class="h-3.5 w-3.5" /> Setujui</button>
                                        <button @click="rejectAp(r)" class="inline-flex items-center gap-1 rounded-lg bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger-700"><X class="h-3.5 w-3.5" /> Tolak</button>
                                    </div>
                                    <span v-else class="text-xs text-neutral-400">menunggu</span>
                                </td>
                            </tr>
                            <tr v-if="expandedAp[r.id]" class="bg-neutral-50/70 dark:bg-neutral-800/40">
                                <td></td>
                                <td colspan="6" class="px-4 py-3">
                                    <div class="rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <p class="mb-2 text-xs font-semibold uppercase text-neutral-500">Invoice yang Dibayar</p>
                                        <table class="w-full text-xs">
                                            <thead><tr class="text-left text-neutral-400"><th class="pb-1.5">No. Invoice</th><th class="pb-1.5">Keterangan</th><th class="pb-1.5 text-right">Nominal</th></tr></thead>
                                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                                <tr v-for="(l, i) in (r.lines ?? [])" :key="l.id ?? i">
                                                    <td class="py-1.5 font-mono text-neutral-500">{{ l.invoice_number ?? '—' }}</td>
                                                    <td class="py-1.5 text-neutral-500">{{ l.description || '—' }}</td>
                                                    <td class="py-1.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                                                </tr>
                                            </tbody>
                                            <tfoot><tr class="border-t border-neutral-200 dark:border-neutral-700"><td colspan="2" class="pt-2 text-right font-semibold">Total</td><td class="pt-2 text-right font-mono font-semibold">{{ fmtRp(r.amount) }}</td></tr></tfoot>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <EmptyState v-if="!apRows.length" title="Tidak ada Kuitansi (AP) menunggu persetujuan" description="Registrasi Pengeluaran Kas & Bank (AP) yang dibuat akan muncul di sini untuk disetujui." />
            </div>
        </div>
    </div>
</template>
