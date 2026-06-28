<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, IdCard, FileSignature, CalendarClock, AlertTriangle, Phone, ScrollText, History, Printer, FileSpreadsheet,
    Users, Ticket, Hand, ShoppingCart, FileText, Receipt, FileCheck, StickyNote, Gift, RotateCcw,
    Percent, Landmark, Scroll, FileBadge, CreditCard, RefreshCw, Coins, Activity } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    customer: any; summary: any;
    contracts: Array<any>; schedules: Array<any>; dendas: Array<any>; sp: Array<any>; followups: Array<any>;
    timeline: Array<any>; activityCounts: Record<string, number>;
}>();

const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const sv: Record<string, any> = { active: 'success', lunas: 'success', signed: 'success', cancelled: 'danger', outstanding: 'warning', partial: 'warning', paid: 'success', approved: 'success', pending: 'warning', rejected: 'danger', scheduled: 'info', open: 'warning' };

// Timeline 360° lintas-modul
const moduleIcon: Record<string, any> = { CRM: Users, NUP: Ticket, Hold: Hand, Booking: ShoppingCart, PPJB: FileText, TTS: Receipt, Kuitansi: FileCheck, Memo: StickyNote, Cashback: Gift, Refund: RotateCcw, Komisi: Percent, KPR: Landmark, Legal: Scroll, Pajak: FileBadge, VA: CreditCard, Collection: Phone, Exception: RefreshCw, Piutang: Coins, Aktivitas: Activity };
const moduleColor: Record<string, string> = { CRM: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300', NUP: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300', Hold: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300', Booking: 'bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300', PPJB: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300', TTS: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300', Kuitansi: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300', Memo: 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300', Cashback: 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300', Refund: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300', Komisi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', KPR: 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300', Legal: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', Pajak: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300', VA: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/30 dark:text-fuchsia-300', Collection: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300', Exception: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300', Piutang: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300', Aktivitas: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300' };
const activeModule = ref<string>('');
const moduleList = computed(() => Object.keys(props.activityCounts ?? {}));
const filteredTimeline = computed(() => activeModule.value ? props.timeline.filter((e) => e.module === activeModule.value) : props.timeline);
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('cif.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Customer Information File</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><IdCard class="h-6 w-6 text-primary-600" /> {{ customer.code }} <span class="text-base font-normal text-neutral-400">{{ customer.full_name }}</span></h1>
            <div class="flex items-center gap-2">
                <a :href="route('cif.print', customer.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"><Printer class="h-4 w-4" /> Cetak</a>
                <a :href="route('cif.excel', customer.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300 dark:hover:bg-emerald-900/50"><FileSpreadsheet class="h-4 w-4" /> Download Excel</a>
            </div>
        </div>

        <!-- Profil + ringkasan -->
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="card p-4 text-sm lg:col-span-1">
                <div class="mb-2 font-semibold">Profil</div>
                <dl class="space-y-1.5">
                    <div class="flex justify-between"><dt class="text-neutral-500">Identitas</dt><dd>{{ customer.id_type }} {{ customer.id_number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Telepon</dt><dd>{{ customer.phone || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Email</dt><dd>{{ customer.email || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Kota</dt><dd>{{ customer.city || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Alamat</dt><dd class="text-right">{{ customer.address_line || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Status</dt><dd><Badge :variant="sv[customer.status] ?? 'neutral'" size="sm">{{ customer.status }}</Badge></dd></div>
                </dl>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:col-span-2">
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Kontrak</div><div class="mt-1 text-xl font-bold">{{ summary.contracts }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Tagihan</div><div class="mt-1 text-xl font-bold">{{ rb(summary.tagihan) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Terbayar</div><div class="mt-1 text-xl font-bold text-success-600">{{ rb(summary.terbayar) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa Tagihan</div><div class="mt-1 text-xl font-bold text-danger-600">{{ rb(summary.sisa) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Denda Outstanding</div><div class="mt-1 text-xl font-bold text-amber-600">{{ rb(summary.denda_open) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Surat Peringatan</div><div class="mt-1 text-xl font-bold">{{ summary.sp_count }}</div></div>
            </div>
        </div>

        <!-- Kontrak -->
        <div class="card overflow-x-auto">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800"><FileSignature class="h-4 w-4 text-primary-600" /> Kontrak &amp; Unit</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">Booking</th><th class="px-4 py-2">Kontrak</th><th class="px-4 py-2">Unit</th><th class="px-4 py-2">Skema</th><th class="px-4 py-2 text-center">Status</th><th class="px-4 py-2 text-right">Tagihan</th><th class="px-4 py-2 text-right">Terbayar</th><th class="px-4 py-2 text-right">Sisa</th><th class="px-4 py-2 text-right">% Lunas</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(c, i) in contracts" :key="i">
                        <td class="px-4 py-2 font-mono text-xs">{{ c.booking_number }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ c.contract_number ?? '—' }}</td>
                        <td class="px-4 py-2">{{ c.unit ?? '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ c.scheme ?? '—' }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="sv[c.status] ?? 'neutral'" size="sm">{{ c.status }}</Badge></td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rb(c.total_tagihan) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-success-600">{{ rb(c.total_terbayar) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-danger-600">{{ rb(c.sisa_tagihan) }}</td>
                        <td class="px-4 py-2 text-right">{{ c.persen_lunas }}%</td>
                    </tr>
                    <tr v-if="!contracts.length"><td colspan="9" class="px-4 py-6 text-center text-neutral-400">Belum ada kontrak.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Jadwal pembayaran -->
            <div class="card overflow-hidden">
                <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800"><CalendarClock class="h-4 w-4 text-primary-600" /> Jadwal Pembayaran</div>
                <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">Termin</th><th class="px-4 py-2">Jatuh Tempo</th><th class="px-4 py-2 text-right">Tagihan</th><th class="px-4 py-2 text-right">Sisa</th><th class="px-4 py-2 text-center">Status</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="(s, i) in schedules" :key="i">
                                <td class="px-4 py-1.5">{{ s.label }}</td>
                                <td class="px-4 py-1.5 text-neutral-500">{{ s.due_date }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ rb(s.amount) }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums" :class="s.sisa > 0 ? 'text-danger-600' : 'text-success-600'">{{ rb(s.sisa) }}</td>
                                <td class="px-4 py-1.5 text-center"><Badge :variant="sv[s.status] ?? 'neutral'" size="sm">{{ s.status }}</Badge></td>
                            </tr>
                            <tr v-if="!schedules.length"><td colspan="5" class="px-4 py-6 text-center text-neutral-400">Tidak ada jadwal.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Denda + SP + Followup -->
            <div class="space-y-4">
                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800"><AlertTriangle class="h-4 w-4 text-amber-600" /> Denda</div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="(d, i) in dendas" :key="i">
                                <td class="px-4 py-1.5">Denda <Badge :variant="sv[d.status] ?? 'neutral'" size="sm">{{ d.status }}</Badge></td>
                                <td class="px-4 py-1.5 text-right text-neutral-500">Total {{ rb(d.amount) }}</td>
                                <td class="px-4 py-1.5 text-right font-medium text-amber-600">Sisa {{ rb(d.outstanding) }}</td>
                            </tr>
                            <tr v-if="!dendas.length"><td class="px-4 py-4 text-center text-neutral-400">Tidak ada denda.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800"><ScrollText class="h-4 w-4 text-danger-600" /> Surat Peringatan</div>
                    <ul class="divide-y divide-neutral-100 text-sm dark:divide-neutral-800">
                        <li v-for="(x, i) in sp" :key="i" class="flex items-center justify-between px-4 py-1.5"><span>SP-{{ x.level }} <span class="text-xs text-neutral-400">{{ x.at }}</span></span><Badge :variant="sv[x.status] ?? 'neutral'" size="sm">{{ x.status }}</Badge></li>
                        <li v-if="!sp.length" class="px-4 py-4 text-center text-neutral-400">Tidak ada surat peringatan.</li>
                    </ul>
                </div>
                <div class="card overflow-hidden">
                    <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800"><Phone class="h-4 w-4 text-primary-600" /> Follow Up</div>
                    <ul class="divide-y divide-neutral-100 text-sm dark:divide-neutral-800">
                        <li v-for="(f, i) in followups" :key="i" class="px-4 py-1.5"><span class="text-xs text-neutral-400">{{ f.at }}</span> · {{ f.notes ?? '—' }}</li>
                        <li v-if="!followups.length" class="px-4 py-4 text-center text-neutral-400">Tidak ada follow-up.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Riwayat Aktivitas 360° (lintas modul) -->
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 text-sm font-semibold"><History class="h-4 w-4 text-primary-600" /> Riwayat Aktivitas (Semua Modul) <span class="text-xs font-normal text-neutral-400">· {{ timeline.length }} aktivitas</span></div>
            </div>
            <!-- Filter chip per modul -->
            <div class="flex flex-wrap gap-1.5 border-b border-neutral-100 px-4 py-2.5 dark:border-neutral-800">
                <button @click="activeModule = ''" class="rounded-full px-2.5 py-1 text-xs font-medium" :class="activeModule === '' ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'">Semua ({{ timeline.length }})</button>
                <button v-for="m in moduleList" :key="m" @click="activeModule = m" class="rounded-full px-2.5 py-1 text-xs font-medium" :class="activeModule === m ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300'">{{ m }} ({{ activityCounts[m] }})</button>
            </div>
            <!-- Daftar aktivitas (scroll) -->
            <div class="max-h-[28rem] overflow-y-auto">
                <ul class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <li v-for="(e, i) in filteredTimeline" :key="i" class="flex items-start gap-3 px-4 py-2.5">
                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg" :class="moduleColor[e.module] ?? 'bg-neutral-100 text-neutral-600'">
                            <component :is="moduleIcon[e.module] ?? Activity" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="text-sm font-medium">{{ e.title }}</span>
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="moduleColor[e.module] ?? 'bg-neutral-100 text-neutral-600'">{{ e.module }}</span>
                                <Badge v-if="e.status" :variant="sv[e.status] ?? 'neutral'" size="sm">{{ e.status }}</Badge>
                            </div>
                            <div v-if="e.detail" class="truncate text-xs text-neutral-500">{{ e.detail }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div v-if="e.amount !== null" class="font-mono text-sm tabular-nums">{{ rb(e.amount) }}</div>
                            <div class="text-xs text-neutral-400">{{ fmtDate(e.date) }}</div>
                        </div>
                    </li>
                    <li v-if="!filteredTimeline.length" class="px-4 py-8 text-center text-sm text-neutral-400">Belum ada aktivitas untuk modul ini.</li>
                </ul>
            </div>
        </div>
    </div>
</template>
