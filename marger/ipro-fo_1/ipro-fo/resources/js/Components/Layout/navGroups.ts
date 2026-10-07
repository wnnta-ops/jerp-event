// Struktur navigasi bersama — dipakai Sidebar (kiri) & kartu modul pada Dashboard Back Office.
// Sumber tunggal daftar modul & sub-modul agar keduanya selalu sinkron.
import {
    LayoutDashboard, Users, Building2, ClipboardList, Wallet, FileText, Receipt, ReceiptText, FileSignature,
    BarChart3, Settings, ChevronLeft, ChevronDown, Phone, AlertTriangle, FileWarning, FileCheck,
    Hand, Star, Banknote, ScrollText, Award, RefreshCcw, CircleDollarSign, Calculator,
    FileBarChart, ShieldCheck, Percent, Landmark, Briefcase, BookOpen, Truck,
    Boxes, ShoppingCart, PackagePlus, PackageMinus, LayoutList, BookText,
    HandCoins, Coins, Building, PieChart, HardHat, Target, PiggyBank,
    FolderGit2, CalendarClock, TriangleAlert, Lock,
    Layers, FileCog, LandPlot,
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
 * kartu modul pada "Dashboard Back Office" — sehingga menambah/menghapus/mengubah
 * modul cukup di file ini saja dan kedua tampilan otomatis ikut berubah.
 */
export interface NavGroup {
    title: string;      // judul grup = nama modul (dipakai mencocokkan kartu ↔ sub-modul)
    items: NavItem[];
    icon?: any;         // ikon kartu Dashboard Back Office
    color?: string;     // warna aksen kartu
    desc?: string;      // deskripsi singkat pada kartu
    route?: string;     // route landing kartu; bila kosong → pakai sub-modul pertama yang dapat diakses
}

/**
 * URUTAN MODUL pada sidebar & Dashboard Back Office — satu-satunya sumber kebenaran urutan.
 *
 * Ditaruh sebagai daftar terpisah, bukan dengan memindah-mindah blok definisinya:
 * blok tiap modul panjangnya puluhan baris, dan memindahkannya berisiko menggeser
 * sub-menu ke modul yang salah. Mengubah urutan cukup menata ulang daftar di bawah.
 * Grup tanpa judul (Dashboard Back Office) selalu paling atas; modul yang belum tercantum di
 * sini ikut di belakang mengikuti urutan definisinya — tidak pernah hilang.
 */
export const MODULE_ORDER: string[] = [
    'Master',
    'Data Center',
    'KPR',
    'Account Payable',
    'Cash Book',
    'General Ledger',
    'Aset Tetap',
    'Budget Control',
    'Contractor Management',
    'Booked Revenue',
    'Piutang Usaha (Booked)',
    // Dua modul HPP berdampingan, COGS Lama lebih dulu supaya urutannya tidak berubah bagi yang
    // sudah terbiasa. Nama di sini HARUS sama persis dengan `title` grupnya; yang tidak tercantum
    // jatuh ke belakang daftar tanpa satu pun galat.
    'COGS Lama',
    'COGS Baru',
    'Purchasing',
    'Inventory',
    'Security',
    'System',
];

/** Urutkan grup mengikuti MODULE_ORDER; yang tak tercantum ditaruh di belakang. */
function sortByModuleOrder(groups: NavGroup[]): NavGroup[] {
    const rank = (g: NavGroup) => {
        if (!g.title) return -1;                      // grup Dashboard Back Office selalu teratas
        const i = MODULE_ORDER.indexOf(g.title);
        return i === -1 ? MODULE_ORDER.length : i;    // modul baru → ekor daftar
    };

    return groups
        .map((g, i) => ({ g, i }))                    // simpan posisi asal…
        .sort((a, b) => rank(a.g) - rank(b.g) || a.i - b.i)  // …agar pengurutan stabil
        .map((x) => x.g);
}

export function navGroups(foUrl: string): NavGroup[] {
    return sortByModuleOrder([
    {
        title: '',
        items: [
            /* EMPAT SUB-DASHBOARD. Delapan belas grafik pada satu halaman membuat yang dicari
               selalu jauh di bawah; tiap peran kini punya pintu masuknya sendiri.
               Keempatnya bersama-sama memuat SELURUH grafik — tidak ada yang menjadi yatim.
               Catatan: item ber-children dirender Sidebar sebagai tombol buka/tutup, bukan
               tautan (lihat Sidebar.vue), jadi induknya sengaja tidak lagi diberi href. */
            { label: 'Dashboard Back Office', icon: LayoutDashboard, children: [
                { label: 'KPR & Collection',        href: 'dashboard', params: { grup: 'kpr' } },
                { label: 'Cash Management',         href: 'dashboard', params: { grup: 'cash' } },
                { label: 'Accounting, Tax & Budget', href: 'dashboard', params: { grup: 'accounting' } },
                { label: 'Project',                 href: 'dashboard', params: { grup: 'project' } },
            ]},
            // Menu silang → kembali ke hub/dashboard utama J-ERP (URL dari env via Inertia config.fo_url).
            { label: 'Kembali ke Dashboard Utama', icon: Building2, external: foUrl + '/dashboard' },
        ],
    },
    {
        title: 'Master',
        icon: Building2, color: '#ff8c00',
        desc: 'Data unit & master properti — dikelola di FO.',
        items: [
            // Master unit HANYA ada di FO (Setup Sales → Unit). Dulu BO punya layar
            // masternya sendiri, dan dua tempat memasukkan unit untuk data yang sama
            // adalah dua tempat yang bisa berbeda isinya. Tabel `units` di BO tetap ada
            // sebagai cermin hasil sinkronisasi, tetapi tidak lagi disunting dari sini.
            { label: 'Unit', icon: Building2, external: foUrl + '/fo/units', permission: 'master.unit.view' },
        ],
    },
    {
        title: 'Data Center',
        icon: Database, color: '#0ea5e9', route: 'bs.dc.supplier.center',
        // Customer Center DIHILANGKAN: data customer dikelola lewat sub-modul Customer di
        // Marketing & Sales (FO/J-ERP), lalu disinkron ke BO — tidak dikelola dua kali di sini.
        desc: 'Pusat data Supplier & Karyawan.',
        items: [
            { label: 'Supplier Center', icon: Truck,  href: 'bs.dc.supplier.center', permission: ['datacenter.hub.*'] },
            { label: 'Employee Center', icon: IdCard, href: 'bs.dc.employee.center', permission: ['datacenter.hub.*'] },
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
            { label: 'Set Up', icon: SlidersHorizontal, permission: ['bankloan.setup.*'], children: [
                { label: 'Kategori Retensi',        href: 'kpr.setup.retensi.index', permission: 'bankloan.setup.view' },
                { label: 'Kategori Bank',           href: 'kpr.setup.bank.index',    permission: 'bankloan.setup.view' },
                { label: 'Kategori Analisa Berkas', href: 'kpr.setup.analisa.index', permission: 'bankloan.setup.view' },
                { label: 'Kategori Berkas Tidak Lengkap', href: 'kpr.setup.berkas-tidak-lengkap.index', permission: 'bankloan.setup.view' },
                { label: 'Kategori Reject Bank',    href: 'kpr.setup.reject-bank.index', permission: 'bankloan.setup.view' },
                // Panel pengaturan Kalkulator KPR: bank, plafon & tenor, skema bunga, promo,
                // foto intro. Yang dihitung dari pengaturan ini ada di Query/Report → Kalkulator KPR.
                { label: 'Kategori Perhitungan KPR', href: 'kpr.setup.perhitungan.index', permission: 'bankloan.setup.view' },
            ]},
            // Transaction — proses transaksi KPR.
            { label: 'Transaction', icon: GitMerge, permission: ['bankloan.application.*'], children: [
                { label: 'Inisiasi KPR',                     href: 'kpr.initiation.index', permission: 'bankloan.application.view' },
                { label: 'Proses KPR',                       href: 'kpr.index',            permission: 'bankloan.application.view' },
                // "Cek Scan KTP & NPWP" DIHILANGKAN dari menu (belum jadi). Route kpr.scan.*,
                // controller, dan halamannya sengaja DIBIARKAN utuh — tinggal munculkan lagi
                // barisnya di sini bila fiturnya sudah siap dipakai.
            ]},
            // Query/Report — master data & arsip Database KPR (Reject BI Checking / Reject Proses KPR).
            { label: 'Query/Report', icon: FileBarChart, permission: ['bankloan.application.*'], children: [
                { label: 'Master Data KPR',    href: 'kpr.master-data.index',    permission: 'bankloan.application.view' },
                { label: 'Reject BI Checking', href: 'kpr.database.reject-bi',  permission: 'bankloan.application.view' },
                { label: 'Reject Proses KPR',  href: 'kpr.database.reject-kpr', permission: 'bankloan.application.view' },
                // Target akad per bulan vs realisasinya. Targetnya diisi lewat import Excel —
                // angka rencana tidak berasal dari transaksi mana pun.
                { label: 'Realisasi vs Budget', href: 'kpr.database.realisasi-budget', permission: 'bankloan.application.view' },
                // Unit akad per cluster per bank — dihitung dari data akad, bukan diunggah.
                { label: 'Realisasi KPR per Bank', href: 'kpr.database.realisasi-bank', permission: 'bankloan.application.view' },
                // Status unit & outstanding per cluster — diisi lewat import Exsum, bukan dihitung.
                { label: 'Realisasi KPR per Cluster', href: 'kpr.database.realisasi-cluster', permission: 'bankloan.application.view' },
                // Simulasi angsuran untuk calon pembeli. Angkanya mengikuti Kategori Perhitungan
                // KPR di Set Up — tidak ada satu pun suku bunga yang ditulis di layar ini.
                { label: 'Kalkulator KPR', href: 'kpr.kalkulator.index', permission: 'bankloan.application.view' },
            ]},
        ],
    },
    // Grup "Business Support" dibubarkan: Purchase Order → Purchasing; Item/FPB/Penerimaan/
    // Pengeluaran/Kartu Stok → Inventory; Piutang Usaha (Booked) → grup mandiri (dekat Account Payable).
    // Overview, Proyeksi Hutang/Piutang & Payroll dihapus dari menu — halamannya tetap ada:
    // Proyeksi duplikat di grup Account Payable, Payroll via Data Center → Employee Center → Karyawan.
    {
        title: 'Booked Revenue',
        icon: CircleDollarSign, color: '#14b8a6', route: 'bs.booked-revenue.index',
        desc: 'Pendapatan diakui, BAST/AJB & Faktur Pajak.',
        items: [
            { label: 'Sudah Booked Revenue', icon: CircleDollarSign, href: 'bs.booked-revenue.index', permission: ['ar.booked-revenue.*'] },
            { label: 'Belum Booked Revenue', icon: CircleDollarSign, href: 'bs.booked-revenue.belum', permission: ['ar.booked-revenue.*'] },
            { label: 'Detail BAST', icon: FileCheck, href: 'bs.booked-revenue.control.bast', permission: ['ar.booked-revenue.*'] },
            { label: 'Detail AJB', icon: FileText, href: 'bs.booked-revenue.control.ajb', permission: ['ar.booked-revenue.*'] },
            { label: 'Verifikasi Faktur Pajak', icon: Receipt, permission: ['tax.ppn-keluaran.*', 'tax.ppn-masukan.*'], children: [
                { label: 'PPN Masukan', href: 'bs.tax.masukan' },
                { label: 'PPN Keluaran', href: 'bs.tax.keluaran' },
            ] },
            { label: 'Rincian Batal Akad', icon: TriangleAlert, href: 'bs.batal-akad.index', permission: ['bankloan.application.*', 'businesssupport.view'] },
            { label: 'Invoice Penjualan', icon: ReceiptText, href: 'bs.sales-invoice.index', permission: ['ar.view'] },
            { label: 'Report / Query', icon: FileBarChart, href: 'bs.booked-revenue.report', permission: ['ar.booked-revenue.*'] },
        ],
    },
    {
        title: 'Aset Tetap',
        icon: Building, color: '#06b6d4', route: 'bs.fa.index',
        desc: 'Registrasi & penyusutan aset tetap.',
        items: [
            // Grup "Master" dihapus: satu-satunya isinya ("Master Aset Tetap") menunjuk
            // route yang SAMA PERSIS dengan Overview di bawah ini (bs.fa.index), jadi hanya
            // menggandakan halaman yang sama di dua tempat.
            { label: 'Overview Aset Tetap', icon: Building, href: 'bs.fa.index', permission: ['bs.asset.*'] },
            { label: 'Report / Query', icon: FileBarChart, permission: ['bs.asset.*'], children: [
                { label: 'Summary Aset', href: 'bs.fa.summary' },
                { label: 'Penjualan Aset', href: 'bs.fa.penjualan' },
            ] },
            { label: 'Registrasi', icon: ClipboardList, permission: ['bs.asset.*', 'businesssupport.transaction.*'], children: [
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
            { label: 'General Function', icon: FileText, permission: ['gl.ledger.*'], children: [
                { label: 'General Function', href: 'bs.gl.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['gl.cf-category.*', 'gl.currency.*', 'gl.kurs.*', 'gl.repair.*'], children: [
                { label: 'Multi Currency', href: 'bs.gl.currency.index' },
                { label: 'Kurs Sistem', href: 'bs.gl.kurs.index' },
                { label: 'Repair Data', href: 'bs.gl.repair.index' },
                { label: 'Kategori Cash Flow Direct', href: 'bs.gl.cf-category.index', params: { kind: 'direct' } },
                { label: 'Kategori Cash Flow Indirect', href: 'bs.gl.cf-category.index', params: { kind: 'indirect' } },
            ]},
            { label: 'Master', icon: BookText, permission: ['businesssupport.approval.*', 'gl.account.*', 'gl.cf-mapping.*', 'gl.cost-center.*', 'gl.journal-dimension.*', 'gl.journal.*'], children: [
                { label: 'Chart of Account', href: 'bs.accounts.index' },
                { label: 'Jurnal', href: 'bs.gl.journal.index' },
                { label: 'Cost Center', href: 'bs.cost-centers.index' },
                { label: 'Mapping Cash Flow Direct', href: 'bs.gl.cf-mapping.index' },
                { label: 'Dimensi Jurnal', href: 'bs.journal-dimensions.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['businesssupport.approval.*', 'gl.journal.*', 'gl.ledger.*'], children: [
                { label: 'Detail Transaksi Jurnal', href: 'bs.gl.journal.detail' },
                { label: 'General Ledger', href: 'bs.gl.ledger' },
            ]},
            { label: 'Neraca', icon: Calculator, permission: ['gl.comparative.*', 'gl.extra-report.*', 'gl.financials.*', 'gl.trialbalance.*', 'gl.worksheet.*'], children: [
                { label: 'Neraca Saldo', href: 'bs.gl.trialbalance' },
                { label: 'Neraca', href: 'bs.gl.balancesheet' },
                { label: 'Neraca Lajur', href: 'bs.gl.worksheet' },
                { label: 'Histori Saldo', href: 'bs.gl.extra', params: { mode: 'histori' } },
                { label: 'Neraca Multi Periode', href: 'bs.gl.extra', params: { mode: 'multi' } },
                { label: 'Neraca Komparasi (Bulanan)', href: 'bs.gl.comparative' },
                { label: 'Neraca Komparasi (Tahunan)', href: 'bs.gl.extra', params: { mode: 'tahunan' } },
            ]},
            { label: 'Laba Rugi', icon: BarChart3, permission: ['gl.comparative.*', 'gl.extra-report.*', 'gl.financials.*'], children: [
                { label: 'Laba Rugi', href: 'bs.gl.incomestatement' },
                { label: 'Laba Rugi vs Budget', href: 'bs.gl.lr-budget' },
                { label: 'Laba Rugi Multi Periode', href: 'bs.gl.extra', params: { mode: 'lr-multi' } },
                { label: 'Laba Rugi Komparasi (Bulanan)', href: 'bs.gl.comparative' },
                { label: 'Laba Rugi Komparasi (Tahunan)', href: 'bs.gl.extra', params: { mode: 'lr-tahunan' } },
                { label: 'Laba Rugi Perbandingan', href: 'bs.gl.extra', params: { mode: 'lr-banding' } },
            ]},
            { label: 'Cash Flow Direct', icon: PieChart, permission: ['gl.cashflow.*'], children: [
                { label: 'Cash Flow Direct Multi Periode', href: 'bs.gl.cashflow', params: { method: 'direct' } },
            ]},
            { label: 'Cash Flow Indirect', icon: PieChart, permission: ['gl.cashflow.*'], children: [
                { label: 'Cash Flow Indirect Multi Periode', href: 'bs.gl.cashflow', params: { method: 'indirect' } },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['gl.saldo-awal.*'], children: [
                { label: 'Input Saldo Awal', href: 'bs.closing.saldoawal' },
                { label: 'Confirm Saldo Awal', href: 'bs.gl.saldo-awal.confirm' },
            ]},
            { label: 'Jurnal Memorial', icon: FileText, permission: ['gl.journal.*'], children: [
                { label: 'Registrasi Jurnal Memorial', href: 'bs.gl.journal.create' },
                { label: 'Import Jurnal', href: 'bs.gl.journal.import' },
            ]},
            { label: 'Generate Lap. Keuangan', icon: FileCheck, permission: ['businesssupport.approval.*', 'gl.journal.*', 'gl.late-closing.*'], children: [
                { label: 'Finalisasi Jurnal', href: 'bs.gl.journal.finalisasi' },
                { label: 'Approval Jurnal', href: 'bs.gl.journal.approval' },
                { label: 'Late Closing', href: 'bs.gl.late-closing' },
            ]},
            { label: 'Generate Lap. Perusahaan', icon: Lock, permission: ['gl.closing.*'], children: [
                { label: 'Closing Fiskal', href: 'bs.closing.index' },
            ]},
        ],
    },
    {
        title: 'Piutang Usaha (Booked)',
        icon: Coins, color: '#3b82f6', route: 'bs.ar.index',
        desc: 'Invoice, kuitansi & kartu piutang customer.',
        items: [
            { label: 'Invoice Piutang',     icon: Coins,            href: 'bs.ar.index',             permission: ['ar.view'] },
            { label: 'UM Penjualan',        icon: Wallet,           href: 'bs.ar.um.index',          permission: ['ar.view'] },
            { label: 'Rekonsiliasi Piutang', icon: Scale,           href: 'bs.ar.rekonsiliasi.index', permission: ['ar.view'] },
            // "Registrasi Piutang" & "Kategori Invoice" DIHILANGKAN: invoice piutang tidak dibuat
            // manual lagi — dibentuk otomatis dari modul Booked Revenue saat unit di-booking.
            // "Kuitansi Piutang" DIHAPUS dari sidebar (permintaan user). Route & halamannya
            // sengaja TIDAK dihapus supaya kuitansi yang sudah pernah dibuat tetap terjangkau.
            { label: 'Memorial Piutang',    icon: BookText,         href: 'bs.ar.memorial.index',    permission: ['ar.memorial.*'] },
            { label: 'Kartu Piutang',       icon: LayoutList,       href: 'bs.ar.card',              permission: ['ar.card.*'] },
            { label: 'Aging Piutang',       icon: FileBarChart,     href: 'bs.ar.aging',             permission: ['ar.aging.*'] },
            { label: 'Penghapusan Piutang', icon: Eraser,           href: 'bs.ar.penghapusan.index', permission: ['ar.penghapusan.*'] },
            // Piutang di luar piutang customer: pemegang saham, karyawan, dan lainnya —
            // seluruh anak akun 101030101. Angkanya dibaca dari buku besar, bukan dari tabel
            // invoice, sebab ketiga akun itu tidak punya tabel pinjaman sendiri.
            { label: 'Piutang Lain',        icon: HandCoins,        href: 'bs.ar.piutang-lain',      permission: ['ar.piutang-lain.*'] },
        ],
    },
    {
        title: 'Account Payable',
        icon: Receipt, color: '#f43f5e', route: 'bs.ap.index',
        desc: 'Invoice & pembayaran hutang supplier.',
        items: [
            { label: 'Setup', icon: SlidersHorizontal, permission: ['ap.category.*', 'ap.repair.*'], children: [
                { label: 'Kategori Invoice', href: 'bs.ap.categories.index' },
                { label: 'Kategori Gimmick', href: 'bs.gimmick.categories.index' },
                { label: 'Repair Data', href: 'bs.ap.repair.index' },
            ]},
            { label: 'Master', icon: BookText, permission: ['ap.invoice.*', 'ap.kuitansi.*'], children: [
                { label: 'Invoice Hutang', href: 'bs.ap.index' },
                { label: 'Kuitansi Hutang', href: 'bs.ap.kuitansi.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['ap.aging.*', 'ap.invoice.*', 'ap.paycard.*', 'ap.penghapusan.*', 'ar.view', 'tax.pph.*'], children: [
                { label: 'Kartu Hutang', href: 'bs.ap.card' },
                { label: 'Aging Hutang', href: 'bs.ap.aging', params: { view: 'detail' } },
                // Outstanding per supplier: 20 terbesar + rangkuman sisanya. Angkanya dari
                // computeAging() yang sama dengan Aging Hutang, jadi keduanya tidak bisa berbeda.
                { label: 'Aging Hutang Supplier', href: 'bs.ap.aging-supplier' },
                { label: 'Proyeksi Hutang', href: 'bs.projection', params: { mode: 'hutang' } },
                { label: 'Kartu Pembayaran Hutang', href: 'bs.ap.payment-card' },
                { label: 'Laporan Invoice Hutang', href: 'bs.ap.report' },
                { label: 'Laporan Penghapusan Hutang', href: 'bs.ap.penghapusan.report' },
                // Dipindah ke sini dari grup "General Function" yang dihapus: isinya laporan,
                // dan tempatnya laporan di modul ini adalah Query.
                { label: 'Rekap PPh Dipotong', href: 'bs.tax.pph' },
            ]},
            { label: 'Generate Invoice', icon: ClipboardList, permission: ['ap.invoice.*'], children: [
                { label: 'Registrasi Invoice Hutang', href: 'bs.ap.create' },
            ]},
            { label: 'Penyesuaian Invoice', icon: Eraser, permission: ['ap.penghapusan.*'], children: [
                { label: 'Penghapusan Nilai Hutang', href: 'bs.ap.penghapusan.index' },
            ]},
            // Sumber 'po' memuat seluruh dokumen asal FO (penerimaan PO, refund, BLT, komisi, gimmick),
            // karena itu label memakai "dari FO" — bukan lagi khusus Purchase Order.
            // Tiga sub modul terpisah, masing-masing satu sumber dokumen. Dulu ketiganya
            // menunjuk rute yang sama (bs.ap.posting.index) dengan params `source` yang
            // tidak pernah dibaca controller, jadi "Posting Invoice SPK" sebenarnya membuka
            // layar yang persis sama dengan "Posting Invoice dari FO".
            { label: 'Posting Invoice dari FO', icon: FileCheck, permission: ['ap.posting.*'], children: [
                { label: 'Posting Invoice dari FO', href: 'bs.ap.posting.index' },
            ]},
            { label: 'Posting Invoice dari Purchase Order', icon: ShoppingCart, permission: ['ap.posting.*'], children: [
                { label: 'Posting Invoice dari Purchase Order', href: 'bs.ap.posting.po.index' },
            ]},
            { label: 'Posting Invoice SPK', icon: HardHat, permission: ['ap.posting.*'], children: [
                { label: 'Posting Invoice SPK', href: 'bs.ap.posting.spk.index' },
                // "Kontrak SPK (MUK)" DIPINDAH ke Contractor Management → Master, tepat di atas
                // Surat Perintah Kerja (permintaan user). Rute & layarnya tidak ikut pindah —
                // yang berpindah hanya tempatnya di menu.
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['ap.opening.*'], children: [
                { label: 'Saldo Awal', href: 'bs.ap.opening.index' },
            ]},
        ],
    },
    {
        title: 'Cash Book',
        icon: Wallet, color: '#6366f1', route: 'bs.cashbook.printhub',
        desc: 'Kas, Bank, Giro, Bon Sementara & approval.',
        items: [
            { label: 'General Function', icon: FileText, permission: ['cashbook.print.*'], children: [
                // Satu-satunya anak grup ini; namanya disamakan dengan grupnya agar tidak
                // terbaca sebagai dua tingkat berbeda ("General Function → Print-Out").
                { label: 'General Function', href: 'bs.cashbook.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['businesssupport.approval.*', 'cashbook.print.*', 'cashbook.repair.*', 'cashbook.saldo.*'], children: [
                { label: 'Rekening', href: 'rekening.index' },
                // Barcode tanda tangan yang dipakai pada Persetujuan (TTD) SIP.
                { label: 'Set Up Barcode', href: 'bs.cashbook.signature-barcode.index' },
                // Kartu debit/VA: saldo & masa berlakunya. Bon sementara berpembayaran
                // Debit VA melahirkan permintaan saldo & expired di sini.
                { label: 'Set Up Debit Card', href: 'bs.cashbook.credit-card.index' },
                { label: 'Repair Data', href: 'bs.cashbook.repair.index' },
            ]},
            { label: 'Master', icon: Wallet, permission: ['cashbook.advance.*', 'cashbook.cheque-out.*', 'cashbook.giro.*', 'cashbook.pettycash.*', 'cashbook.transaction.*'], children: [
                { label: 'Transfer Rekening', href: 'bs.cashbook.index', params: { type: 'transfer' } },
                { label: 'Kas Masuk', href: 'bs.cashbook.index', params: { type: 'in' } },
                { label: 'Kas Keluar', href: 'bs.cashbook.index', params: { type: 'out' } },
                { label: 'Kas Kecil Masuk', href: 'bs.pettycash.index', params: { type: 'in' } },
                { label: 'Kas Kecil Keluar', href: 'bs.pettycash.index', params: { type: 'out' } },
                { label: 'Cek Giro Masuk', href: 'bs.giro.index' },
                { label: 'Cek Giro Keluar', href: 'bs.cheque-out.index' },
                { label: 'Realisasi Bon Sementara', href: 'bs.advance.index' },
            ]},
            { label: 'Report/Query', icon: FileBarChart, permission: ['cashbook.advance.*', 'cashbook.giro-calendar.*', 'cashbook.ledger.*', 'cashbook.saldo.*', 'cashbook.transaction.*'], children: [
                { label: 'Rekening Ledger', href: 'bs.cashbook.ledger' },
                { label: 'Rekening Saldo', href: 'bs.cashbook.balances' },
                { label: 'Kalender Cek Giro', href: 'bs.cashbook.giro-calendar' },
                { label: 'Pengeluaran Kas & Bank', href: 'bs.cashbook.index', params: { type: 'out' } },
                { label: 'Kartu Bon Sementara', href: 'bs.advance.card' },
                // Saldo kartu debit/VA yang dipecah per nomor bon — angkanya sama dengan
                // Set Up Debit Card, hanya sudut pandangnya yang per bon, bukan per kartu.
                { label: 'Saldo Debit Card', href: 'bs.advance.card-balance' },
                { label: 'Rekap Bon Sementara', href: 'bs.advance.report' },
                // Outstanding Kasbon adalah laporan (saldo awal · penambahan · realisasi ·
                // outstanding per karyawan), bukan tempat menginput — tempatnya di Report/Query,
                // tepat di bawah Rekap Bon Sementara.
                { label: 'Outstanding Kasbon', href: 'bs.cashbook.outstanding-kasbon' },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['cashbook.opening.*'], children: [
                { label: 'Saldo Awal Rekening', href: 'bs.cashbook.opening.index' },
            ]},
            { label: 'Transaksi Pengeluaran Bank', icon: Banknote, permission: ['ap.kuitansi.*', 'bs.sip.*', 'businesssupport.approval.*', 'cashbook.transaction.*'], children: [
                { label: 'Registrasi Pengeluaran Kas & Bank', href: 'bs.cashbook.create', params: { type: 'out' } },
                { label: 'Registrasi Pengeluaran Kas & Bank (AP)', href: 'bs.ap.kuitansi.create' },
                { label: 'Approval Pengeluaran Kas & Bank', href: 'bs.cashbook.approvals' },
                { label: 'SIP (Instruksi Bayar)', href: 'bs.sip.index', permission: ['bs.sip.*'] },
            ]},
            { label: 'Transaksi Penerimaan Bank', icon: Landmark, permission: ['cashbook.approval-retensi.*', 'cashbook.approval-utjdp.*', 'cashbook.transaction.*'], children: [
                { label: 'Transfer Antar Rekening', href: 'bs.cashbook.create', params: { type: 'transfer' } },
                { label: 'Penerimaan Bank', href: 'bs.cashbook.create', params: { type: 'in' } },
                { label: 'Approval Retensi', href: 'bs.cashbook.approval-retensi' },
                { label: 'Approval UM Penjualan', href: 'bs.cashbook.approval-um' },
                { label: 'Approval UTJ', href: 'bs.cashbook.approval-utjdp' },
                // Penerimaan dari Pelunasan DP, Angsuran & Tambah DP (FO) — masuk otomatis.
                { label: 'Approval DP', href: 'bs.cashbook.approval-dp' },
            ]},
            { label: 'Transaksi Bon Sementara', icon: HandCoins, permission: ['cashbook.advance.*'], children: [
                { label: 'Pengeluaran Bon Sementara', href: 'bs.advance.payments' },
                // Alokasi Kasbon berdiri sendiri di antara pengeluaran dan realisasi: bon baru
                // sampai ke Realisasi setelah tombol "Lanjut Realisasikan Bon" ditekan di sini.
                { label: 'Alokasi Bon Sementara', href: 'bs.advance.allocation.index' },
                { label: 'Realisasi Bon Sementara', href: 'bs.advance.index' },
            ]},
            { label: 'Transaksi Kas Kecil', icon: Wallet, permission: ['cashbook.pettycash.*'], children: [
                { label: 'Penerimaan Kas Kecil', href: 'bs.pettycash.create', params: { type: 'in' } },
                { label: 'Pengeluaran Kas Kecil', href: 'bs.pettycash.create', params: { type: 'out' } },
            ]},
            { label: 'Transaksi Cek Giro Masuk', icon: FileText, permission: ['cashbook.giro.*'], children: [
                { label: 'Penerimaan Cek Giro', href: 'bs.giro.create' },
                { label: 'Kliring Cek Giro Masuk', href: 'bs.giro.index' },
                { label: 'Tolakan Cek Giro Masuk', href: 'bs.giro.index' },
            ]},
            { label: 'Transaksi Cek Giro Keluar', icon: FileText, permission: ['cashbook.cheque-out.*'], children: [
                { label: 'Data BG/Cek Keluar', href: 'bs.cheque-out.index' },
            ]},
            // Modul Cash Flow: kategorinya menjadi pilihan "Pembagian Biaya per Cash Flow" di
            // Rekening Ledger, dan Report Cashflow merekap pembagian tersebut.
            { label: 'Cash Flow', icon: BarChart3, permission: ['cashbook.ledger.*'], children: [
                { label: 'Set Up Kategori', href: 'bs.cashflow.categories' },
                { label: 'Set Up Rumus', href: 'bs.cashflow.formulas' },
                { label: 'Report Cashflow', href: 'bs.cashflow.report' },
            ]},
        ],
    },
    {
        title: 'Budget Control',
        icon: PiggyBank, color: '#d946ef', route: 'bs.budget.index',
        desc: 'Anggaran, alokasi & koreksi budget.',
        items: [
            // Grup "General Function → Print-Out" DIHAPUS: isinya hanya daftar tautan ke
            // keempat laporan yang SUDAH ada satu per satu di grup "Query" di bawah ini,
            // jadi hanya menambah satu lompatan tanpa menambah apa pun.
            { label: 'Master', icon: BookText, permission: ['budget.allocation.*', 'budget.correction.*', 'budget.master.*'], children: [
                { label: 'Budget Control', href: 'bs.budget.index' },
                { label: 'Alokasi Budget Control', href: 'bs.budget.allocation.index' },
                { label: 'Koreksi Budget Control', href: 'bs.budget.correction.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['budget.query.*'], children: [
                { label: 'Master Budget', href: 'bs.budget.query.master' },
                { label: 'Koreksi Budget', href: 'bs.budget.query.correction' },
                { label: 'Realisasi Budget', href: 'bs.budget.query.realization' },
                { label: 'Alokasi Budget', href: 'bs.budget.query.allocation' },
                { label: 'Report Budget', href: 'bs.budget.query.report' },
            ]},
            { label: 'Upload Budget', icon: FileSpreadsheet, permission: ['budget.master.*', 'budget.upload.*', 'businesssupport.approval.*'], children: [
                { label: 'Upload Budget', href: 'bs.budget.upload.index' },
                { label: 'Approval Budget', href: 'bs.budget.approvals' },
            ]},
            { label: 'Alokasi Budget', icon: Layers, permission: ['budget.allocation.*', 'businesssupport.approval.*'], children: [
                { label: 'Alokasi Budget', href: 'bs.budget.allocation.create' },
                { label: 'Approval Alokasi Budget', href: 'bs.budget.allocation.approvals' },
            ]},
            { label: 'Koreksi Budget', icon: ShieldCheck, permission: ['budget.correction.*', 'businesssupport.approval.*'], children: [
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
            { label: 'Setup', icon: FolderGit2, permission: ['contractor.project.*', 'contractor.spk.*'], children: [
                { label: 'Project', href: 'bs.spk.project.index' },
                // Menyandingkan nama rumah versi MUK dengan kode unit kita. Di Setup, bukan
                // Master: ia menyetel dasar perhitungan, bukan mencatat transaksi.
                { label: 'Pasangan Unit MUK', href: 'bs.muk.pasangan.index' },
                { label: 'Set Up SPK', href: 'bs.spk.draft.edit' },
            ]},
            // 'ap.posting.*' IKUT di daftar izin grup ini semata-mata karena "Kontrak SPK (MUK)"
            // pindah ke sini: layarnya dijaga izin ap.posting.view (MukKontrakController:33,77),
            // bukan izin contractor. Tanpa baris itu, pengguna yang selama ini bisa membukanya
            // dari Account Payable akan kehilangan menunya sama sekali — grup Master-nya tidak
            // pernah dirender untuk mereka.
            { label: 'Master', icon: FileSignature, permission: ['contractor.kpl.*', 'contractor.retention.*', 'contractor.spk.*', 'ap.posting.*'], children: [
                // Baca-saja dari MUK ERP — sumber angka kontrak & penagihan SPK. Izinnya
                // DISEBUT di baris ini sendiri (bukan diwarisi grup): layar ini menuntut
                // ap.posting.view, jadi menampilkannya kepada pemegang izin contractor saja
                // berarti menyodorkan menu yang berakhir 403 saat diklik.
                { label: 'Kontrak SPK (MUK)', href: 'bs.muk.kontrak.index', permission: ['ap.posting.*'] },
                // Lampiran 2 — nilai SPK + sudah/belum tertagih. Izinnya DISEBUT sendiri
                // dengan alasan yang sama seperti baris di atas: layarnya menuntut
                // ap.posting.view, bukan izin contractor yang memayungi grup ini.
                { label: 'Informasi CTC', href: 'bs.muk.ctc.index', permission: ['ap.posting.*'] },
                // COP Owner dari MUK beserta tombol Posting ke AP. Izinnya disebut sendiri,
                // alasan yang sama dengan dua baris di atasnya.
                { label: 'Tagihan Progress Project', href: 'bs.muk.tagihan.index', permission: ['ap.posting.*'] },
                { label: 'Surat Perintah Kerja', href: 'bs.spk.index' },
                { label: 'Kontrol Progress Lapangan', href: 'bs.spk.kpl.index' },
                { label: 'Progress Retensi', href: 'bs.spk.retention.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['contractor.query.*'], children: [
                { label: 'Rekap SPK', href: 'bs.spk.query.rekap' },
                { label: 'Kartu Hutang Supplier - SPK', href: 'bs.spk.query.kartu-hutang' },
                { label: 'Pemakaian Barang', href: 'bs.spk.query.pemakaian' },
                { label: 'Summary SPK', href: 'bs.spk.query.summary' },
            ]},
            { label: 'Surat Perintah Kerja', icon: ClipboardList, permission: ['contractor.kpl.*', 'contractor.retention.*', 'contractor.spk.*'], children: [
                { label: 'Registrasi Surat Perintah Kerja', href: 'bs.spk.create' },
                { label: 'Registrasi Kontrol Progress Lapangan', href: 'bs.spk.kpl.create' },
                { label: 'Registrasi Retensi', href: 'bs.spk.retention.create' },
                { label: 'Addendum SPK (Tambah / Kurang)', href: 'bs.spk.addendum.form' },
                { label: 'Adjustment PPN', href: 'bs.spk.adjust-ppn.form' },
                { label: 'Pembatalan Surat Perintah Kerja', href: 'bs.spk.cancel.form' },
            ]},
        ],
    },
    {
        // Seluruh COGS yang lama — Setup sampai Saldo Awal Realisasi — berdiri di bawah nama
        // "COGS Lama" sejak modul COGS Baru lahir. Isinya, rutenya, dan izinnya TIDAK berubah
        // sedikit pun; hanya namanya. Dua model perhitungan HPP yang hidup berdampingan tanpa
        // nama yang membedakannya adalah cara tercepat membuat orang membuka yang salah.
        title: 'COGS Lama',
        // Kartu modul mendarat di halaman hub-nya, BUKAN lagi di Print-Out yang sudah dihapus
        // dari menu — kalau tidak, pintu depan modul justru menuju halaman yang ditiadakan.
        icon: Boxes, color: '#84cc16', route: 'bs.cogs.home',
        desc: 'HPP RealCost & budget proyek (model lama).',
        items: [
            // Grup "General Function → Print-Out" DIHAPUS: isinya hanya daftar tautan ke
            // keenam laporan yang SUDAH ada satu per satu di grup "Query" di bawah ini.
            { label: 'Setup', icon: SlidersHorizontal, permission: ['cogs.budget.*', 'cogs.category.*', 'cogs.location.*', 'cogs.provision.*', 'cogs.unitcoa.*', 'cogs.upload.*'], children: [
                { label: 'Lokasi', href: 'bs.cogs.location.index' },
                { label: 'Ketentuan', href: 'bs.cogs.provision.index' },
                { label: 'Budget', href: 'bs.cogs.budget.index' },
                { label: 'Set Akun Budget', href: 'bs.cogs.category.index' },
                { label: 'Set COA Jenis Unit', href: 'bs.cogs.unitcoa.index' },
                { label: 'Upload Kategori Budget', href: 'bs.cogs.upload.form', params: { kind: 'kategori' } },
                { label: 'Upload Akun Budget', href: 'bs.cogs.upload.form', params: { kind: 'akun' } },
                { label: 'Upload Nilai Budget', href: 'bs.cogs.upload.form', params: { kind: 'nilai' } },
            ]},
            { label: 'Master', icon: BookText, permission: ['cogs.hpp.*', 'cogs.mutation.*', 'cogs.realization.*'], children: [
                { label: 'Mutasi Budget', href: 'bs.cogs.mutation.index' },
                { label: 'HPP RealCost', href: 'bs.cogs.hpp.index' },
                { label: 'Saldo Awal Realisasi', href: 'bs.cogs.realization.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['cogs.query.*'], children: [
                { label: 'Report Budget', href: 'bs.cogs.query.budget' },
                { label: 'Report Realisasi Budget', href: 'bs.cogs.query.realisasi' },
                { label: 'Report Realisasi Budget Detil', href: 'bs.cogs.query.realisasi-detil' },
                { label: 'Report Pengakuan HPP RealCost', href: 'bs.cogs.query.pengakuan' },
                { label: 'Report Persediaan Unit RealCost', href: 'bs.cogs.query.persediaan-unit' },
                { label: 'Report HPP Unit RealCost', href: 'bs.cogs.query.hpp-unit' },
            ]},
            { label: 'Mutasi Budget', icon: GitMerge, permission: ['businesssupport.approval.*', 'cogs.mutation.*'], children: [
                { label: 'Registrasi Mutasi Budget', href: 'bs.cogs.mutation.create' },
                { label: 'Approval Mutasi Budget', href: 'bs.cogs.mutation.approvals' },
            ]},
            { label: 'Transaksi HPP RealCost', icon: Calculator, permission: ['cogs.hpp.*'], children: [
                { label: 'Generate HPP', href: 'bs.cogs.hpp.generate' },
                { label: 'Posting Jurnal HPP Persediaan', href: 'bs.cogs.hpp.post-persediaan' },
                { label: 'Posting Jurnal HPP', href: 'bs.cogs.hpp.post-hpp' },
            ]},
            // Biaya pembentuk tiap komponen HPP, diambil langsung dari modul sumbernya:
            // Tanah & License Permit dari Kategori Invoice (Account Payable), Housing &
            // Infrastruktur dari Surat Perintah Kerja (Contractor Management).
            { label: 'Komponen COGS', icon: Layers, permission: ['cogs.component.*'], children: [
                { label: 'Tanah', href: 'bs.cogs.komponen.show', params: { komponen: 'tanah' } },
                { label: 'Housing', href: 'bs.cogs.komponen.show', params: { komponen: 'housing' } },
                { label: 'Infrastruktur', href: 'bs.cogs.komponen.show', params: { komponen: 'infrastruktur' } },
                { label: 'License Permit', href: 'bs.cogs.komponen.show', params: { komponen: 'license-permit' } },
            ]},
            // Perhitungan Lahan per lokasi/tahap → budget per unit menurut luas tanah & luas
            // bangunan unit itu sendiri. Izinnya cogs.budget.* (baca) — SENGAJA bukan
            // 'cogs.setup.*', yang akan ikut mencocoki izin mati cogs.setup.provision.manage.
            { label: 'Set Up COGS', icon: LandPlot, permission: ['cogs.budget.*'], children: [
                { label: 'Perhitungan Lahan & Budget per Unit', href: 'bs.cogs.setupcogs.index' },
            ]},
            { label: 'Saldo Awal Realisasi', icon: Coins, permission: ['cogs.realization.*'], children: [
                { label: 'Input Saldo Awal Realisasi', href: 'bs.cogs.realization.create' },
            ]},
        ],
    },
    {
        // ── COGS BARU ────────────────────────────────────────────────────────────────
        // Model HPP yang kedua, mengikuti lampiran "MODUL HPP.xlsx": budget per komponen
        // diturunkan ke tiap unit menurut LUAS, lalu biaya aktual dibebankan ulang ke unit yang
        // belum terkunci HPP-nya. Berdiri sebagai modul sendiri, bukan grup di dalam COGS Lama:
        // angkanya tidak pernah bertemu dengan angka model lama, dan mencampurnya di satu modul
        // membuat orang membandingkan dua angka yang memang tidak sebanding.
        title: 'COGS Baru',
        icon: Calculator, color: '#0ea5e9', route: 'bs.cogs2.index',
        desc: 'Perhitungan COGS, kategori, actual costing & rincian per unit.',
        items: [
            // Seluruh layar COGS Baru berbutir PERIODE (lokasi + tahap), sedangkan butir menu
            // tidak bisa tahu periode mana yang sedang dikerjakan. Rute 'bs.cogs2.buka' membuka
            // periode yang terakhir disunting; kalau belum ada, ia mendarat di daftar periode.
            { label: 'Perhitungan COGS', icon: Calculator, permission: ['cogs.budget.*'], children: [
                { label: 'Perhitungan COGS', href: 'bs.cogs2.buka', params: { layar: 'perhitungan' } },
                { label: 'Daftar Periode', href: 'bs.cogs2.index' },
            ]},
            { label: 'Set Up Kategori COGS', icon: SlidersHorizontal, permission: ['cogs.budget.*'], children: [
                { label: 'Set Up Kategori COGS-Tanah', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'tanah' } },
                { label: 'Set Up Kategori COGS-Housing', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'housing' } },
                { label: 'Set Up Kategori COGS-Infra', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'infra' } },
                { label: 'Set Up Kategori COGS-License', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'license' } },
            ]},
            { label: 'Actual Costing COGS', icon: ReceiptText, permission: ['cogs.budget.*'], children: [
                { label: 'Actual Costing COGS-Tanah', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'tanah' } },
                { label: 'Actual Costing COGS-Housing', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'housing' } },
                { label: 'Actual Costing COGS-Infra', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'infra' } },
                { label: 'Actual Costing COGS-License', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'license' } },
            ]},
            { label: 'Rincian COGS', icon: FileBarChart, permission: ['cogs.budget.*'], children: [
                { label: 'Rincian COGS', href: 'bs.cogs2.buka', params: { layar: 'rincian' } },
            ]},
        ],
    },
    // ── FO (Legal / Komisi) DINONAKTIFKAN — dikelola di J-ERP. ───────────────────
    // ── Grup "Approval", "Reports" & "Setup Sales" DIHAPUS dari sidebar (permintaan user).
    //    Route & halamannya sengaja TIDAK dihapus — hanya tak lagi tampil di menu.
    // Purchasing & Inventory diletakkan setelah COGS dan sebelum Security (permintaan user):
    // modul akuntansi berurutan lebih dulu, baru rantai pembelian–persediaan, lalu sistem.
    {
        title: 'Purchasing',
        icon: ShoppingCart, color: '#f59e0b', route: 'bs.po.index',
        desc: 'Permintaan pembelian (FPB), Purchase Order ke supplier & rekapnya.',
        items: [
            // FPB ada di ATAS Purchase Order: permintaan pembelian adalah langkah SEBELUM PO,
            // jadi urutan sidebar mengikuti urutan prosesnya (FPB disetujui → jadi PO).
            { label: 'FPB (Permintaan)',   icon: ClipboardList, href: 'bs.fpb.index',          permission: ['bs.fpb.*'] },
            { label: 'Purchase Order',     icon: ShoppingCart,  href: 'bs.po.index',           permission: ['bs.po.*'] },
            { label: 'Report PO',          icon: ClipboardList, href: 'bs.po.report',          permission: ['bs.po.*'] },
        ],
    },
    {
        title: 'Inventory',
        icon: Boxes, color: '#eab308', route: 'bs.items.index',
        desc: 'Item/stok, penerimaan & pengeluaran barang.',
        items: [
            { label: 'Item / Stok',        icon: Boxes,         href: 'bs.items.index',        permission: ['bs.item.*'] },
            // FPB (Permintaan) dipindah ke modul Purchasing — permintaan pembelian adalah
            // langkah awal proses pembelian, bukan pergerakan stok.
            { label: 'Penerimaan Barang',  icon: PackagePlus,   href: 'bs.receipt.index',      permission: ['bs.receipt.*'] },
            { label: 'Pengeluaran Barang', icon: PackageMinus,  href: 'bs.issue.index',        permission: ['bs.issue.*'] },
            { label: 'Kartu Stok',         icon: LayoutList,    href: 'bs.stockcard.index',    permission: ['bs.stockcard.*'] },
        ],
    },
    {
        title: 'Security',
        icon: ShieldCheck, color: '#ef4444', route: 'security.index',
        desc: 'User, hak akses, absensi & log.',
        items: [
            { label: 'Security',        icon: ShieldCheck,   href: 'security.index',                permission: ['security.view'] },
            { label: 'Matrikulasi Perusahaan', icon: Building2, href: 'security.matrikulasi.company', permission: ['security.view'] },
            { label: 'Matrikulasi Project',    icon: FolderGit2, href: 'security.matrikulasi.project', permission: ['security.view'] },
            { label: 'Absensi',         icon: CalendarClock, href: 'security.absensi',              permission: ['security.view'] },
            { label: 'Job History',     icon: Briefcase,     href: 'security.jobhistory',           permission: ['security.view'] },
            { label: 'Technical Problem',icon: TriangleAlert,href: 'security.technical',            permission: ['security.view'] },
            { label: 'Security Log',    icon: Lock,          href: 'security.log',                  permission: ['security.view'] },
        ],
    },
    {
        title: 'System',
        icon: Settings, color: '#64748b', route: 'settings.index',
        desc: 'Pengaturan & konfigurasi sistem.',
        items: [
            { label: 'Settings', icon: Settings, href: 'settings.index', permission: 'system.user.view' },
            { label: 'Konfigurasi Sistem', icon: SlidersHorizontal, href: 'settings.config.index', permission: ['system.setup.*'] },
        ],
    },
    ]);
}
