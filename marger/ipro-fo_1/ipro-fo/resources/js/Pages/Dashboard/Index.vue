<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Users, Home, ClipboardList, Wallet, TrendingUp, TrendingDown, Bell,
    Activity, ShoppingCart, AlertTriangle, Landmark, FileText, ArrowRight, BarChart3,
    ClipboardCheck, BookOpen, Receipt, PiggyBank, Boxes, HardHat, Percent, Database,
    Building2, Banknote, CircleDollarSign, Building, Briefcase, Vote, FileBarChart,
    Settings2, ShieldCheck, Settings, LayoutGrid,
} from 'lucide-vue-next';
import Sparkline from '@/Components/Charts/Sparkline.vue';
import Donut from '@/Components/Charts/Donut.vue';
import Bars from '@/Components/Charts/Bars.vue';
import AreaLine from '@/Components/Charts/AreaLine.vue';

interface Seg { label: string; value: number; color: string }
interface Tile { label: string; count: number; url: string; group: string }

const props = defineProps<{
    summary: { customers_total: number; bookings_active: number; bookings_month: number };
    greeting?: { name: string | null; roles: string[]; last_login_at: string | null; last_login_ip: string | null };
    reminders?: Array<{ type: string; title: string; due_date: string; ref_id: number | null; severity: string }>;
    metrics: {
        kpis: {
            customers: { value: number; sub: string; spark: number[]; delta: number | null };
            units: { value: number; sub: string; spark: number[]; delta: number | null };
            bookings: { value: number; sub: string; spark: number[]; delta: number | null };
            penerimaan: { value: number; sub: string; spark: number[]; delta: number | null; is_money?: boolean };
            sales_value: { value: number; sub: string; is_money?: boolean };
        };
        trend: { labels: string[]; bookings: number[]; penerimaan: number[]; customers: number[] };
        unit_status: Seg[]; booking_status: Seg[]; installments: Seg[];
        funnel: Seg[]; finance_modules: Seg[]; legal_docs: Seg[]; collection: Seg[]; customer_sources: Seg[];
        module_tiles: Tile[];
        recent_activity: Array<{ category: string; description: string; when: string; subject: string }>;
        modules: {
            kpr: { total: number; plafon: number; url: string; dist: Seg[] };
            approval: { pending: number; total: number; url: string; dist: Seg[] };
            gl: { total: number; url: string; dist: Seg[] };
            ap: { outstanding: number; open: number; url: string };
            ar: { outstanding: number; open: number };
            cashbook: { count: number; volume: number; pending: number; url: string };
            budget: { total: number; count: number; url: string };
            cogs: { budget: number; realisasi: number; url: string };
            contractor: { contract: number; progress: number; count: number; url: string };
            komisi: { total: number; url: string; lines: Seg[] };
            datacenter: { customers: number; suppliers: number; employees: number; url: string };
        };
    };
}>();

const m = props.metrics;

/* ── Trend series switcher (interaktif) ── */
const trendKey = ref<'penerimaan' | 'bookings' | 'customers'>('penerimaan');
const trendSeries = computed(() => m.trend[trendKey.value]);
const trendMoney = computed(() => trendKey.value === 'penerimaan');
const trendColor = computed(() => ({ penerimaan: '#ff8c00', bookings: '#10b981', customers: '#7c3aed' }[trendKey.value]));
const trendTabs = [
    { key: 'penerimaan', label: 'Penerimaan' },
    { key: 'bookings', label: 'Booking' },
    { key: 'customers', label: 'Customer' },
] as const;

const funnelMax = computed(() => Math.max(...m.funnel.map((f) => f.value), 1));

const groupIcon: Record<string, any> = {
    master: Users, sales: ShoppingCart, finance: Wallet,
    collection: AlertTriangle, bank: Landmark, legal: FileText,
};
const groupColor: Record<string, string> = {
    master: '#ff8c00', sales: '#f59e0b', finance: '#10b981',
    collection: '#f43f5e', bank: '#14b8a6', legal: '#7c3aed',
};

function rupiah(v: number): string {
    if (v >= 1e9) return 'Rp ' + (v / 1e9).toFixed(1).replace('.', ',') + ' M';
    if (v >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1).replace('.', ',') + ' Jt';
    if (v >= 1e3) return 'Rp ' + Math.round(v / 1e3).toLocaleString('id-ID') + ' Rb';
    return 'Rp ' + v.toLocaleString('id-ID');
}
function kpiValue(k: { value: number; is_money?: boolean }): string {
    return k.is_money ? rupiah(k.value) : k.value.toLocaleString('id-ID');
}
function fmtDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function fmtDate(iso: string): string {
    return new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}
function relTime(iso: string): string {
    const d = (Date.now() - new Date(iso).getTime()) / 1000;
    if (d < 60) return 'baru saja';
    if (d < 3600) return Math.floor(d / 60) + ' mnt lalu';
    if (d < 86400) return Math.floor(d / 3600) + ' jam lalu';
    return Math.floor(d / 86400) + ' hari lalu';
}
/* Persentase progress (untuk bar realisasi/kontrak), aman bila pembagi 0. */
function pct(a: number, b: number): number { return b > 0 ? Math.min(100, Math.round((a / b) * 100)) : 0; }
const mod = props.metrics.modules;

/* ══ MENU UTAMA — peluncur modul (kotak-kotak yang mengarah ke tiap modul BO) ══
   Hanya tampil bila route dikenal & user punya izin (mirror gating Sidebar).
   Warna chip pakai inline-style (hex) agar aman dari purge Tailwind. */
const pageP = usePage();
function modHasPermission(perm?: string | string[]): boolean {
    if (!perm) return true;
    const perms: string[] = (pageP.props as any).auth?.user?.permissions ?? [];
    const list = Array.isArray(perm) ? perm : [perm];
    return list.some((p) => p.endsWith('.*') ? perms.some((x) => x.startsWith(p.slice(0, -1))) : perms.includes(p));
}
function modRoute(name: string, params?: Record<string, any>): string | null {
    try { return route(name, params); } catch { return null; }
}
interface ModCard { key: string; title: string; desc: string; icon: any; color: string; route: string; params?: Record<string, any>; permission?: string | string[]; }
const MODULES: ModCard[] = [
    { key: 'master',      title: 'Master',                desc: 'Data unit & master properti.',                      icon: Building2,       color: '#ff8c00', route: 'units.index',            permission: 'master.unit.view' },
    { key: 'datacenter',  title: 'Data Center',           desc: 'Pusat data Customer, Supplier & Karyawan.',         icon: Database,        color: '#0ea5e9', route: 'bs.dc.customer.center',  permission: ['master.customer.view', 'businesssupport.view', 'datacenter.*'] },
    { key: 'kpr',         title: 'KPR',                   desc: 'Inisiasi & Proses KPR bank (Bank Loan).',           icon: Banknote,        color: '#10b981', route: 'kpr.index',              permission: 'bankloan.application.view' },
    { key: 'bs',          title: 'Business Support',      desc: 'AR, Payroll, Inventory & Purchasing.',              icon: Briefcase,       color: '#f59e0b', route: 'bs.index',               permission: ['businesssupport.view', 'ap.*', 'ar.*', 'gl.*', 'cashbook.*', 'budget.*', 'contractor.*', 'cogs.*', 'tax.*', 'bs.*'] },
    { key: 'revenue',     title: 'Booked Revenue',        desc: 'Pendapatan diakui, BAST/AJB & Faktur Pajak.',       icon: CircleDollarSign,color: '#14b8a6', route: 'bs.booked-revenue.index',permission: ['businesssupport.view', 'ar.*'] },
    { key: 'fa',          title: 'Aset Tetap',            desc: 'Registrasi & penyusutan aset tetap.',               icon: Building,        color: '#06b6d4', route: 'bs.fa.index',            permission: ['businesssupport.view', 'bs.*'] },
    { key: 'gl',          title: 'General Ledger',        desc: 'Jurnal, Neraca, Laba Rugi & Cash Flow.',            icon: BookOpen,        color: '#7c3aed', route: 'bs.gl.printhub',         permission: ['businesssupport.view', 'gl.*'] },
    { key: 'ap',          title: 'Account Payable',       desc: 'Invoice & pembayaran hutang supplier.',             icon: Receipt,         color: '#f43f5e', route: 'bs.ap.index',            permission: ['businesssupport.view', 'ap.*'] },
    { key: 'cashbook',    title: 'Cash Book',             desc: 'Kas, Bank, Giro, Bon Sementara & approval.',        icon: Wallet,          color: '#6366f1', route: 'bs.cashbook.printhub',   permission: ['businesssupport.view', 'cashbook.*'] },
    { key: 'budget',      title: 'Budget Control',        desc: 'Anggaran, alokasi & koreksi budget.',               icon: PiggyBank,       color: '#d946ef', route: 'bs.budget.index',        permission: ['businesssupport.view', 'budget.*'] },
    { key: 'contractor',  title: 'Contractor Management', desc: 'SPK, progress lapangan & retensi.',                 icon: HardHat,         color: '#f97316', route: 'bs.spk.index',           permission: ['businesssupport.view', 'contractor.*'] },
    { key: 'cogs',        title: 'COGS',                  desc: 'HPP RealCost & budget proyek.',                     icon: Boxes,           color: '#84cc16', route: 'bs.cogs.printhub',       permission: ['businesssupport.view', 'cogs.*'] },
    { key: 'approval',    title: 'Approval',              desc: 'Pusat persetujuan seluruh transaksi.',              icon: Vote,            color: '#eab308', route: 'approvals.index',        permission: 'approval.view' },
    { key: 'reports',     title: 'Reports',               desc: 'Laporan & export data.',                            icon: FileBarChart,    color: '#3b82f6', route: 'reports.index',          permission: 'reporting.report.export' },
    { key: 'setupsales',  title: 'Setup Sales',           desc: 'Skema bayar, sales, agen & price list.',            icon: Settings2,       color: '#ec4899', route: 'setup-sales.index',      permission: 'setupsales.view' },
    { key: 'security',    title: 'Security',              desc: 'User, hak akses, absensi & log.',                   icon: ShieldCheck,     color: '#ef4444', route: 'security.index',         permission: 'security.view' },
    { key: 'system',      title: 'System',                desc: 'Pengaturan & konfigurasi sistem.',                  icon: Settings,        color: '#64748b', route: 'settings.index',         permission: 'system.user.view' },
];
const launcherModules = computed(() => MODULES
    .map((mm) => ({ ...mm, url: modRoute(mm.route, mm.params) }))
    .filter((mm) => mm.url && modHasPermission(mm.permission)));
</script>

<template>
    <Head title="Menu Utama" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                    Selamat Datang{{ greeting?.name ? `, ${greeting.name}` : '' }}! 👋
                </h1>
                <p class="text-sm text-neutral-500 mt-1">Ringkasan aktivitas Java Core System hari ini</p>
                <p v-if="greeting?.last_login_at" class="text-xs text-neutral-400 mt-1">
                    Login terakhir: {{ fmtDateTime(greeting.last_login_at) }}
                    <span v-if="greeting.last_login_ip">· IP {{ greeting.last_login_ip }}</span>
                </p>
            </div>
        </div>

        <!-- ════ MENU UTAMA — peluncur modul (klik kotak → buka modul terkait) ════ -->
        <section>
            <div class="mb-3 flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-500/15"><LayoutGrid class="h-5 w-5 text-primary-600" /></span>
                <div>
                    <h2 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Menu Utama</h2>
                    <p class="text-xs text-neutral-500">Pilih modul untuk mulai — {{ launcherModules.length }} modul tersedia.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <Link v-for="mm in launcherModules" :key="mm.key" :href="mm.url!"
                      class="group card card-hover relative flex flex-col gap-3 overflow-hidden p-5 transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="absolute inset-x-0 top-0 h-1" :style="{ background: mm.color }"></span>
                    <div class="flex items-center gap-3">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl shadow-sm" :style="{ background: mm.color }">
                            <component :is="mm.icon" class="h-6 w-6 text-white" />
                        </span>
                        <h3 class="text-base font-bold leading-tight text-neutral-900 dark:text-neutral-100">{{ mm.title }}</h3>
                        <ArrowRight class="ml-auto h-4 w-4 shrink-0 text-neutral-300 transition group-hover:translate-x-0.5 group-hover:text-primary-500" />
                    </div>
                    <p class="text-sm leading-relaxed text-neutral-500 dark:text-neutral-400">{{ mm.desc }}</p>
                </Link>
            </div>
        </section>

        <!-- ════ Ringkasan & Statistik ════ -->
        <div class="flex items-center gap-2 pt-2">
            <span class="h-5 w-1.5 rounded-full bg-primary-500"></span>
            <div>
                <h2 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Ringkasan &amp; Statistik</h2>
                <p class="text-xs text-neutral-500">Ringkasan aktivitas &amp; performa tiap modul</p>
            </div>
        </div>

        <!-- ════ KPI CARDS ════ -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
            <div class="card card-hover p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-neutral-500">Total Customer</p>
                    <span class="p-1.5 rounded-lg bg-primary-50 dark:bg-primary-900/20"><Users class="h-4 w-4 text-primary-600" /></span>
                </div>
                <p class="text-3xl font-extrabold mt-2 tabular-nums">{{ kpiValue(metrics.kpis.customers) }}</p>
                <div class="flex items-center justify-between mt-2">
                    <span class="text-xs text-neutral-400">{{ metrics.kpis.customers.sub }}</span>
                    <Sparkline :points="metrics.kpis.customers.spark" color="#ff8c00" :width="70" :height="28" />
                </div>
            </div>

            <div class="card card-hover p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-neutral-500">Unit Tersedia</p>
                    <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/20"><Home class="h-4 w-4 text-emerald-600" /></span>
                </div>
                <p class="text-3xl font-extrabold mt-2 tabular-nums">{{ metrics.kpis.units.value }}</p>
                <span class="text-xs text-neutral-400 mt-2 block">{{ metrics.kpis.units.sub }}</span>
            </div>

            <div class="card card-hover p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-neutral-500">Booking Aktif</p>
                    <span class="p-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20"><ClipboardList class="h-4 w-4 text-amber-600" /></span>
                </div>
                <p class="text-3xl font-extrabold mt-2 tabular-nums">{{ metrics.kpis.bookings.value }}</p>
                <div class="flex items-center justify-between mt-2">
                    <span class="text-xs text-neutral-400">{{ metrics.kpis.bookings.sub }}</span>
                    <Sparkline :points="metrics.kpis.bookings.spark" color="#10b981" :width="70" :height="28" />
                </div>
            </div>

            <div class="card card-hover p-5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-neutral-500">Penerimaan Bln Ini</p>
                    <span class="p-1.5 rounded-lg bg-primary-50 dark:bg-primary-900/20"><Wallet class="h-4 w-4 text-primary-600" /></span>
                </div>
                <p class="text-2xl font-extrabold mt-2 tabular-nums">{{ kpiValue(metrics.kpis.penerimaan) }}</p>
                <div class="flex items-center justify-between mt-2">
                    <span class="inline-flex items-center gap-1 text-xs font-medium"
                          :class="(metrics.kpis.penerimaan.delta ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                        <TrendingUp v-if="(metrics.kpis.penerimaan.delta ?? 0) >= 0" class="h-3 w-3" />
                        <TrendingDown v-else class="h-3 w-3" />
                        {{ metrics.kpis.penerimaan.delta !== null ? Math.abs(metrics.kpis.penerimaan.delta) + '%' : '—' }}
                    </span>
                    <Sparkline :points="metrics.kpis.penerimaan.spark" color="#ff8c00" :width="70" :height="28" />
                </div>
            </div>

            <div class="card card-hover p-5 bg-gradient-to-br from-primary-600 to-primary-700 text-white border-transparent">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-white/80">Nilai Penjualan</p>
                    <span class="p-1.5 rounded-lg bg-white/15"><BarChart3 class="h-4 w-4 text-white" /></span>
                </div>
                <p class="text-2xl font-extrabold mt-2 tabular-nums">{{ kpiValue(metrics.kpis.sales_value) }}</p>
                <span class="text-xs text-white/70 mt-2 block">{{ metrics.kpis.sales_value.sub }}</span>
            </div>
        </div>

        <!-- ════ TREND + FUNNEL ════ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="card p-5 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                    <h2 class="font-semibold flex items-center gap-2"><Activity class="h-4 w-4 text-primary-600" /> Tren 6 Bulan Terakhir</h2>
                    <div class="flex gap-1 bg-neutral-100 dark:bg-neutral-800 rounded-lg p-1">
                        <button v-for="t in trendTabs" :key="t.key"
                                @click="trendKey = t.key"
                                class="px-3 py-1 text-xs font-medium rounded-md transition"
                                :class="trendKey === t.key ? 'bg-white dark:bg-neutral-700 shadow text-primary-700 dark:text-primary-300' : 'text-neutral-500 hover:text-neutral-700'">
                            {{ t.label }}
                        </button>
                    </div>
                </div>
                <AreaLine :labels="metrics.trend.labels" :values="trendSeries" :color="trendColor" :money="trendMoney" :height="240" />
            </div>

            <div class="card p-5">
                <h2 class="font-semibold mb-4 flex items-center gap-2"><TrendingUp class="h-4 w-4 text-primary-600" /> Pipeline Penjualan</h2>
                <div class="space-y-3">
                    <div v-for="(f, i) in metrics.funnel" :key="i" class="group">
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="font-medium text-neutral-600 dark:text-neutral-300">{{ f.label }}</span>
                            <span class="font-bold tabular-nums">{{ f.value }}</span>
                        </div>
                        <div class="h-3 rounded-full bg-neutral-100 dark:bg-neutral-800 overflow-hidden">
                            <div class="h-full rounded-full funnel-bar"
                                 :style="{ width: (f.value / funnelMax * 100) + '%', background: f.color, '--d': (i * 0.12) + 's' }"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════ PERFORMA PER MODUL ════════════ -->
        <div class="flex items-center gap-2 pt-1">
            <span class="h-5 w-1.5 rounded-full bg-primary-500"></span>
            <div>
                <h2 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Performa per Modul</h2>
                <p class="text-xs text-neutral-500">Ringkasan visual tiap modul Java Core System</p>
            </div>
        </div>

        <!-- Baris A: KPR · Approval · General Ledger (donut) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-teal-50 dark:bg-teal-900/20"><Landmark class="h-4 w-4 text-teal-600" /></span> KPR — per Tahap</h3>
                    <Link :href="mod.kpr.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <Donut :items="mod.kpr.dist" center-label="Aplikasi" :size="150" />
                <p class="mt-3 text-xs text-neutral-500">Plafon disetujui: <span class="font-semibold text-neutral-700 dark:text-neutral-200">{{ rupiah(mod.kpr.plafon) }}</span></p>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20"><ClipboardCheck class="h-4 w-4 text-amber-600" /></span> Approval</h3>
                    <Link :href="mod.approval.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <div class="mb-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 px-3 py-2">
                    <span class="text-2xl font-extrabold text-amber-600 tabular-nums">{{ mod.approval.pending.toLocaleString('id-ID') }}</span>
                    <span class="text-xs text-amber-700/80 dark:text-amber-300/80"> menunggu persetujuan</span>
                </div>
                <Donut :items="mod.approval.dist" center-label="Total" :size="140" />
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/20"><BookOpen class="h-4 w-4 text-emerald-600" /></span> General Ledger</h3>
                    <Link :href="mod.gl.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <Donut :items="mod.gl.dist" center-label="Jurnal" :size="150" />
                <p class="mt-3 text-xs text-neutral-500">Total jurnal: <span class="font-semibold text-neutral-700 dark:text-neutral-200">{{ mod.gl.total.toLocaleString('id-ID') }}</span></p>
            </div>
        </div>

        <!-- Baris B: Hutang/Piutang · Cash Book · Budget · Data Center (stat) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/20"><Receipt class="h-4 w-4 text-rose-600" /></span> Hutang &amp; Piutang</h3>
                    <Link :href="mod.ap.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <div class="space-y-3">
                    <div>
                        <p class="text-xs text-neutral-500">Hutang (AP) outstanding</p>
                        <p class="text-xl font-extrabold text-rose-600 tabular-nums">{{ rupiah(mod.ap.outstanding) }}</p>
                        <p class="text-[11px] text-neutral-400">{{ mod.ap.open }} faktur belum lunas</p>
                    </div>
                    <div class="border-t border-neutral-100 dark:border-neutral-800 pt-3">
                        <p class="text-xs text-neutral-500">Piutang (AR) outstanding</p>
                        <p class="text-xl font-extrabold text-emerald-600 tabular-nums">{{ rupiah(mod.ar.outstanding) }}</p>
                        <p class="text-[11px] text-neutral-400">{{ mod.ar.open }} faktur belum lunas</p>
                    </div>
                </div>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-primary-50 dark:bg-primary-900/20"><Wallet class="h-4 w-4 text-primary-600" /></span> Cash Book</h3>
                    <Link :href="mod.cashbook.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <p class="text-xs text-neutral-500">Volume transaksi</p>
                <p class="text-2xl font-extrabold tabular-nums">{{ rupiah(mod.cashbook.volume) }}</p>
                <div class="mt-3 flex items-center gap-3 text-xs">
                    <span class="text-neutral-500">{{ mod.cashbook.count }} transaksi</span>
                    <span v-if="mod.cashbook.pending" class="inline-flex items-center gap-1 rounded-full bg-amber-50 dark:bg-amber-900/20 px-2 py-0.5 font-medium text-amber-600">{{ mod.cashbook.pending }} perlu approval</span>
                </div>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-violet-50 dark:bg-violet-900/20"><PiggyBank class="h-4 w-4 text-violet-600" /></span> Budget Control</h3>
                    <Link :href="mod.budget.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <p class="text-xs text-neutral-500">Total anggaran</p>
                <p class="text-2xl font-extrabold tabular-nums">{{ rupiah(mod.budget.total) }}</p>
                <p class="mt-3 text-xs text-neutral-500">{{ mod.budget.count }} dokumen anggaran</p>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-sky-50 dark:bg-sky-900/20"><Database class="h-4 w-4 text-sky-600" /></span> Data Center</h3>
                    <Link :href="mod.datacenter.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-neutral-50 dark:bg-neutral-800/60 py-2">
                        <p class="text-lg font-extrabold tabular-nums">{{ mod.datacenter.customers.toLocaleString('id-ID') }}</p>
                        <p class="text-[10px] text-neutral-500">Customer</p>
                    </div>
                    <div class="rounded-lg bg-neutral-50 dark:bg-neutral-800/60 py-2">
                        <p class="text-lg font-extrabold tabular-nums">{{ mod.datacenter.suppliers.toLocaleString('id-ID') }}</p>
                        <p class="text-[10px] text-neutral-500">Supplier</p>
                    </div>
                    <div class="rounded-lg bg-neutral-50 dark:bg-neutral-800/60 py-2">
                        <p class="text-lg font-extrabold tabular-nums">{{ mod.datacenter.employees.toLocaleString('id-ID') }}</p>
                        <p class="text-[10px] text-neutral-500">Pegawai</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Baris C: Contractor · COGS (progress) · Komisi (bars) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-orange-50 dark:bg-orange-900/20"><HardHat class="h-4 w-4 text-orange-600" /></span> Contractor (SPK)</h3>
                    <Link :href="mod.contractor.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <div class="flex items-end justify-between text-xs text-neutral-500">
                    <span>Progress</span><span class="font-bold text-neutral-700 dark:text-neutral-200">{{ pct(mod.contractor.progress, mod.contractor.contract) }}%</span>
                </div>
                <div class="mt-1 h-3 rounded-full bg-neutral-100 dark:bg-neutral-800 overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-orange-400 to-orange-600 transition-all" :style="{ width: pct(mod.contractor.progress, mod.contractor.contract) + '%' }"></div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div><p class="text-neutral-500">Nilai Kontrak</p><p class="font-semibold tabular-nums">{{ rupiah(mod.contractor.contract) }}</p></div>
                    <div><p class="text-neutral-500">Realisasi</p><p class="font-semibold tabular-nums">{{ rupiah(mod.contractor.progress) }}</p></div>
                </div>
                <p class="mt-2 text-[11px] text-neutral-400">{{ mod.contractor.count }} SPK</p>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-lime-50 dark:bg-lime-900/20"><Boxes class="h-4 w-4 text-lime-600" /></span> COGS</h3>
                    <Link :href="mod.cogs.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <template v-if="mod.cogs.budget > 0 || mod.cogs.realisasi > 0">
                    <div class="flex items-end justify-between text-xs text-neutral-500">
                        <span>Realisasi vs Budget</span><span class="font-bold text-neutral-700 dark:text-neutral-200">{{ pct(mod.cogs.realisasi, mod.cogs.budget) }}%</span>
                    </div>
                    <div class="mt-1 h-3 rounded-full bg-neutral-100 dark:bg-neutral-800 overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-lime-400 to-emerald-600 transition-all" :style="{ width: pct(mod.cogs.realisasi, mod.cogs.budget) + '%' }"></div>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><p class="text-neutral-500">Budget</p><p class="font-semibold tabular-nums">{{ rupiah(mod.cogs.budget) }}</p></div>
                        <div><p class="text-neutral-500">Realisasi</p><p class="font-semibold tabular-nums">{{ rupiah(mod.cogs.realisasi) }}</p></div>
                    </div>
                </template>
                <div v-else class="py-8 text-center text-sm text-neutral-400">Belum ada data COGS.</div>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold flex items-center gap-2"><span class="p-1.5 rounded-lg bg-pink-50 dark:bg-pink-900/20"><Percent class="h-4 w-4 text-pink-600" /></span> Komisi</h3>
                    <Link :href="mod.komisi.url" class="text-xs text-primary-600 hover:underline">Lihat →</Link>
                </div>
                <p class="text-xs text-neutral-500">Total komisi (basis)</p>
                <p class="text-2xl font-extrabold tabular-nums mb-2">{{ rupiah(mod.komisi.total) }}</p>
                <Bars v-if="mod.komisi.lines.length" :items="mod.komisi.lines" :height="120" />
            </div>
        </div>

        <!-- ════ DONUTS: Unit, Booking, Cicilan ════ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Status Inventori Unit</h2>
                <Donut :items="metrics.unit_status" center-label="Total Unit" :size="160" />
            </div>
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Status Booking</h2>
                <Donut :items="metrics.booking_status" center-label="Total Booking" :size="160" />
            </div>
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Pembayaran Cicilan</h2>
                <Donut :items="metrics.installments" center-label="Total Cicilan" :size="160" />
            </div>
        </div>

        <!-- ════ BARS: Finance, Legal, Collection ════ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Aktivitas Finance</h2>
                <Bars :items="metrics.finance_modules" :height="180" />
            </div>
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Dokumen Legal</h2>
                <Bars :items="metrics.legal_docs" :height="180" />
            </div>
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Koleksi & Tunggakan</h2>
                <Bars :items="metrics.collection" :height="180" />
            </div>
        </div>

        <!-- ════ Customer sources + Reminder ════ -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Customer per Sumber Data</h2>
                <Bars :items="metrics.customer_sources" :height="180" />
            </div>

            <div class="card p-5">
                <div class="flex items-center gap-2 mb-3">
                    <Bell class="h-5 w-5 text-amber-500" />
                    <h2 class="font-semibold">Reminder — Jatuh Tempo 7 Hari</h2>
                </div>
                <div v-if="!reminders?.length" class="text-sm text-neutral-400 py-10 text-center">
                    Tidak ada cicilan yang jatuh tempo dalam 7 hari. 🎉
                </div>
                <ul v-else class="divide-y divide-neutral-100 dark:divide-neutral-800 max-h-52 overflow-auto">
                    <li v-for="(r, i) in reminders" :key="i" class="py-2 flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="w-2 h-2 rounded-full shrink-0" :class="r.severity === 'overdue' ? 'bg-rose-500' : 'bg-amber-500'"></span>
                            <span class="truncate">{{ r.title }}</span>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="text-xs" :class="r.severity === 'overdue' ? 'text-rose-600 font-semibold' : 'text-neutral-500'">
                                {{ r.severity === 'overdue' ? '⚠ ' : '' }}{{ fmtDate(r.due_date) }}
                            </span>
                            <Link v-if="r.ref_id" :href="`/bookings/${r.ref_id}`" class="text-primary-600 hover:underline text-xs">Lihat →</Link>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- ════ MODULE TILES ════ -->
        <div class="card p-5">
            <h2 class="font-semibold mb-4">Ringkasan Semua Modul</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-7 gap-3">
                <Link v-for="(t, i) in metrics.module_tiles" :key="i" :href="t.url"
                      class="group rounded-xl border border-neutral-100 dark:border-neutral-800 p-3 hover:shadow-md hover:-translate-y-0.5 transition bg-white dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <span class="p-1.5 rounded-lg" :style="{ background: groupColor[t.group] + '1a' }">
                            <component :is="groupIcon[t.group]" class="h-4 w-4" :style="{ color: groupColor[t.group] }" />
                        </span>
                        <ArrowRight class="h-3.5 w-3.5 text-neutral-300 group-hover:text-primary-500 transition" />
                    </div>
                    <p class="text-2xl font-extrabold mt-2 tabular-nums">{{ t.count }}</p>
                    <p class="text-xs text-neutral-500 truncate">{{ t.label }}</p>
                </Link>
            </div>
        </div>

        <!-- ════ Recent activity ════ -->
        <div v-if="metrics.recent_activity.length" class="card p-5">
            <h2 class="font-semibold mb-3 flex items-center gap-2"><Activity class="h-4 w-4 text-primary-600" /> Aktivitas Terbaru</h2>
            <ul class="space-y-2">
                <li v-for="(a, i) in metrics.recent_activity" :key="i" class="flex items-start gap-3 text-sm">
                    <span class="mt-1 w-2 h-2 rounded-full bg-primary-500 shrink-0"></span>
                    <div class="min-w-0">
                        <p class="text-neutral-700 dark:text-neutral-200 truncate">{{ a.description }}</p>
                        <p class="text-xs text-neutral-400">
                            <span class="uppercase tracking-wide">{{ a.category }}</span>
                            <span v-if="a.subject"> · {{ a.subject }}</span> · {{ relTime(a.when) }}
                        </p>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.funnel-bar { animation: grow 1s cubic-bezier(.4,0,.2,1) both; animation-delay: var(--d); }
@keyframes grow { from { width: 0; } }
@media (prefers-reduced-motion: reduce) { .funnel-bar { animation: none; } }
</style>
