<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    LayoutDashboard, Users, Building2, ClipboardList, Wallet, FileText, Receipt, FileSignature,
    BarChart3, Settings, ChevronLeft, ChevronDown, Phone, AlertTriangle, FileWarning, FileCheck,
    Vote, Hand, Star, Banknote, ScrollText, Award, RefreshCcw, CircleDollarSign, Calculator,
    FileBarChart, ShieldCheck, Percent, Landmark, Briefcase, BookOpen, Truck,
    Boxes, ShoppingCart, PackagePlus, PackageMinus, LayoutList, BookText,
    HandCoins, Coins, Building, PieChart, HardHat, Target,
    FolderGit2, CalendarClock, TriangleAlert, Lock,
    Settings2, Layers, Handshake, Tag, BellRing, FileCog,
    GitMerge, FileSpreadsheet, IdCard, Gift, Eraser, SlidersHorizontal, DatabaseZap, Database, UserPlus,
} from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits<{ (e: 'close'): void }>();

const page = usePage();

// Grup submodul bisa di-collapse (dropdown) — disimpan di localStorage.
const collapsed = ref<Record<string, boolean>>(JSON.parse(localStorage.getItem('sidebarCollapsed') || '{}'));
function toggleGroup(title: string) {
    collapsed.value[title] = !collapsed.value[title];
    localStorage.setItem('sidebarCollapsed', JSON.stringify(collapsed.value));
}

// Sub-modul (level 2) yang punya children bisa dibuka untuk menampilkan detail modul (level 3).
const openSub = ref<Record<string, boolean>>({});
function toggleSub(key: string) { openSub.value[key] = !openSub.value[key]; }

interface NavItem {
    label: string;
    icon?: any;
    href?: string;
    external?: string; // URL absolut (mis. kembali ke FO J-ERP) → render <a>, bukan <Link>
    params?: Record<string, any>;
    permission?: string | string[];
    children?: NavItem[]; // detail modul (level 3) — bila ada, item jadi sub-modul yang bisa dibuka
}

const groups: { title: string; items: NavItem[] }[] = [
    {
        title: '',
        items: [
            { label: 'Dashboard',  icon: LayoutDashboard, href: 'dashboard' },
            { label: 'Kalkulator', icon: Calculator,      href: 'calculator.index' },
            // Menu silang → kembali ke hub/dashboard J-ERP (URL dari env via Inertia config.fo_url).
            { label: 'Kembali ke Dashboard J-ERP', icon: Building2, external: ((page.props as any).config?.fo_url ?? '') + '/dashboard' },
        ],
    },
    {
        title: 'Master',
        items: [
            { label: 'Unit',     icon: Building2, href: 'units.index',     permission: 'master.unit.view' },
        ],
    },
    {
        title: 'Data Center',
        items: [
            { label: 'Customer Center', icon: Users,  href: 'bs.dc.customer.center', permission: 'master.customer.view' },
            { label: 'Supplier Center', icon: Truck,  href: 'bs.dc.supplier.center', permission: ['businesssupport.view', 'datacenter.*'] },
            { label: 'Employee Center', icon: IdCard, href: 'bs.dc.employee.center', permission: ['businesssupport.view', 'datacenter.*'] },
        ],
    },
    // ── FO (Sales / Finance / Collection) DINONAKTIFKAN ──────────────────────────
    //    FO dikelola di J-ERP (Filament). Data customer/unit/SPR/TTS disinkron dari
    //    J-ERP → ipro_bo. Menu FO disembunyikan agar BO/Bankloan saja yang tampil.
    {
        title: 'KPR',
        items: [
            { label: 'Kategori Retensi', icon: Percent,       href: 'kpr.setup.retensi.index', permission: 'bankloan.setup.view' },
            { label: 'Kategori Bank',    icon: Landmark,      href: 'kpr.setup.bank.index',    permission: 'bankloan.setup.view' },
            { label: 'Inisiasi KPR',     icon: GitMerge,      href: 'kpr.initiation.index',    permission: 'bankloan.application.view' },
            { label: 'Proses KPR',       icon: Banknote,      href: 'kpr.index',               permission: 'bankloan.application.view' },
            { label: 'Database KPR',     icon: Database, permission: 'bankloan.application.view', children: [
                { label: 'Reject BI Checking', href: 'kpr.database.reject-bi',  permission: 'bankloan.application.view' },
                { label: 'Reject Proses KPR',  href: 'kpr.database.reject-kpr', permission: 'bankloan.application.view' },
            ]},
            { label: 'Cek Scan KTP & NPWP (belum jadi)', icon: IdCard, href: 'kpr.scan.index', permission: 'bankloan.application.view' },
        ],
    },
    {
        title: 'Business Support',
        items: [
            { label: 'Overview',          icon: Briefcase,    href: 'bs.index',              permission: ['businesssupport.view', 'ap.*', 'ar.*', 'gl.*', 'cashbook.*', 'budget.*', 'contractor.*', 'cogs.*', 'tax.*', 'datacenter.*', 'bs.*'] },
            { label: 'Proyeksi Hutang/Piutang', icon: BookText, href: 'bs.projection', params: { mode: 'hutang' }, permission: ['businesssupport.view', 'ap.*', 'ar.*'] },
            { label: 'Account Receivable',icon: Coins,        href: 'bs.ar.index',           permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Booked Revenue',    icon: CircleDollarSign, href: 'bs.booked-revenue.index', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Aset Tetap',        icon: Building,     href: 'bs.fa.index',           permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Payroll',           icon: Banknote,    href: 'bs.payroll.index',      permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Item / Stok',       icon: Boxes,        href: 'bs.items.index',        permission: ['businesssupport.view', 'bs.*'] },
            { label: 'FPB (Permintaan)',  icon: ClipboardList,href: 'bs.fpb.index',          permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Purchase Order',    icon: ShoppingCart, href: 'bs.po.index',           permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Penerimaan',        icon: PackagePlus,  href: 'bs.receipt.index',      permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Pengeluaran',       icon: PackageMinus, href: 'bs.issue.index',        permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Kartu Stok',        icon: LayoutList,   href: 'bs.stockcard.index',    permission: ['businesssupport.view', 'bs.*'] },
        ],
    },
    {
        title: 'General Ledger',
        items: [
            { label: 'General Function', icon: FileText, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'General Function', href: 'bs.gl.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Transaction Rule', href: 'bs.gl.transaction-rule.index' },
                { label: 'Multi Currency', href: 'bs.gl.currency.index' },
                { label: 'Kurs Sistem', href: 'bs.gl.kurs.index' },
                { label: 'Repair Data', href: 'bs.gl.repair.index' },
                { label: 'Kategori Cash Flow Direct', href: 'bs.gl.cf-category.index', params: { kind: 'direct' } },
                { label: 'Kategori Cash Flow Indirect', href: 'bs.gl.cf-category.index', params: { kind: 'indirect' } },
            ]},
            { label: 'Master', icon: BookText, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Chart of Account', href: 'bs.accounts.index' },
                { label: 'Jurnal', href: 'bs.gl.journal.index' },
                { label: 'Cost Center', href: 'bs.cost-centers.index' },
                { label: 'Mapping Cash Flow Direct', href: 'bs.gl.cf-mapping.index' },
                { label: 'Dimensi Jurnal', href: 'bs.journal-dimensions.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Detail Transaksi Jurnal', href: 'bs.gl.journal.index' },
                { label: 'General Ledger', href: 'bs.gl.ledger' },
            ]},
            { label: 'Neraca', icon: Calculator, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Neraca Saldo', href: 'bs.gl.trialbalance' },
                { label: 'Neraca', href: 'bs.gl.balancesheet' },
                { label: 'Neraca Lajur', href: 'bs.gl.worksheet' },
                { label: 'Histori Saldo', href: 'bs.gl.extra', params: { mode: 'histori' } },
                { label: 'Neraca Multi Periode', href: 'bs.gl.extra', params: { mode: 'multi' } },
                { label: 'Neraca Komparasi (Bulanan)', href: 'bs.gl.comparative' },
                { label: 'Neraca Komparasi (Tahunan)', href: 'bs.gl.extra', params: { mode: 'tahunan' } },
            ]},
            { label: 'Laba Rugi', icon: BarChart3, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Laba Rugi', href: 'bs.gl.incomestatement' },
                { label: 'Laba Rugi vs Budget', href: 'bs.gl.lr-budget' },
                { label: 'Laba Rugi Multi Periode', href: 'bs.gl.extra', params: { mode: 'lr-multi' } },
                { label: 'Laba Rugi Komparasi (Bulanan)', href: 'bs.gl.comparative' },
                { label: 'Laba Rugi Komparasi (Tahunan)', href: 'bs.gl.extra', params: { mode: 'lr-tahunan' } },
                { label: 'Laba Rugi Perbandingan', href: 'bs.gl.extra', params: { mode: 'lr-banding' } },
            ]},
            { label: 'Cash Flow Direct', icon: PieChart, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Cash Flow Direct Multi Periode', href: 'bs.gl.cashflow', params: { method: 'direct' } },
            ]},
            { label: 'Cash Flow Indirect', icon: PieChart, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Cash Flow Indirect Multi Periode', href: 'bs.gl.cashflow', params: { method: 'indirect' } },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Input Saldo Awal', href: 'bs.closing.saldoawal' },
                { label: 'Confirm Saldo Awal', href: 'bs.gl.saldo-awal.confirm' },
            ]},
            { label: 'Jurnal Memorial', icon: FileText, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Registrasi Jurnal Memorial', href: 'bs.gl.journal.create' },
                { label: 'Import Jurnal', href: 'bs.gl.journal.import' },
            ]},
            { label: 'Generate Lap. Keuangan', icon: FileCheck, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Finalisasi Jurnal', href: 'bs.gl.journal.index', params: { stage: 'finalisasi' } },
                { label: 'Approval Jurnal', href: 'bs.gl.journal.index', params: { stage: 'approval' } },
                { label: 'Late Closing', href: 'bs.gl.late-closing' },
            ]},
            { label: 'Generate Lap. Perusahaan', icon: Lock, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Closing Fiskal', href: 'bs.closing.index' },
            ]},
        ],
    },
    {
        title: 'Account Payable',
        items: [
            { label: 'General Function', icon: FileText, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Print-Out', href: 'bs.ap.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Transaction Rule', href: 'bs.gl.transaction-rule.index', params: { source: 'ap' } },
                { label: 'Kategori Invoice', href: 'bs.ap.categories.index' },
                { label: 'Kategori Gimmick', href: 'bs.gimmick.categories.index' },
                { label: 'Repair Data', href: 'bs.ap.repair.index' },
                { label: 'Kelengkapan Berkas', href: 'bs.ap.berkas.index' },
            ]},
            { label: 'Master', icon: BookText, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Invoice Hutang', href: 'bs.ap.index' },
                { label: 'Kuitansi Hutang', href: 'bs.ap.kuitansi.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Kartu Hutang', href: 'bs.ap.card' },
                { label: 'Aging Hutang', href: 'bs.ap.aging', params: { view: 'detail' } },
                { label: 'Proyeksi Hutang', href: 'bs.projection', params: { mode: 'hutang' } },
                { label: 'Kartu Pembayaran Hutang', href: 'bs.ap.payment-card' },
                { label: 'Laporan Invoice Hutang', href: 'bs.ap.report' },
                { label: 'Laporan Penghapusan Hutang', href: 'bs.ap.penghapusan.report' },
            ]},
            { label: 'Generate Invoice', icon: ClipboardList, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Registrasi Invoice Hutang', href: 'bs.ap.create' },
            ]},
            { label: 'Penyesuaian Invoice', icon: Eraser, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Penghapusan Nilai Hutang', href: 'bs.ap.penghapusan.index' },
            ]},
            { label: 'Posting Invoice PO', icon: FileCheck, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Posting Invoice', href: 'bs.ap.posting.index', params: { source: 'po' } },
            ]},
            { label: 'Posting Invoice SPK', icon: FileCheck, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Posting Invoice', href: 'bs.ap.posting.index', params: { source: 'spk' } },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Saldo Awal', href: 'bs.ap.opening.index' },
            ]},
        ],
    },
    {
        title: 'Cash Book',
        items: [
            { label: 'General Function', icon: FileText, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Print-Out', href: 'bs.cashbook.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Transaction Rule', href: 'bs.gl.transaction-rule.index', params: { source: 'cash' } },
                { label: 'Rekening', href: 'rekening.index' },
                { label: 'Repair Data', href: 'bs.cashbook.repair.index' },
            ]},
            { label: 'Master', icon: Wallet, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Transfer Rekening', href: 'bs.cashbook.index', params: { type: 'transfer' } },
                { label: 'Kas Masuk', href: 'bs.cashbook.index', params: { type: 'in' } },
                { label: 'Kas Keluar', href: 'bs.cashbook.index', params: { type: 'out' } },
                { label: 'Kas Kecil Masuk', href: 'bs.pettycash.index', params: { type: 'in' } },
                { label: 'Kas Kecil Keluar', href: 'bs.pettycash.index', params: { type: 'out' } },
                { label: 'Cek Giro Masuk', href: 'bs.giro.index' },
                { label: 'Cek Giro Keluar', href: 'bs.cheque-out.index' },
                { label: 'Realisasi Bon Sementara', href: 'bs.advance.index' },
            ]},
            { label: 'Report/Query', icon: FileBarChart, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Rekening Ledger', href: 'bs.cashbook.ledger' },
                { label: 'Rekening Saldo', href: 'bs.cashbook.balances' },
                { label: 'Kalender Cek Giro', href: 'bs.cashbook.giro-calendar' },
                { label: 'Pengeluaran Kas & Bank', href: 'bs.cashbook.index', params: { type: 'out' } },
                { label: 'Kartu Bon Sementara', href: 'bs.advance.card' },
                { label: 'Rekap Bon Sementara', href: 'bs.advance.report' },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Saldo Awal Rekening', href: 'bs.cashbook.opening.index' },
            ]},
            { label: 'Transaksi Pengeluaran Bank', icon: Banknote, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Registrasi Pengeluaran Kas & Bank', href: 'bs.cashbook.create', params: { type: 'out' } },
                { label: 'Registrasi Pengeluaran Kas & Bank (AP)', href: 'bs.ap.kuitansi.create' },
                { label: 'Approval Pengeluaran Kas & Bank', href: 'bs.cashbook.approvals' },
                { label: 'SIP (Instruksi Bayar)', href: 'bs.sip.index', permission: ['businesssupport.view', 'bs.*'] },
            ]},
            { label: 'Transaksi Penerimaan Bank', icon: Landmark, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Transfer Antar Rekening', href: 'bs.cashbook.create', params: { type: 'transfer' } },
                { label: 'Penerimaan Bank', href: 'bs.cashbook.create', params: { type: 'in' } },
                { label: 'Approval Retensi', href: 'bs.cashbook.approval-retensi' },
                { label: 'Approval UTJ & DP', href: 'bs.cashbook.approval-utjdp' },
            ]},
            { label: 'Transaksi Bon Sementara', icon: HandCoins, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Pengeluaran Bon Sementara', href: 'bs.advance.payments' },
                { label: 'Realisasi Bon Sementara', href: 'bs.advance.index' },
            ]},
            { label: 'Transaksi Kas Kecil', icon: Wallet, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Penerimaan Kas Kecil', href: 'bs.pettycash.create', params: { type: 'in' } },
                { label: 'Pengeluaran Kas Kecil', href: 'bs.pettycash.create', params: { type: 'out' } },
            ]},
            { label: 'Transaksi Cek Giro Masuk', icon: FileText, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Penerimaan Cek Giro', href: 'bs.giro.create' },
                { label: 'Kliring Cek Giro Masuk', href: 'bs.giro.index' },
                { label: 'Tolakan Cek Giro Masuk', href: 'bs.giro.index' },
            ]},
            { label: 'Transaksi Cek Giro Keluar', icon: FileText, permission: ['businesssupport.view', 'cashbook.*'], children: [
                { label: 'Data BG/Cek Keluar', href: 'bs.cheque-out.index' },
            ]},
        ],
    },
    {
        title: 'Budget Control',
        items: [
            { label: 'General Function', icon: FileText, permission: ['businesssupport.view', 'budget.*'], children: [
                { label: 'Print-Out', href: 'bs.budget.printhub' },
            ]},
            { label: 'Master', icon: BookText, permission: ['businesssupport.view', 'budget.*'], children: [
                { label: 'Budget Control', href: 'bs.budget.index' },
                { label: 'Alokasi Budget Control', href: 'bs.budget.allocation.index' },
                { label: 'Koreksi Budget Control', href: 'bs.budget.correction.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['businesssupport.view', 'budget.*'], children: [
                { label: 'Master Budget', href: 'bs.budget.query.master' },
                { label: 'Koreksi Budget', href: 'bs.budget.query.correction' },
                { label: 'Realisasi Budget', href: 'bs.budget.query.realization' },
                { label: 'Alokasi Budget', href: 'bs.budget.query.allocation' },
            ]},
            { label: 'Upload Budget', icon: FileSpreadsheet, permission: ['businesssupport.view', 'budget.*'], children: [
                { label: 'Upload Budget', href: 'bs.budget.upload.index' },
                { label: 'Approval Budget', href: 'bs.budget.approvals' },
            ]},
            { label: 'Alokasi Budget', icon: Layers, permission: ['businesssupport.view', 'budget.*'], children: [
                { label: 'Alokasi Budget', href: 'bs.budget.allocation.create' },
                { label: 'Approval Alokasi Budget', href: 'bs.budget.allocation.approvals' },
            ]},
            { label: 'Koreksi Budget', icon: ShieldCheck, permission: ['businesssupport.view', 'budget.*'], children: [
                { label: 'Koreksi Budget', href: 'bs.budget.correction.create' },
                { label: 'Approval Koreksi Budget', href: 'bs.budget.correction.approvals' },
            ]},
        ],
    },
    {
        title: 'Contractor Management',
        items: [
            { label: 'Setup', icon: FolderGit2, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Project', href: 'bs.spk.project.index' },
            ]},
            { label: 'Master', icon: FileSignature, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Surat Perintah Kerja', href: 'bs.spk.index' },
                { label: 'Kontrol Progress Lapangan', href: 'bs.spk.kpl.index' },
                { label: 'Pemakaian Barang SBO', href: 'bs.spk.material.index' },
                { label: 'Progress Retensi', href: 'bs.spk.retention.index' },
                { label: 'Mutasi Progress Bangunan', href: 'bs.spk.mutation.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Rekap SPK', href: 'bs.spk.query.rekap' },
                { label: 'Kartu Hutang Supplier - SPK', href: 'bs.spk.query.kartu-hutang' },
                { label: 'Pemakaian Barang', href: 'bs.spk.query.pemakaian' },
                { label: 'Summary SPK', href: 'bs.spk.query.summary' },
            ]},
            { label: 'Surat Perintah Kerja', icon: ClipboardList, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Registrasi Surat Perintah Kerja', href: 'bs.spk.create' },
                { label: 'Registrasi Kontrol Progress Lapangan', href: 'bs.spk.kpl.create' },
                { label: 'Registrasi Retensi', href: 'bs.spk.retention.create' },
                { label: 'Addendum SPK (Tambah / Kurang)', href: 'bs.spk.addendum.form' },
                { label: 'Adjustment PPN', href: 'bs.spk.adjust-ppn.form' },
                { label: 'Pembatalan Surat Perintah Kerja', href: 'bs.spk.cancel.form' },
            ]},
            { label: 'Pemakaian Barang SBO', icon: PackageMinus, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Pemakaian Barang', href: 'bs.spk.material.create' },
                { label: 'Close SBO', href: 'bs.spk.material.close-form' },
                { label: 'Pengembalian Sisa Pemakaian', href: 'bs.spk.material.return-form' },
            ]},
            { label: 'Mutasi Progress', icon: GitMerge, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Registrasi Mutasi Progress Bangunan', href: 'bs.spk.mutation.create' },
                { label: 'Approval Mutasi Progress Bangunan', href: 'bs.spk.mutation.approvals' },
            ]},
        ],
    },
    {
        title: 'COGS',
        items: [
            { label: 'General Function', icon: FileText, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Print-Out', href: 'bs.cogs.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Lokasi', href: 'bs.cogs.location.index' },
                { label: 'Ketentuan', href: 'bs.cogs.provision.index' },
                { label: 'Budget', href: 'bs.cogs.budget.index' },
                { label: 'Set Akun Budget', href: 'bs.cogs.category.index' },
                { label: 'Set COA Jenis Unit', href: 'bs.cogs.unitcoa.index' },
                { label: 'Upload Kategori Budget', href: 'bs.cogs.upload.form', params: { kind: 'kategori' } },
                { label: 'Upload Akun Budget', href: 'bs.cogs.upload.form', params: { kind: 'akun' } },
                { label: 'Upload Nilai Budget', href: 'bs.cogs.upload.form', params: { kind: 'nilai' } },
            ]},
            { label: 'Master', icon: BookText, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Mutasi Budget', href: 'bs.cogs.mutation.index' },
                { label: 'HPP RealCost', href: 'bs.cogs.hpp.index' },
                { label: 'Saldo Awal Realisasi', href: 'bs.cogs.realization.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Report Budget', href: 'bs.cogs.query.budget' },
                { label: 'Report Realisasi Budget', href: 'bs.cogs.query.realisasi' },
                { label: 'Report Realisasi Budget Detil', href: 'bs.cogs.query.realisasi-detil' },
                { label: 'Report Pengakuan HPP RealCost', href: 'bs.cogs.query.pengakuan' },
                { label: 'Report Persediaan Unit RealCost', href: 'bs.cogs.query.persediaan-unit' },
                { label: 'Report HPP Unit RealCost', href: 'bs.cogs.query.hpp-unit' },
            ]},
            { label: 'Mutasi Budget', icon: GitMerge, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Registrasi Mutasi Budget', href: 'bs.cogs.mutation.create' },
                { label: 'Approval Mutasi Budget', href: 'bs.cogs.mutation.approvals' },
            ]},
            { label: 'Transaksi HPP RealCost', icon: Calculator, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Generate HPP', href: 'bs.cogs.hpp.generate' },
                { label: 'Posting Jurnal HPP Persediaan', href: 'bs.cogs.hpp.post-persediaan' },
                { label: 'Posting Jurnal HPP', href: 'bs.cogs.hpp.post-hpp' },
            ]},
            { label: 'Saldo Awal Realisasi', icon: Coins, permission: ['businesssupport.view', 'cogs.*'], children: [
                { label: 'Input Saldo Awal Realisasi', href: 'bs.cogs.realization.create' },
            ]},
        ],
    },
    // ── FO (Legal / Komisi) DINONAKTIFKAN — dikelola di J-ERP. ───────────────────
    {
        title: 'Approval',
        items: [
            { label: 'Approval Center', icon: Vote, href: 'approvals.index', permission: 'approval.view' },
        ],
    },
    {
        title: 'Reports',
        items: [
            { label: 'Laporan',         icon: FileBarChart, href: 'reports.index',     permission: 'reporting.report.export' },
            { label: 'Export Customer', icon: BarChart3,    href: 'exports.customers', permission: 'master.customer.export' },
            { label: 'Export Booking',  icon: BarChart3,    href: 'exports.bookings',  permission: 'reporting.report.export' },
        ],
    },
    {
        title: 'Setup Sales',
        items: [
            { label: 'Setup Sales',     icon: Settings2,     href: 'setup-sales.index',            permission: 'setupsales.view' },
            { label: 'Skema Cara Bayar',icon: Layers,        href: 'setup-sales.scheme.index',     permission: 'setupsales.view' },
            { label: 'Master Sales',    icon: Users,         href: 'setup-sales.sales.index',      permission: 'setupsales.view' },
            { label: 'Master Agen',     icon: Handshake,     href: 'setup-sales.agent.index',      permission: 'setupsales.view' },
            { label: 'Price List',      icon: Tag,           href: 'setup-sales.pricelist.index',  permission: 'setupsales.view' },
            { label: 'Dashboard Sales', icon: LayoutDashboard, href: 'setup-sales.dashboard',      permission: 'setupsales.view' },
            { label: 'Laporan Sales',   icon: FileBarChart,  href: 'setup-sales.reports.index',    permission: 'setupsales.view' },
            { label: 'Reminder Sales',  icon: BellRing,      href: 'setup-sales.reminder',         permission: 'setupsales.view' },
        ],
    },
    {
        title: 'Security',
        items: [
            { label: 'Security',        icon: ShieldCheck,   href: 'security.index',                permission: 'security.view' },
            { label: 'Matrikulasi Perusahaan', icon: Building2, href: 'security.matrikulasi.company', permission: 'security.view' },
            { label: 'Matrikulasi Project',    icon: FolderGit2, href: 'security.matrikulasi.project', permission: 'security.view' },
            { label: 'Absensi',         icon: CalendarClock, href: 'security.absensi',              permission: 'security.view' },
            { label: 'Job History',     icon: Briefcase,     href: 'security.jobhistory',           permission: 'security.view' },
            { label: 'Technical Problem',icon: TriangleAlert,href: 'security.technical',            permission: 'security.view' },
            { label: 'Security Log',    icon: Lock,          href: 'security.log',                  permission: 'security.view' },
        ],
    },
    {
        title: 'System',
        items: [
            { label: 'Settings', icon: Settings, href: 'settings.index', permission: 'system.user.view' },
            { label: 'Konfigurasi Sistem', icon: SlidersHorizontal, href: 'settings.config.index', permission: 'system.setup.parameter' },
        ],
    },
];

function hasPermission(perm?: string | string[]): boolean {
    if (! perm) return true;
    const perms: string[] = page.props.auth.user?.permissions ?? [];
    const list = Array.isArray(perm) ? perm : [perm];
    // Dukung wildcard modul, mis. 'ap.*' = punya permission granular apa pun pada modul 'ap'.
    return list.some(p => p.endsWith('.*')
        ? perms.some(x => x.startsWith(p.slice(0, -1)))
        : perms.includes(p));
}

function isActive(href?: string, params?: Record<string, any>): boolean {
    if (! href) return false;
    try { return (route() as unknown as { current: (n: string, p?: any) => boolean }).current(href, params); }
    catch { return false; }
}

// #128: resolve href dengan aman — bila nama route tidak dikenal (mis. bundle lama), kembalikan null
// agar Link tidak melempar saat render & link tidak "mati senyap".
function safeRoute(href?: string, params?: Record<string, any>): string | null {
    if (! href) return null;
    try { return route(href, params); }
    catch { return null; }
}
</script>

<template>
    <aside
        class="bg-primary-100 text-primary-900 border-r border-primary-200 flex-shrink-0 transition-all duration-300 overflow-y-auto"
        :class="open ? 'w-64' : 'w-16'"
    >
        <div class="flex items-center justify-between px-4 h-16 border-b border-primary-200 sticky top-0 bg-primary-100 z-10">
            <span v-if="open" class="text-base font-bold text-primary-900">Java Core System</span>
            <button @click="$emit('close')" class="text-primary-700 hover:text-primary-900">
                <ChevronLeft class="h-5 w-5" :class="{ 'rotate-180': ! open }" />
            </button>
        </div>

        <nav class="py-3">
            <template v-for="g in groups" :key="g.title">
                <button v-if="g.title && open && g.items.some(i => hasPermission(i.permission))"
                    type="button" @click="toggleGroup(g.title)"
                    class="flex w-full items-center justify-between px-4 mt-4 mb-1 text-[12px] uppercase tracking-wider text-primary-700 font-extrabold hover:text-primary-800">
                    <span>{{ g.title }}</span>
                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ '-rotate-90': collapsed[g.title] }" />
                </button>
                <template v-for="item in g.items" :key="(item.href ?? item.label) + JSON.stringify(item.params ?? {})">
                    <template v-if="hasPermission(item.permission) && (!g.title || !collapsed[g.title] || !open)">
                        <!-- Sub-modul dengan children (level 2) → klik untuk buka detail modul (level 3) -->
                        <template v-if="item.children">
                            <button
                                type="button" @click="toggleSub(g.title + '|' + item.label)"
                                class="flex w-full items-center gap-3 px-4 py-2 mx-2 rounded-lg text-[15px] font-semibold text-primary-900 transition hover:bg-primary-300 hover:text-primary-950"
                                :style="{ width: 'calc(100% - 1rem)' }"
                            >
                                <component :is="item.icon" class="h-[18px] w-[18px] flex-shrink-0" />
                                <span v-if="open" class="flex-1 text-left">{{ item.label }}</span>
                                <ChevronDown v-if="open" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': openSub[g.title + '|' + item.label] }" />
                            </button>
                            <template v-if="open && openSub[g.title + '|' + item.label]">
                                <template v-for="c in item.children" :key="c.label + JSON.stringify(c.params ?? {})">
                                    <Link
                                        v-if="hasPermission(c.permission) && safeRoute(c.href, c.params)"
                                        :href="safeRoute(c.href, c.params) ?? '#'"
                                        class="flex items-center gap-2 py-1.5 pl-11 pr-4 mx-2 rounded-lg text-sm transition"
                                        :class="isActive(c.href, c.params) ? 'bg-primary-500 text-primary-950 font-bold' : 'text-primary-800 font-medium hover:bg-primary-300 hover:text-primary-950'"
                                    >
                                        <span v-if="open">{{ c.label }}</span>
                                    </Link>
                                </template>
                            </template>
                        </template>
                        <!-- Link eksternal (mis. kembali ke FO J-ERP) -->
                        <a
                            v-else-if="item.external"
                            :href="item.external"
                            class="flex items-center gap-3 px-4 py-2 mx-2 rounded-lg text-[15px] font-semibold text-primary-900 transition hover:bg-primary-300 hover:text-primary-950"
                        >
                            <component :is="item.icon" class="h-[18px] w-[18px] flex-shrink-0" />
                            <span v-if="open">{{ item.label }}</span>
                        </a>
                        <!-- Item biasa (leaf) -->
                        <Link
                            v-else-if="safeRoute(item.href, item.params)"
                            :href="safeRoute(item.href, item.params) ?? '#'"
                            class="flex items-center gap-3 px-4 py-2 mx-2 rounded-lg text-[15px] transition"
                            :class="isActive(item.href, item.params)
                                ? 'bg-primary-500 text-primary-950 font-bold'
                                : 'text-primary-900 font-semibold hover:bg-primary-300 hover:text-primary-950'"
                        >
                            <component :is="item.icon" class="h-[18px] w-[18px] flex-shrink-0" />
                            <span v-if="open">{{ item.label }}</span>
                        </Link>
                    </template>
                </template>
            </template>
        </nav>
    </aside>
</template>
