// Struktur navigasi bersama — dipakai Sidebar (kiri) & Menu Utama pada Dashboard.
// Sumber tunggal daftar modul & sub-modul agar keduanya selalu sinkron.
import {
    LayoutDashboard, Users, Building2, ClipboardList, Wallet, FileText, Receipt, ReceiptText, FileSignature,
    BarChart3, Settings, ChevronLeft, ChevronDown, Phone, AlertTriangle, FileWarning, FileCheck,
    Hand, Star, Banknote, ScrollText, Award, RefreshCcw, CircleDollarSign, Calculator,
    FileBarChart, ShieldCheck, Percent, Landmark, Briefcase, BookOpen, Truck,
    Boxes, ShoppingCart, PackagePlus, PackageMinus, LayoutList, BookText,
    HandCoins, Coins, Building, PieChart, HardHat, Target, PiggyBank,
    FolderGit2, CalendarClock, TriangleAlert, Lock,
    Layers, FileCog,
    GitMerge, FileSpreadsheet, IdCard, Gift, Eraser, SlidersHorizontal, DatabaseZap, Database, UserPlus,
    Scale,
} from 'lucide-vue-next';

export interface NavItem {
    label: string;
    icon?: any;
    href?: string;
    external?: string; // URL absolut (mis. kembali ke FO J-ERP) → render <a>, bukan <Link>
    params?: Record<string, any>;
    permission?: string | string[];
    children?: NavItem[]; // detail modul (level 3) — bila ada, item jadi sub-modul yang bisa dibuka
}

/**
 * Satu grup = satu MODUL. Selain dipakai Sidebar, metadata di bawah menjadi sumber
 * kartu modul pada "Menu Utama" (Dashboard) — sehingga menambah/menghapus/mengubah
 * modul cukup di file ini saja dan kedua tampilan otomatis ikut berubah.
 */
export interface NavGroup {
    title: string;      // judul grup = nama modul (dipakai mencocokkan kartu ↔ sub-modul)
    items: NavItem[];
    icon?: any;         // ikon kartu Menu Utama
    color?: string;     // warna aksen kartu
    desc?: string;      // deskripsi singkat pada kartu
    route?: string;     // route landing kartu; bila kosong → pakai sub-modul pertama yang dapat diakses
}

export function navGroups(foUrl: string): NavGroup[] {
    return [
    {
        title: '',
        items: [
            { label: 'Menu Utama', icon: LayoutDashboard, href: 'dashboard' },
            // Menu silang → kembali ke hub/dashboard utama J-ERP (URL dari env via Inertia config.fo_url).
            { label: 'Kembali ke Dashboard Utama', icon: Building2, external: foUrl + '/dashboard' },
        ],
    },
    {
        title: 'Master',
        icon: Building2, color: '#ff8c00', route: 'units.index',
        desc: 'Data unit & master properti.',
        items: [
            { label: 'Unit',     icon: Building2, href: 'units.index',     permission: 'master.unit.view' },
        ],
    },
    {
        title: 'Data Center',
        icon: Database, color: '#0ea5e9', route: 'bs.dc.supplier.center',
        // Customer Center DIHILANGKAN: data customer dikelola lewat sub-modul Customer di
        // Marketing & Sales (FO/J-ERP), lalu disinkron ke BO — tidak dikelola dua kali di sini.
        desc: 'Pusat data Supplier & Karyawan.',
        items: [
            { label: 'Supplier Center', icon: Truck,  href: 'bs.dc.supplier.center', permission: ['businesssupport.view', 'datacenter.*'] },
            { label: 'Employee Center', icon: IdCard, href: 'bs.dc.employee.center', permission: ['businesssupport.view', 'datacenter.*'] },
        ],
    },
    // ── FO (Sales / Finance / Collection) DINONAKTIFKAN ──────────────────────────
    //    FO dikelola di J-ERP (Filament). Data customer/unit/SPR/TTS disinkron dari
    //    J-ERP → ipro_bo. Menu FO disembunyikan agar BO/Bankloan saja yang tampil.
    {
        title: 'KPR',
        icon: Banknote, color: '#10b981', route: 'kpr.index',
        desc: 'Inisiasi & Proses KPR bank (Bank Loan).',
        items: [
            // Set Up — kategori/master parameter KPR.
            { label: 'Set Up', icon: SlidersHorizontal, permission: 'bankloan.setup.view', children: [
                { label: 'Kategori Retensi',        href: 'kpr.setup.retensi.index', permission: 'bankloan.setup.view' },
                { label: 'Kategori Bank',           href: 'kpr.setup.bank.index',    permission: 'bankloan.setup.view' },
                { label: 'Kategori Analisa Berkas', href: 'kpr.setup.analisa.index', permission: 'bankloan.setup.view' },
                { label: 'Kategori Berkas Tidak Lengkap', href: 'kpr.setup.berkas-tidak-lengkap.index', permission: 'bankloan.setup.view' },
            ]},
            // Transaction — proses transaksi KPR.
            { label: 'Transaction', icon: GitMerge, permission: 'bankloan.application.view', children: [
                { label: 'Inisiasi KPR',                     href: 'kpr.initiation.index', permission: 'bankloan.application.view' },
                { label: 'Proses KPR',                       href: 'kpr.index',            permission: 'bankloan.application.view' },
                // "Cek Scan KTP & NPWP" DIHILANGKAN dari menu (belum jadi). Route kpr.scan.*,
                // controller, dan halamannya sengaja DIBIARKAN utuh — tinggal munculkan lagi
                // barisnya di sini bila fiturnya sudah siap dipakai.
            ]},
            // Query/Report — master data & arsip Database KPR (Reject BI Checking / Reject Proses KPR).
            { label: 'Query/Report', icon: FileBarChart, permission: 'bankloan.application.view', children: [
                { label: 'Master Data KPR',    href: 'kpr.master-data.index',    permission: 'bankloan.application.view' },
                { label: 'Reject BI Checking', href: 'kpr.database.reject-bi',  permission: 'bankloan.application.view' },
                { label: 'Reject Proses KPR',  href: 'kpr.database.reject-kpr', permission: 'bankloan.application.view' },
            ]},
        ],
    },
    // Grup "Business Support" dibubarkan: Purchase Order → Purchasing; Item/FPB/Penerimaan/
    // Pengeluaran/Kartu Stok → Inventory; Account Receivable → grup mandiri (dekat Account Payable).
    // Overview, Proyeksi Hutang/Piutang & Payroll dihapus dari menu — halamannya tetap ada:
    // Proyeksi duplikat di grup Account Payable, Payroll via Data Center → Employee Center → Karyawan.
    {
        title: 'Purchasing',
        icon: ShoppingCart, color: '#f59e0b', route: 'bs.po.index',
        desc: 'Purchase Order ke supplier & rekapnya.',
        items: [
            { label: 'Purchase Order',     icon: ShoppingCart,  href: 'bs.po.index',           permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Report PO',          icon: ClipboardList, href: 'bs.po.report',          permission: ['businesssupport.view', 'bs.*'] },
        ],
    },
    {
        title: 'Inventory',
        icon: Boxes, color: '#eab308', route: 'bs.items.index',
        desc: 'Item/stok, FPB, penerimaan & pengeluaran barang.',
        items: [
            { label: 'Item / Stok',        icon: Boxes,         href: 'bs.items.index',        permission: ['businesssupport.view', 'bs.*'] },
            { label: 'FPB (Permintaan)',   icon: ClipboardList, href: 'bs.fpb.index',          permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Penerimaan Barang',  icon: PackagePlus,   href: 'bs.receipt.index',      permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Pengeluaran Barang', icon: PackageMinus,  href: 'bs.issue.index',        permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Kartu Stok',         icon: LayoutList,    href: 'bs.stockcard.index',    permission: ['businesssupport.view', 'bs.*'] },
        ],
    },
    {
        title: 'Booked Revenue',
        icon: CircleDollarSign, color: '#14b8a6', route: 'bs.booked-revenue.index',
        desc: 'Pendapatan diakui, BAST/AJB & Faktur Pajak.',
        items: [
            { label: 'Sudah Booked Revenue', icon: CircleDollarSign, href: 'bs.booked-revenue.index', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Belum Booked Revenue', icon: CircleDollarSign, href: 'bs.booked-revenue.belum', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Detail BAST', icon: FileCheck, href: 'bs.booked-revenue.control.bast', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Detail AJB', icon: FileText, href: 'bs.booked-revenue.control.ajb', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Verifikasi Faktur Pajak', icon: Receipt, permission: ['businesssupport.view', 'tax.*'], children: [
                { label: 'PPN Masukan', href: 'bs.tax.masukan' },
                { label: 'PPN Keluaran', href: 'bs.tax.keluaran' },
            ] },
            { label: 'Rincian Batal Akad', icon: TriangleAlert, href: 'bs.batal-akad.index', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Invoice Penjualan', icon: ReceiptText, href: 'bs.sales-invoice.index', permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Report / Query', icon: FileBarChart, href: 'bs.booked-revenue.report', permission: ['businesssupport.view', 'ar.*'] },
        ],
    },
    {
        title: 'Aset Tetap',
        icon: Building, color: '#06b6d4', route: 'bs.fa.index',
        desc: 'Registrasi & penyusutan aset tetap.',
        items: [
            { label: 'Overview Aset', icon: Building, href: 'bs.fa.index', permission: ['businesssupport.view', 'bs.*'] },
            { label: 'Master', icon: BookText, permission: ['businesssupport.view', 'bs.*'], children: [
                { label: 'Master Aset Tetap', href: 'bs.fa.index' },
            ] },
            { label: 'Report / Query', icon: FileBarChart, permission: ['businesssupport.view', 'bs.*'], children: [
                { label: 'Summary Aset', href: 'bs.fa.summary' },
                { label: 'Penjualan Aset', href: 'bs.fa.penjualan' },
            ] },
            { label: 'Registrasi', icon: ClipboardList, permission: ['businesssupport.view', 'bs.*'], children: [
                { label: 'Registrasi Aset', href: 'bs.fa.create' },
                { label: 'Registrasi Penyusutan', href: 'bs.fa.run' },
                { label: 'Upload Aset Massal', href: 'bs.bulk.form', params: { mode: 'asset' } },
            ] },
        ],
    },
    {
        title: 'General Ledger',
        icon: BookOpen, color: '#7c3aed', route: 'bs.gl.printhub',
        desc: 'Jurnal, Neraca, Laba Rugi & Cash Flow.',
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
                { label: 'Detail Transaksi Jurnal', href: 'bs.gl.journal.detail' },
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
                { label: 'Finalisasi Jurnal', href: 'bs.gl.journal.finalisasi' },
                { label: 'Approval Jurnal', href: 'bs.gl.journal.approval' },
                { label: 'Late Closing', href: 'bs.gl.late-closing' },
            ]},
            { label: 'Generate Lap. Perusahaan', icon: Lock, permission: ['businesssupport.view', 'gl.*'], children: [
                { label: 'Closing Fiskal', href: 'bs.closing.index' },
            ]},
        ],
    },
    {
        title: 'Account Receivable',
        icon: Coins, color: '#3b82f6', route: 'bs.ar.index',
        desc: 'Invoice, kuitansi & kartu piutang customer.',
        items: [
            { label: 'Invoice Piutang',     icon: Coins,            href: 'bs.ar.index',             permission: ['businesssupport.view', 'ar.*'] },
            { label: 'UM Penjualan',        icon: Wallet,           href: 'bs.ar.um.index',          permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Rekonsiliasi Piutang', icon: Scale,           href: 'bs.ar.rekonsiliasi.index', permission: ['businesssupport.view', 'ar.*'] },
            // "Registrasi Piutang" & "Kategori Invoice" DIHILANGKAN: invoice piutang tidak dibuat
            // manual lagi — dibentuk otomatis dari modul Booked Revenue saat unit di-booking.
            { label: 'Kuitansi Piutang',    icon: Receipt,          href: 'bs.ar.kuitansi.index',    permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Memorial Piutang',    icon: BookText,         href: 'bs.ar.memorial.index',    permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Kartu Piutang',       icon: LayoutList,       href: 'bs.ar.card',              permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Aging Piutang',       icon: FileBarChart,     href: 'bs.ar.aging',             permission: ['businesssupport.view', 'ar.*'] },
            { label: 'Penghapusan Piutang', icon: Eraser,           href: 'bs.ar.penghapusan.index', permission: ['businesssupport.view', 'ar.*'] },
        ],
    },
    {
        title: 'Account Payable',
        icon: Receipt, color: '#f43f5e', route: 'bs.ap.index',
        desc: 'Invoice & pembayaran hutang supplier.',
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
            // Sumber 'po' memuat seluruh dokumen asal FO (penerimaan PO, refund, BLT, komisi, gimmick),
            // karena itu label memakai "dari FO" — bukan lagi khusus Purchase Order.
            { label: 'Posting Invoice dari FO', icon: FileCheck, permission: ['businesssupport.view', 'ap.*'], children: [
                { label: 'Posting Invoice dari FO', href: 'bs.ap.posting.index', params: { source: 'po' } },
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
        icon: Wallet, color: '#6366f1', route: 'bs.cashbook.printhub',
        desc: 'Kas, Bank, Giro, Bon Sementara & approval.',
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
                { label: 'Approval UM Penjualan', href: 'bs.cashbook.approval-um' },
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
        icon: PiggyBank, color: '#d946ef', route: 'bs.budget.index',
        desc: 'Anggaran, alokasi & koreksi budget.',
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
        icon: HardHat, color: '#f97316', route: 'bs.spk.index',
        desc: 'SPK, progress lapangan & retensi.',
        items: [
            { label: 'Setup', icon: FolderGit2, permission: ['businesssupport.view', 'contractor.*'], children: [
                { label: 'Project', href: 'bs.spk.project.index' },
                { label: 'Set Up SPK', href: 'bs.spk.draft.edit' },
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
            { label: 'Generate BAST', icon: FileCheck, href: 'bs.spk.bast.index', permission: ['businesssupport.view', 'contractor.*'] },
        ],
    },
    {
        title: 'COGS',
        icon: Boxes, color: '#84cc16', route: 'bs.cogs.printhub',
        desc: 'HPP RealCost & budget proyek.',
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
    // ── Grup "Approval", "Reports" & "Setup Sales" DIHAPUS dari sidebar (permintaan user).
    //    Route & halamannya sengaja TIDAK dihapus — hanya tak lagi tampil di menu.
    {
        title: 'Security',
        icon: ShieldCheck, color: '#ef4444', route: 'security.index',
        desc: 'User, hak akses, absensi & log.',
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
        icon: Settings, color: '#64748b', route: 'settings.index',
        desc: 'Pengaturan & konfigurasi sistem.',
        items: [
            { label: 'Settings', icon: Settings, href: 'settings.index', permission: 'system.user.view' },
            { label: 'Konfigurasi Sistem', icon: SlidersHorizontal, href: 'settings.config.index', permission: 'system.setup.parameter' },
        ],
    },
    ];
}
