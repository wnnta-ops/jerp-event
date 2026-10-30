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
    /**
     * Izin TAMPIL butir menu ini — satu izin `menu.*` per butir, terpisah dari izin aksinya.
     *
     * Dipakai BERSAMA `permission`, dan keduanya harus terpenuhi (DAN, bukan ATAU):
     *
     *   permission  menentukan BOLEH TIDAKNYA layarnya dibuka — dijaga juga di controller.
     *   tampil      menentukan KELIHATAN TIDAKNYA butirnya di sidebar.
     *
     * Gunanya supaya admin IT bisa merapikan sidebar seseorang tanpa menyentuh wewenangnya:
     * satu izin aksi kerap membuka beberapa menu sekaligus (mis. ap.invoice.view membuka
     * Invoice Hutang, Laporan Invoice Hutang, dan Registrasi Invoice Hutang), dan sebelum ini
     * tidak ada cara memunculkan sebagian saja.
     *
     * YANG PERLU DISADARI: mematikan `tampil` MENYEMBUNYIKAN, bukan mengamankan. Alamatnya
     * tetap bisa diketik langsung selama izin aksinya masih dipegang. Untuk benar-benar
     * menutup, yang dicabut izin aksinya.
     */
    tampil?: string;
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
    'Approval Inbox',
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
    // Nama di sini HARUS sama persis dengan `title` grupnya; yang tidak tercantum jatuh ke
    // belakang daftar tanpa satu pun galat.
    'COGS',
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
                // Satu izin per sub-dashboard (Grup Akses BO). Tanpa satu pun, grup ini lenyap
                // dari sidebar dan /dashboard menampilkan "Selamat Datang".
                { label: 'KPR & Collection',        href: 'dashboard', params: { grup: 'kpr' }, permission: ['dashboard.kpr.view'], tampil: 'menu.dashboard.kpr' },
                { label: 'Cash Management',         href: 'dashboard', params: { grup: 'cash' }, permission: ['dashboard.cash.view'], tampil: 'menu.dashboard.cash' },
                { label: 'Accounting, Tax & Budget', href: 'dashboard', params: { grup: 'accounting' }, permission: ['dashboard.accounting.view'], tampil: 'menu.dashboard.accounting' },
                { label: 'Project',                 href: 'dashboard', params: { grup: 'project' }, permission: ['dashboard.project.view'], tampil: 'menu.dashboard.project' },
            ]},
            // Menu silang → kembali ke hub/dashboard utama J-ERP (URL dari env via Inertia config.fo_url).
            { label: 'Kembali ke Dashboard Utama', icon: Building2, external: foUrl + '/dashboard' },
        ],
    },
    {
        title: 'Approval Inbox',
        icon: ShieldCheck, color: '#f97316', route: 'approval-inbox.index',
        desc: 'Ajukan Memo Internal & lacak approval bertingkat.',
        items: [
            // Satu layar, tiga tab (perlu persetujuan / pengajuan saya / riwayat); isi tiap tab
            // dibatasi per baris oleh server, jadi semua peran internal boleh membukanya.
            { label: 'Inbox Approval', icon: ShieldCheck, href: 'approval-inbox.index', permission: ['approvalinbox.view'] },
            { label: 'Ajukan Memo', icon: FileText, href: 'approval-inbox.create', permission: ['approvalinbox.submit'] },
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
            // Dipisah per center (21 Okt 2026): masing-masing mengikuti izin Lihat-nya sendiri.
            // Izin lama "Supplier & Employee Center — Lihat" (datacenter.hub.*) tetap membuka keduanya.
            { label: 'Supplier Center', icon: Truck,  href: 'bs.dc.supplier.center', permission: ['datacenter.supplier.view', 'datacenter.hub.*'] },
            { label: 'Employee Center', icon: IdCard, href: 'bs.dc.employee.center', permission: ['datacenter.employee.view', 'datacenter.hub.*'] },
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
                // Per tahap Proses KPR: wajib approval?, approver, boleh ubah langsung.
                { label: 'Setup Approval KPR', href: 'kpr.setup.approval.index', permission: 'bankloan.setup.view' },
            ]},
            // Approval perubahan data Proses KPR — tiap sub-menu hanya tampil bagi approver tahap itu
            // (penanda 'kpr.approval.<tahap>' dikirim server, lihat HandleInertiaRequests).
            { label: 'Approval', icon: ShieldCheck, permission: ['kpr.approval.*'], children: [
                { label: 'Approval Proses Bank',   href: 'kpr.approval.index', params: { stage: 'prosesbank' }, permission: 'kpr.approval.prosesbank' },
                { label: 'Approval SP3K',          href: 'kpr.approval.index', params: { stage: 'sp3k' },       permission: 'kpr.approval.sp3k' },
                { label: 'Approval Akad',          href: 'kpr.approval.index', params: { stage: 'akad' },       permission: 'kpr.approval.akad' },
                { label: 'Approval Pencairan',     href: 'kpr.approval.index', params: { stage: 'pencairan' },  permission: 'kpr.approval.pencairan' },
                { label: 'Approval Batal / Tolak', href: 'kpr.approval.index', params: { stage: 'batal' },      permission: 'kpr.approval.batal' },
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
            { label: 'Verifikasi Faktur Pajak', icon: Receipt, permission: ['tax.ppn-keluaran.view', 'tax.ppn-masukan.view'], children: [
                { label: 'PPN Masukan', href: 'bs.tax.masukan' , permission: ['tax.ppn-masukan.view'] , tampil: 'menu.bs.tax.masukan' },
                { label: 'PPN Keluaran', href: 'bs.tax.keluaran' , permission: ['tax.ppn-keluaran.view'] , tampil: 'menu.bs.tax.keluaran' },
            ] },
            // 'businesssupport.view' DICABUT dari sini: itu izin "boleh masuk Back Office",
            // dipegang hampir semua orang, sehingga layar berisi nama customer, nomor KPR,
            // bank, dan plafon terbuka bagi siapa saja yang bisa membuka BO. Isinya data
            // KPR, jadi izinnya izin KPR.
            { label: 'Rincian Batal Akad', icon: TriangleAlert, href: 'bs.batal-akad.index', permission: ['bankloan.application.*'] },
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
            { label: 'Report / Query', icon: FileBarChart, permission: ['bs.asset.view'], children: [
                { label: 'Summary Aset', href: 'bs.fa.summary' , permission: ['bs.asset.view'] , tampil: 'menu.bs.fa.summary' },
                { label: 'Penjualan Aset', href: 'bs.fa.penjualan' , permission: ['bs.asset.view'] , tampil: 'menu.bs.fa.penjualan' },
            ] },
            // IZIN TIAP BUTIR DISAMAKAN DENGAN AKSINYA.
            //
            // Grup ini dulu juga menerima 'businesssupport.transaction.*' — izin umum "boleh
            // membuat transaksi Back Office" yang dipegang hampir semua peran operasional.
            // Akibatnya staf Finance melihat sub-menu Registrasi Aset Tetap, padahal ketiga
            // layarnya menuntut izin yang sama sekali lain (bs.asset.create / bs.asset.run /
            // businesssupport.setup.manage) dan memulangkan 403 begitu diklik.
            //
            // Sidebar menyaring anak menu memakai izinnya masing-masing, jadi tiap butir kini
            // menyebut izin aksinya sendiri dan grupnya hanya gabungan ketiganya.
            { label: 'Registrasi', icon: ClipboardList, permission: ['bs.asset.create', 'bs.asset.run', 'businesssupport.setup.manage'], children: [
                { label: 'Registrasi Aset', href: 'bs.fa.create', permission: ['bs.asset.create'] , tampil: 'menu.bs.fa.create' },
                { label: 'Registrasi Penyusutan', href: 'bs.fa.run', permission: ['bs.asset.run'] , tampil: 'menu.bs.fa.run' },
                { label: 'Upload Aset Massal', href: 'bs.bulk.form', params: { mode: 'asset' }, permission: ['businesssupport.setup.manage'] , tampil: 'menu.bs.bulk.form.asset' },
            ] },
        ],
    },
    {
        title: 'General Ledger',
        icon: BookOpen, color: '#7c3aed', route: 'bs.gl.printhub',
        desc: 'Jurnal, Neraca, Laba Rugi & Cash Flow.',
        items: [
            { label: 'General Function', icon: FileText, permission: ['gl.ledger.view'], children: [
                { label: 'General Function', href: 'bs.gl.printhub' , permission: ['gl.ledger.view'] , tampil: 'menu.bs.gl.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['gl.cf-category.view', 'gl.currency.view', 'gl.kurs.view', 'gl.repair.view'], children: [
                { label: 'Multi Currency', href: 'bs.gl.currency.index' , permission: ['gl.currency.view'] , tampil: 'menu.bs.gl.currency.index' },
                { label: 'Kurs Sistem', href: 'bs.gl.kurs.index' , permission: ['gl.kurs.view'] , tampil: 'menu.bs.gl.kurs.index' },
                { label: 'Repair Data', href: 'bs.gl.repair.index' , permission: ['gl.repair.view'] , tampil: 'menu.bs.gl.repair.index' },
                { label: 'Kategori Cash Flow Direct', href: 'bs.gl.cf-category.index', params: { kind: 'direct' } , permission: ['gl.cf-category.view'] , tampil: 'menu.bs.gl.cf-category.index.direct' },
                { label: 'Kategori Cash Flow Indirect', href: 'bs.gl.cf-category.index', params: { kind: 'indirect' } , permission: ['gl.cf-category.view'] , tampil: 'menu.bs.gl.cf-category.index.indirect' },
            ]},
            { label: 'Master', icon: BookText, permission: ['gl.account.view', 'gl.cf-mapping.view', 'gl.cost-center.view', 'gl.journal-dimension.view', 'gl.journal.view'], children: [
                { label: 'Chart of Account', href: 'bs.accounts.index' , permission: ['gl.account.view'] , tampil: 'menu.bs.accounts.index' },
                { label: 'Jurnal', href: 'bs.gl.journal.index' , permission: ['gl.journal.view'] , tampil: 'menu.bs.gl.journal.index' },
                { label: 'Cost Center', href: 'bs.cost-centers.index' , permission: ['gl.cost-center.view'] , tampil: 'menu.bs.cost-centers.index' },
                { label: 'Mapping Cash Flow Direct', href: 'bs.gl.cf-mapping.index' , permission: ['gl.cf-mapping.view'] , tampil: 'menu.bs.gl.cf-mapping.index' },
                { label: 'Dimensi Jurnal', href: 'bs.journal-dimensions.index' , permission: ['gl.journal-dimension.view'] , tampil: 'menu.bs.journal-dimensions.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['gl.journal.view', 'gl.ledger.view'], children: [
                { label: 'Detail Transaksi Jurnal', href: 'bs.gl.journal.detail' , permission: ['gl.journal.view'] , tampil: 'menu.bs.gl.journal.detail' },
                { label: 'General Ledger', href: 'bs.gl.ledger' , permission: ['gl.ledger.view'] , tampil: 'menu.bs.gl.ledger' },
            ]},
            { label: 'Neraca', icon: Calculator, permission: ['gl.comparative.view', 'gl.extra-report.view', 'gl.financials.view', 'gl.trialbalance.view', 'gl.worksheet.view'], children: [
                { label: 'Neraca Saldo', href: 'bs.gl.trialbalance' , permission: ['gl.trialbalance.view'] , tampil: 'menu.bs.gl.trialbalance' },
                { label: 'Neraca', href: 'bs.gl.balancesheet' , permission: ['gl.financials.view'] , tampil: 'menu.bs.gl.balancesheet' },
                { label: 'Neraca Lajur', href: 'bs.gl.worksheet' , permission: ['gl.worksheet.view'] , tampil: 'menu.bs.gl.worksheet' },
                { label: 'Histori Saldo', href: 'bs.gl.extra', params: { mode: 'histori' } , permission: ['gl.extra-report.view'] , tampil: 'menu.bs.gl.extra.histori' },
                { label: 'Neraca Multi Periode', href: 'bs.gl.extra', params: { mode: 'multi' } , permission: ['gl.extra-report.view'] , tampil: 'menu.bs.gl.extra.multi' },
                { label: 'Neraca Komparasi (Bulanan)', href: 'bs.gl.comparative' , permission: ['gl.comparative.view'] , tampil: 'menu.bs.gl.comparative.neraca' },
                { label: 'Neraca Komparasi (Tahunan)', href: 'bs.gl.extra', params: { mode: 'tahunan' } , permission: ['gl.extra-report.view'] , tampil: 'menu.bs.gl.extra.tahunan' },
            ]},
            { label: 'Laba Rugi', icon: BarChart3, permission: ['gl.comparative.view', 'gl.extra-report.view', 'gl.financials.view'], children: [
                { label: 'Laba Rugi', href: 'bs.gl.incomestatement' , permission: ['gl.financials.view'] , tampil: 'menu.bs.gl.incomestatement' },
                { label: 'Laba Rugi vs Budget', href: 'bs.gl.lr-budget' , permission: ['gl.financials.view'] , tampil: 'menu.bs.gl.lr-budget' },
                { label: 'Laba Rugi Multi Periode', href: 'bs.gl.extra', params: { mode: 'lr-multi' } , permission: ['gl.extra-report.view'] , tampil: 'menu.bs.gl.extra.lr-multi' },
                { label: 'Laba Rugi Komparasi (Bulanan)', href: 'bs.gl.comparative' , permission: ['gl.comparative.view'] , tampil: 'menu.bs.gl.comparative.laba-rugi' },
                { label: 'Laba Rugi Komparasi (Tahunan)', href: 'bs.gl.extra', params: { mode: 'lr-tahunan' } , permission: ['gl.extra-report.view'] , tampil: 'menu.bs.gl.extra.lr-tahunan' },
                { label: 'Laba Rugi Perbandingan', href: 'bs.gl.extra', params: { mode: 'lr-banding' } , permission: ['gl.extra-report.view'] , tampil: 'menu.bs.gl.extra.lr-banding' },
            ]},
            { label: 'Cash Flow Direct', icon: PieChart, permission: ['gl.cashflow.direct.view'], children: [
                { label: 'Cash Flow Direct Multi Periode', href: 'bs.gl.cashflow', params: { method: 'direct' } , permission: ['gl.cashflow.direct.view'] , tampil: 'menu.bs.gl.cashflow.direct' },
            ]},
            { label: 'Cash Flow Indirect', icon: PieChart, permission: ['gl.cashflow.direct.view'], children: [
                { label: 'Cash Flow Indirect Multi Periode', href: 'bs.gl.cashflow', params: { method: 'indirect' } , permission: ['gl.cashflow.direct.view'] , tampil: 'menu.bs.gl.cashflow.indirect' },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['gl.saldo-awal.confirm', 'gl.saldo-awal.view'], children: [
                { label: 'Input Saldo Awal', href: 'bs.closing.saldoawal' , permission: ['gl.saldo-awal.view'] , tampil: 'menu.bs.closing.saldoawal' },
                { label: 'Confirm Saldo Awal', href: 'bs.gl.saldo-awal.confirm' , permission: ['gl.saldo-awal.confirm'] , tampil: 'menu.bs.gl.saldo-awal.confirm' },
            ]},
            { label: 'Jurnal Memorial', icon: FileText, permission: ['gl.journal.create', 'gl.journal.import'], children: [
                { label: 'Registrasi Jurnal Memorial', href: 'bs.gl.journal.create' , permission: ['gl.journal.create'] , tampil: 'menu.bs.gl.journal.create' },
                { label: 'Import Jurnal', href: 'bs.gl.journal.import' , permission: ['gl.journal.import'] , tampil: 'menu.bs.gl.journal.import' },
            ]},
            { label: 'Generate Lap. Keuangan', icon: FileCheck, permission: ['gl.journal.approve', 'gl.journal.finalize', 'gl.late-closing.view'], children: [
                { label: 'Finalisasi Jurnal', href: 'bs.gl.journal.finalisasi' , permission: ['gl.journal.finalize'] , tampil: 'menu.bs.gl.journal.finalisasi' },
                { label: 'Approval Jurnal', href: 'bs.gl.journal.approval' , permission: ['gl.journal.approve'] , tampil: 'menu.bs.gl.journal.approval' },
                { label: 'Late Closing', href: 'bs.gl.late-closing' , permission: ['gl.late-closing.view'] , tampil: 'menu.bs.gl.late-closing' },
            ]},
            { label: 'Generate Lap. Perusahaan', icon: Lock, permission: ['gl.closing.view'], children: [
                { label: 'Closing Fiskal', href: 'bs.closing.index' , permission: ['gl.closing.view'] , tampil: 'menu.bs.closing.index' },
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
            { label: 'Setup', icon: SlidersHorizontal, permission: ['ap.category.view', 'ap.repair.view'], children: [
                { label: 'Kategori Invoice', href: 'bs.ap.categories.index' , permission: ['ap.category.view'] , tampil: 'menu.bs.ap.categories.index' },
                { label: 'Kategori Gimmick', href: 'bs.gimmick.categories.index' , permission: ['ap.category.view'] , tampil: 'menu.bs.gimmick.categories.index' },
                { label: 'Repair Data', href: 'bs.ap.repair.index' , permission: ['ap.repair.view'] , tampil: 'menu.bs.ap.repair.index' },
            ]},
            { label: 'Master', icon: BookText, permission: ['ap.invoice.view', 'ap.kuitansi.view'], children: [
                { label: 'Invoice Hutang', href: 'bs.ap.index' , permission: ['ap.invoice.view'] , tampil: 'menu.bs.ap.index' },
                { label: 'Kuitansi Hutang', href: 'bs.ap.kuitansi.index' , permission: ['ap.kuitansi.view'] , tampil: 'menu.bs.ap.kuitansi.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['ap.aging.view', 'ap.invoice.view', 'ap.paycard.view', 'ap.penghapusan.view', 'tax.pph.view'], children: [
                { label: 'Kartu Hutang', href: 'bs.ap.card' , permission: ['ap.paycard.view'] , tampil: 'menu.bs.ap.card' },
                { label: 'Aging Hutang', href: 'bs.ap.aging', params: { view: 'detail' } , permission: ['ap.aging.view'] , tampil: 'menu.bs.ap.aging.detail' },
                // Outstanding per supplier: 20 terbesar + rangkuman sisanya. Angkanya dari
                // computeAging() yang sama dengan Aging Hutang, jadi keduanya tidak bisa berbeda.
                { label: 'Aging Hutang Supplier', href: 'bs.ap.aging-supplier' , permission: ['ap.aging.view'] , tampil: 'menu.bs.ap.aging-supplier' },
                { label: 'Proyeksi Hutang', href: 'bs.projection', params: { mode: 'hutang' } , permission: ['ap.invoice.view'] , tampil: 'menu.bs.projection.hutang' },
                { label: 'Kartu Pembayaran Hutang', href: 'bs.ap.payment-card' , permission: ['ap.paycard.view'] , tampil: 'menu.bs.ap.payment-card' },
                { label: 'Laporan Invoice Hutang', href: 'bs.ap.report' , permission: ['ap.invoice.view'] , tampil: 'menu.bs.ap.report' },
                { label: 'Laporan Penghapusan Hutang', href: 'bs.ap.penghapusan.report' , permission: ['ap.penghapusan.view'] , tampil: 'menu.bs.ap.penghapusan.report' },
                // Dipindah ke sini dari grup "General Function" yang dihapus: isinya laporan,
                // dan tempatnya laporan di modul ini adalah Query.
                { label: 'Rekap PPh Dipotong', href: 'bs.tax.pph' , permission: ['tax.pph.view'] , tampil: 'menu.bs.tax.pph' },
            ]},
            { label: 'Generate Invoice', icon: ClipboardList, permission: ['ap.invoice.create'], children: [
                { label: 'Registrasi Invoice Hutang', href: 'bs.ap.create' , permission: ['ap.invoice.create'] , tampil: 'menu.bs.ap.create' },
                { label: 'Reimburse Biaya Karyawan', href: 'bs.ap.reimburse.index' , permission: ['ap.reimburse.view'] , tampil: 'menu.bs.ap.reimburse.index' },
                { label: 'Deposit Renovasi Customer', href: 'bs.ap.deposit.index' , permission: ['ap.deposit.view'] , tampil: 'menu.bs.ap.deposit.index' },
            ]},
            { label: 'Penyesuaian Invoice', icon: Eraser, permission: ['ap.penghapusan.view'], children: [
                { label: 'Penghapusan Nilai Hutang', href: 'bs.ap.penghapusan.index' , permission: ['ap.penghapusan.view'] , tampil: 'menu.bs.ap.penghapusan.index' },
            ]},
            // Sumber 'po' memuat seluruh dokumen asal FO (penerimaan PO, refund, BLT, komisi, gimmick),
            // karena itu label memakai "dari FO" — bukan lagi khusus Purchase Order.
            // Tiga sub modul terpisah, masing-masing satu sumber dokumen. Dulu ketiganya
            // menunjuk rute yang sama (bs.ap.posting.index) dengan params `source` yang
            // tidak pernah dibaca controller, jadi "Posting Invoice SPK" sebenarnya membuka
            // layar yang persis sama dengan "Posting Invoice dari FO".
            { label: 'Posting Invoice dari FO', icon: FileCheck, permission: ['ap.posting.view'], children: [
                { label: 'Posting Invoice dari FO', href: 'bs.ap.posting.index' , permission: ['ap.posting.view'] , tampil: 'menu.bs.ap.posting.index' },
            ]},
            { label: 'Posting Invoice dari Purchase Order', icon: ShoppingCart, permission: ['ap.posting.view'], children: [
                { label: 'Posting Invoice dari Purchase Order', href: 'bs.ap.posting.po.index' , permission: ['ap.posting.view'] , tampil: 'menu.bs.ap.posting.po.index' },
            ]},
            { label: 'Posting Invoice SPK', icon: HardHat, permission: ['ap.posting.view'], children: [
                { label: 'Posting Invoice SPK', href: 'bs.ap.posting.spk.index' , permission: ['ap.posting.view'] , tampil: 'menu.bs.ap.posting.spk.index' },
                // Lanjutan rantainya: sesudah biaya SPK menjadi hutang, nilai rumahnya
                // dipindahkan dari Persediaan Dalam Proses ke Barang Siap Dijual.
                { label: 'Posting ke Barang Siap Dijual', href: 'bs.ap.barang-siap-jual.index' , permission: ['ap.posting.view'] , tampil: 'menu.bs.ap.barang-siap-jual.index' },
                // "Kontrak SPK (MUK)" DIPINDAH ke Contractor Management → Master, tepat di atas
                // Surat Perintah Kerja (permintaan user). Rute & layarnya tidak ikut pindah —
                // yang berpindah hanya tempatnya di menu.
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['ap.opening.view'], children: [
                { label: 'Saldo Awal', href: 'bs.ap.opening.index' , permission: ['ap.opening.view'] , tampil: 'menu.bs.ap.opening.index' },
            ]},
        ],
    },
    {
        title: 'Cash Book',
        icon: Wallet, color: '#6366f1', route: 'bs.cashbook.printhub',
        desc: 'Kas, Bank, Giro, Bon Sementara & approval.',
        items: [
            { label: 'General Function', icon: FileText, permission: ['cashbook.print.view'], children: [
                // Satu-satunya anak grup ini; namanya disamakan dengan grupnya agar tidak
                // terbaca sebagai dua tingkat berbeda ("General Function → Print-Out").
                { label: 'General Function', href: 'bs.cashbook.printhub' , permission: ['cashbook.print.view'] , tampil: 'menu.bs.cashbook.printhub' },
            ]},
            { label: 'Setup', icon: SlidersHorizontal, permission: ['cashbook.print.view', 'cashbook.repair.view', 'cashbook.saldo.view'], children: [
                // 'Rekening' DIHAPUS dari menu: rutanya milik modul Finance lama yang sudah
                // dimatikan config/modul_pindah_fo.php, jadi ia tidak pernah terdaftar dan
                // butirnya memang sudah tak pernah tampil. Membiarkannya di sini menahan
                // seluruh grup Setup dari penyelarasan izin, karena satu anak tanpa izin
                // membuat gabungannya tidak bisa dihitung.
                // Barcode tanda tangan yang dipakai pada Persetujuan (TTD) SIP.
                { label: 'Set Up Barcode', href: 'bs.cashbook.signature-barcode.index' , permission: ['cashbook.print.view'] , tampil: 'menu.bs.cashbook.signature-barcode.index' },
                // Kartu debit/VA: saldo & masa berlakunya. Bon sementara berpembayaran
                // Debit VA melahirkan permintaan saldo & expired di sini.
                { label: 'Set Up Debit Card', href: 'bs.cashbook.credit-card.index' , permission: ['cashbook.saldo.view'] , tampil: 'menu.bs.cashbook.credit-card.index' },
                { label: 'Repair Data', href: 'bs.cashbook.repair.index' , permission: ['cashbook.repair.view'] , tampil: 'menu.bs.cashbook.repair.index' },
            ]},
            { label: 'Master', icon: Wallet, permission: ['cashbook.cheque-out.view', 'cashbook.giro.view', 'cashbook.pettycash.view', 'cashbook.transaction.view'], children: [
                { label: 'Transfer Rekening', href: 'bs.cashbook.index', params: { type: 'transfer' } , permission: ['cashbook.transaction.view'] , tampil: 'menu.bs.cashbook.index.transfer' },
                { label: 'Kas Masuk', href: 'bs.cashbook.index', params: { type: 'in' } , permission: ['cashbook.transaction.view'] , tampil: 'menu.bs.cashbook.index.in' },
                { label: 'Kas Keluar', href: 'bs.cashbook.index', params: { type: 'out' } , permission: ['cashbook.transaction.view'] , tampil: 'menu.bs.cashbook.index.out.master' },
                { label: 'Kas Kecil Masuk', href: 'bs.pettycash.index', params: { type: 'in' } , permission: ['cashbook.pettycash.view'] , tampil: 'menu.bs.pettycash.index.in' },
                { label: 'Kas Kecil Keluar', href: 'bs.pettycash.index', params: { type: 'out' } , permission: ['cashbook.pettycash.view'] , tampil: 'menu.bs.pettycash.index.out' },
                { label: 'Cek Giro Masuk', href: 'bs.giro.index' , permission: ['cashbook.giro.view'] , tampil: 'menu.bs.giro.index.master' },
                { label: 'Cek Giro Keluar', href: 'bs.cheque-out.index' , permission: ['cashbook.cheque-out.view'] , tampil: 'menu.bs.cheque-out.index.master' },
            ]},
            { label: 'Report/Query', icon: FileBarChart, permission: ['cashbook.advance.card', 'cashbook.advance.report', 'cashbook.giro-calendar.view', 'cashbook.ledger.view', 'cashbook.saldo.view', 'cashbook.transaction.view'], children: [
                { label: 'Rekening Ledger', href: 'bs.cashbook.ledger' , permission: ['cashbook.ledger.view'] , tampil: 'menu.bs.cashbook.ledger' },
                { label: 'Rekening Saldo', href: 'bs.cashbook.balances' , permission: ['cashbook.saldo.view'] , tampil: 'menu.bs.cashbook.balances' },
                { label: 'Kalender Cek Giro', href: 'bs.cashbook.giro-calendar' , permission: ['cashbook.giro-calendar.view'] , tampil: 'menu.bs.cashbook.giro-calendar' },
                { label: 'Pengeluaran Kas & Bank', href: 'bs.cashbook.index', params: { type: 'out' } , permission: ['cashbook.transaction.view'] , tampil: 'menu.bs.cashbook.index.out.report-query' },
                { label: 'Kartu Bon Sementara', href: 'bs.advance.card' , permission: ['cashbook.advance.card'] , tampil: 'menu.bs.advance.card' },
                // Saldo kartu debit/VA yang dipecah per nomor bon — angkanya sama dengan
                // Set Up Debit Card, hanya sudut pandangnya yang per bon, bukan per kartu.
                { label: 'Saldo Debit Card', href: 'bs.advance.card-balance' , permission: ['cashbook.advance.card'] , tampil: 'menu.bs.advance.card-balance' },
                { label: 'Rekap Bon Sementara', href: 'bs.advance.report' , permission: ['cashbook.advance.report'] , tampil: 'menu.bs.advance.report' },
                // Outstanding Kasbon adalah laporan (saldo awal · penambahan · realisasi ·
                // outstanding per karyawan), bukan tempat menginput — tempatnya di Report/Query,
                // tepat di bawah Rekap Bon Sementara.
                { label: 'Outstanding Kasbon', href: 'bs.cashbook.outstanding-kasbon' , permission: ['cashbook.advance.report'] , tampil: 'menu.bs.cashbook.outstanding-kasbon' },
            ]},
            { label: 'Saldo Awal', icon: Coins, permission: ['cashbook.opening.view'], children: [
                { label: 'Saldo Awal Rekening', href: 'bs.cashbook.opening.index' , permission: ['cashbook.opening.view'] , tampil: 'menu.bs.cashbook.opening.index' },
            ]},
            { label: 'Transaksi Pengeluaran Bank', icon: Banknote, permission: ['ap.kuitansi.create', 'bs.sip.*', 'cashbook.transaction.approve', 'cashbook.transaction.create'], children: [
                { label: 'Registrasi Pengeluaran Kas & Bank', href: 'bs.cashbook.create', params: { type: 'out' } , permission: ['cashbook.transaction.create'] , tampil: 'menu.bs.cashbook.create.out' },
                { label: 'Registrasi Pengeluaran Kas & Bank (AP)', href: 'bs.ap.kuitansi.create' , permission: ['ap.kuitansi.create'] , tampil: 'menu.bs.ap.kuitansi.create' },
                { label: 'Approval Pengeluaran Kas & Bank', href: 'bs.cashbook.approvals' , permission: ['cashbook.transaction.approve'] , tampil: 'menu.bs.cashbook.approvals' },
                { label: 'SIP (Instruksi Bayar)', href: 'bs.sip.index', permission: ['bs.sip.*'] , tampil: 'menu.bs.sip.index' },
            ]},
            { label: 'Transaksi Penerimaan Bank', icon: Landmark, permission: ['cashbook.approval-retensi.view', 'cashbook.approval-utjdp.view', 'cashbook.transaction.create'], children: [
                { label: 'Transfer Antar Rekening', href: 'bs.cashbook.create', params: { type: 'transfer' } , permission: ['cashbook.transaction.create'] , tampil: 'menu.bs.cashbook.create.transfer' },
                { label: 'Penerimaan Bank', href: 'bs.cashbook.create', params: { type: 'in' } , permission: ['cashbook.transaction.create'] , tampil: 'menu.bs.cashbook.create.in' },
                { label: 'Approval Retensi', href: 'bs.cashbook.approval-retensi' , permission: ['cashbook.approval-retensi.view'] , tampil: 'menu.bs.cashbook.approval-retensi' },
                { label: 'Approval UM Penjualan', href: 'bs.cashbook.approval-um' , permission: ['cashbook.approval-utjdp.view'] , tampil: 'menu.bs.cashbook.approval-um' },
                { label: 'Approval UTJ', href: 'bs.cashbook.approval-utjdp' , permission: ['cashbook.approval-utjdp.view'] , tampil: 'menu.bs.cashbook.approval-utjdp' },
                // Penerimaan dari Pelunasan DP, Angsuran & Tambah DP (FO) — masuk otomatis.
                { label: 'Approval DP', href: 'bs.cashbook.approval-dp' , permission: ['cashbook.approval-utjdp.view'] , tampil: 'menu.bs.cashbook.approval-dp' },
            ]},
            // ── MILIK PENGAJU ──────────────────────────────────────────────────────
            // Pengaju berhenti di PENGAJUAN. Seluruh langkah sesudahnya (bayar, realisasi,
            // alokasi) milik Dept Finance dan pindah ke grupnya sendiri di bawah — bukan
            // karena rahasia, melainkan karena menampilkannya kepada pengaju membuat orang
            // mengira bon itu urusannya sampai selesai, lalu menunggu tombol yang tidak akan
            // pernah bisa ia tekan.
            { label: 'Transaksi Bon Sementara', icon: HandCoins, permission: ['cashbook.advance.create', 'cashbook.advance.view'], children: [
                { label: 'Pengajuan Bon Sementara', href: 'bs.advance.create' , permission: ['cashbook.advance.create'] , tampil: 'menu.bs.advance.create' },
                // Di sinilah pengaju MEREALISASIKAN bonnya — rincian pemakaian. Karena itu
                // namanya Realisasi, bukan sekadar daftar. Berhenti di situ: sisa atau
                // kekurangannya (Refund/Reimburse) diselesaikan Dept Finance, sebab keduanya
                // memindahkan uang masuk/keluar bank.
                { label: 'Realisasi Bon Sementara', href: 'bs.advance.index' , permission: ['cashbook.advance.view'] , tampil: 'menu.bs.advance.index' },
            ]},

            // ── MILIK DEPT FINANCE ─────────────────────────────────────────────────
            // Seluruh proses Finance dikumpulkan di satu grup, bukan tersebar di Transaksi.
            // Izinnya cashbook.advance.pay — itulah yang membedakan Finance dari pengaju.
            { label: 'Bon Sementara (Finance)', icon: HandCoins, permission: ['cashbook.advance.pay', 'cashbook.advance.view'], children: [
                { label: 'Bon Sementara Finance', href: 'bs.advance.payments' , permission: ['cashbook.advance.view'] , tampil: 'menu.bs.advance.payments' },
                // Alokasi berdiri sendiri di antara pembayaran dan realisasi: bon baru sampai
                // ke Realisasi setelah tombol "Lanjut Realisasikan Bon" ditekan di sini.
                { label: 'Alokasi Bon Sementara Finance', href: 'bs.advance.allocation.index' , permission: ['cashbook.advance.view'] , tampil: 'menu.bs.advance.allocation.index' },
                { label: 'Realisasi Bon Sementara Finance', href: 'bs.advance.index', params: { finance: 1 } , permission: ['cashbook.advance.view'] , tampil: 'menu.bs.advance.index.1' },
                // Riwayat pengajuan yang DITOLAK sebelum dibayar — baca-saja, tanpa jurnal.
                { label: 'Transaksi Bon Batal (Riwayat)', href: 'bs.advance.cancelled' , permission: ['cashbook.advance.pay'] , tampil: 'menu.bs.advance.cancelled' },
            ]},
            { label: 'Transaksi Kas Kecil', icon: Wallet, permission: ['cashbook.pettycash.create'], children: [
                { label: 'Penerimaan Kas Kecil', href: 'bs.pettycash.create', params: { type: 'in' } , permission: ['cashbook.pettycash.create'] , tampil: 'menu.bs.pettycash.create.in' },
                { label: 'Pengeluaran Kas Kecil', href: 'bs.pettycash.create', params: { type: 'out' } , permission: ['cashbook.pettycash.create'] , tampil: 'menu.bs.pettycash.create.out' },
            ]},
            { label: 'Transaksi Cek Giro Masuk', icon: FileText, permission: ['cashbook.giro.create', 'cashbook.giro.view'], children: [
                { label: 'Penerimaan Cek Giro', href: 'bs.giro.create' , permission: ['cashbook.giro.create'] , tampil: 'menu.bs.giro.create' },
                { label: 'Kliring Cek Giro Masuk', href: 'bs.giro.index' , permission: ['cashbook.giro.view'] , tampil: 'menu.bs.giro.index.kliring-cek-giro-masuk' },
                { label: 'Tolakan Cek Giro Masuk', href: 'bs.giro.index' , permission: ['cashbook.giro.view'] , tampil: 'menu.bs.giro.index.tolakan-cek-giro-masuk' },
            ]},
            { label: 'Transaksi Cek Giro Keluar', icon: FileText, permission: ['cashbook.cheque-out.view'], children: [
                { label: 'Data BG/Cek Keluar', href: 'bs.cheque-out.index' , permission: ['cashbook.cheque-out.view'] , tampil: 'menu.bs.cheque-out.index.transaksi-cek-giro-keluar' },
            ]},
            // Modul Cash Flow: kategorinya menjadi pilihan "Pembagian Biaya per Cash Flow" di
            // Rekening Ledger, dan Report Cashflow merekap pembagian tersebut.
            { label: 'Cash Flow', icon: BarChart3, permission: ['cashbook.ledger.view'], children: [
                { label: 'Set Up Kategori', href: 'bs.cashflow.categories' , permission: ['cashbook.ledger.view'] , tampil: 'menu.bs.cashflow.categories' },
                { label: 'Set Up Rumus', href: 'bs.cashflow.formulas' , permission: ['cashbook.ledger.view'] , tampil: 'menu.bs.cashflow.formulas' },
                { label: 'Report Cashflow', href: 'bs.cashflow.report' , permission: ['cashbook.ledger.view'] , tampil: 'menu.bs.cashflow.report' },
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
            { label: 'Master', icon: BookText, permission: ['budget.allocation.view', 'budget.correction.view', 'budget.master.view'], children: [
                { label: 'Budget Control', href: 'bs.budget.index' , permission: ['budget.master.view'] , tampil: 'menu.bs.budget.index' },
                { label: 'Alokasi Budget Control', href: 'bs.budget.allocation.index' , permission: ['budget.allocation.view'] , tampil: 'menu.bs.budget.allocation.index' },
                { label: 'Koreksi Budget Control', href: 'bs.budget.correction.index' , permission: ['budget.correction.view'] , tampil: 'menu.bs.budget.correction.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['budget.query.allocation', 'budget.query.correction', 'budget.query.master', 'budget.query.realization'], children: [
                { label: 'Master Budget', href: 'bs.budget.query.master' , permission: ['budget.query.master'] , tampil: 'menu.bs.budget.query.master' },
                { label: 'Koreksi Budget', href: 'bs.budget.query.correction' , permission: ['budget.query.correction'] , tampil: 'menu.bs.budget.query.correction' },
                { label: 'Realisasi Budget', href: 'bs.budget.query.realization' , permission: ['budget.query.realization'] , tampil: 'menu.bs.budget.query.realization' },
                { label: 'Alokasi Budget', href: 'bs.budget.query.allocation' , permission: ['budget.query.allocation'] , tampil: 'menu.bs.budget.query.allocation' },
                { label: 'Report Budget', href: 'bs.budget.query.report' , permission: ['budget.query.realization'] , tampil: 'menu.bs.budget.query.report' },
            ]},
            { label: 'Upload Budget', icon: FileSpreadsheet, permission: ['budget.master.approve', 'budget.upload.view'], children: [
                { label: 'Upload Budget', href: 'bs.budget.upload.index' , permission: ['budget.upload.view'] , tampil: 'menu.bs.budget.upload.index' },
                { label: 'Approval Budget', href: 'bs.budget.approvals' , permission: ['budget.master.approve'] , tampil: 'menu.bs.budget.approvals' },
            ]},
            { label: 'Alokasi Budget', icon: Layers, permission: ['budget.allocation.approve', 'budget.allocation.create'], children: [
                { label: 'Alokasi Budget', href: 'bs.budget.allocation.create' , permission: ['budget.allocation.create'] , tampil: 'menu.bs.budget.allocation.create' },
                { label: 'Approval Alokasi Budget', href: 'bs.budget.allocation.approvals' , permission: ['budget.allocation.approve'] , tampil: 'menu.bs.budget.allocation.approvals' },
            ]},
            { label: 'Koreksi Budget', icon: ShieldCheck, permission: ['budget.correction.approve', 'budget.correction.create'], children: [
                { label: 'Koreksi Budget', href: 'bs.budget.correction.create' , permission: ['budget.correction.create'] , tampil: 'menu.bs.budget.correction.create' },
                { label: 'Approval Koreksi Budget', href: 'bs.budget.correction.approvals' , permission: ['budget.correction.approve'] , tampil: 'menu.bs.budget.correction.approvals' },
            ]},
        ],
    },
    {
        title: 'Contractor Management',
        icon: HardHat, color: '#f97316', route: 'bs.spk.index',
        desc: 'SPK, progress lapangan & retensi.',
        items: [
            { label: 'Setup', icon: FolderGit2, permission: ['contractor.project.view', 'contractor.spk.update'], children: [
                { label: 'Project', href: 'bs.spk.project.index' , permission: ['contractor.project.view'] , tampil: 'menu.bs.spk.project.index' },
                // Menyandingkan nama rumah versi MUK dengan kode unit kita. Di Setup, bukan
                // Master: ia menyetel dasar perhitungan, bukan mencatat transaksi.
                { label: 'Pasangan Unit MUK', href: 'bs.muk.pasangan.index' , permission: ['contractor.project.view'] , tampil: 'menu.bs.muk.pasangan.index' },
                { label: 'Set Up SPK', href: 'bs.spk.draft.edit' , permission: ['contractor.spk.update'] , tampil: 'menu.bs.spk.draft.edit' },
            ]},
            // 'ap.posting.*' IKUT di daftar izin grup ini semata-mata karena "Kontrak SPK (MUK)"
            // pindah ke sini: layarnya dijaga izin ap.posting.view (MukKontrakController:33,77),
            // bukan izin contractor. Tanpa baris itu, pengguna yang selama ini bisa membukanya
            // dari Account Payable akan kehilangan menunya sama sekali — grup Master-nya tidak
            // pernah dirender untuk mereka.
            { label: 'Master', icon: FileSignature, permission: ['ap.posting.*', 'contractor.kpl.view', 'contractor.retention.view', 'contractor.spk.view'], children: [
                // Baca-saja dari MUK ERP — sumber angka kontrak & penagihan SPK. Izinnya
                // DISEBUT di baris ini sendiri (bukan diwarisi grup): layar ini menuntut
                // ap.posting.view, jadi menampilkannya kepada pemegang izin contractor saja
                // berarti menyodorkan menu yang berakhir 403 saat diklik.
                { label: 'Kontrak SPK (MUK)', href: 'bs.muk.kontrak.index', permission: ['ap.posting.*'] , tampil: 'menu.bs.muk.kontrak.index' },
                // Lampiran 2 — nilai SPK + sudah/belum tertagih. Izinnya DISEBUT sendiri
                // dengan alasan yang sama seperti baris di atas: layarnya menuntut
                // ap.posting.view, bukan izin contractor yang memayungi grup ini.
                { label: 'Informasi CTC', href: 'bs.muk.ctc.index', permission: ['ap.posting.*'] , tampil: 'menu.bs.muk.ctc.index' },
                // COP Owner dari MUK beserta tombol Posting ke AP. Izinnya disebut sendiri,
                // alasan yang sama dengan dua baris di atasnya.
                { label: 'Tagihan Progress Project', href: 'bs.muk.tagihan.index', permission: ['ap.posting.*'] , tampil: 'menu.bs.muk.tagihan.index' },
                { label: 'Surat Perintah Kerja', href: 'bs.spk.index' , permission: ['contractor.spk.view'] , tampil: 'menu.bs.spk.index' },
                { label: 'Kontrol Progress Lapangan', href: 'bs.spk.kpl.index' , permission: ['contractor.kpl.view'] , tampil: 'menu.bs.spk.kpl.index' },
                { label: 'Progress Retensi', href: 'bs.spk.retention.index' , permission: ['contractor.retention.view'] , tampil: 'menu.bs.spk.retention.index' },
            ]},
            { label: 'Query', icon: FileBarChart, permission: ['contractor.query.view'], children: [
                { label: 'Rekap SPK', href: 'bs.spk.query.rekap' , permission: ['contractor.query.view'] , tampil: 'menu.bs.spk.query.rekap' },
                { label: 'Kartu Hutang Supplier - SPK', href: 'bs.spk.query.kartu-hutang' , permission: ['contractor.query.view'] , tampil: 'menu.bs.spk.query.kartu-hutang' },
                { label: 'Pemakaian Barang', href: 'bs.spk.query.pemakaian' , permission: ['contractor.query.view'] , tampil: 'menu.bs.spk.query.pemakaian' },
                { label: 'Summary SPK', href: 'bs.spk.query.summary' , permission: ['contractor.query.view'] , tampil: 'menu.bs.spk.query.summary' },
            ]},
            { label: 'Surat Perintah Kerja', icon: ClipboardList, permission: ['contractor.kpl.create', 'contractor.retention.view', 'contractor.spk.create', 'contractor.spk.view'], children: [
                { label: 'Registrasi Surat Perintah Kerja', href: 'bs.spk.create' , permission: ['contractor.spk.create'] , tampil: 'menu.bs.spk.create' },
                { label: 'Registrasi Kontrol Progress Lapangan', href: 'bs.spk.kpl.create' , permission: ['contractor.kpl.create'] , tampil: 'menu.bs.spk.kpl.create' },
                { label: 'Registrasi Retensi', href: 'bs.spk.retention.create' , permission: ['contractor.retention.view'] , tampil: 'menu.bs.spk.retention.create' },
                { label: 'Addendum SPK (Tambah / Kurang)', href: 'bs.spk.addendum.form' , permission: ['contractor.spk.view'] , tampil: 'menu.bs.spk.addendum.form' },
                { label: 'Adjustment PPN', href: 'bs.spk.adjust-ppn.form' , permission: ['contractor.spk.view'] , tampil: 'menu.bs.spk.adjust-ppn.form' },
                { label: 'Pembatalan Surat Perintah Kerja', href: 'bs.spk.cancel.form' , permission: ['contractor.spk.view'] , tampil: 'menu.bs.spk.cancel.form' },
            ]},
        ],
    },
    // ── COGS LAMA DITUTUP (12 Okt 2026) ──────────────────────────────────────────
    // Grupnya dicabut dari sidebar dan izin `cogs.*`-nya dihapus migrasi
    // 2026_10_12_100000. Alasannya bukan sekadar "tidak dipakai": seluruh masternya
    // memang kosong di server (lokasi, budget, kategori, ketentuan, setup, realisasi =
    // 0 baris), dan satu-satunya isinya — 71 baris HPP RealCost — semuanya berstatus
    // `generated` dengan NOL jurnal, jadi buku besar tidak pernah tersentuh olehnya.
    //
    // Rute & kodenya SENGAJA tidak dibuang. Tanpa izin, layarnya berhenti di 403; kalau
    // ternyata masih ada yang mencarinya, izinnya tinggal diterbitkan lagi tanpa
    // mengembalikan kode apa pun. Datanya juga tidak dihapus — menutup menu tidak sama
    // dengan menghapus riwayat.

    {
        // ── COGS ─────────────────────────────────────────────────────────────────────
        // Mengikuti lampiran "MODUL HPP.xlsx": budget per komponen diturunkan ke tiap unit
        // menurut LUAS, lalu biaya aktual dibebankan ulang ke unit yang belum terkunci
        // HPP-nya. Namanya kembali "COGS" begitu model lama ditutup — embel-embel "Baru"
        // hanya berguna selagi keduanya hidup berdampingan, dan setelah itu justru membuat
        // orang mencari-cari di mana "COGS Lama"-nya.
        title: 'COGS',
        icon: Calculator, color: '#0ea5e9', route: 'bs.cogs2.index',
        desc: 'Perhitungan COGS, kategori, actual costing & rincian per unit.',
        items: [
            // Seluruh layar COGS Baru berbutir PERIODE (lokasi + tahap), sedangkan butir menu
            // tidak bisa tahu periode mana yang sedang dikerjakan. Rute 'bs.cogs2.buka' membuka
            // periode yang terakhir disunting; kalau belum ada, ia mendarat di daftar periode.
            { label: 'Perhitungan COGS', icon: Calculator, permission: ['cogs2.view'], children: [
                { label: 'Perhitungan COGS', href: 'bs.cogs2.buka', params: { layar: 'perhitungan' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.perhitungan' },
                { label: 'Daftar Periode', href: 'bs.cogs2.index' , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.index' },
            ]},
            { label: 'Set Up Kategori COGS', icon: SlidersHorizontal, permission: ['cogs2.view'], children: [
                { label: 'Set Up Kategori COGS-Tanah', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'tanah' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.kategori.tanah' },
                { label: 'Set Up Kategori COGS-Housing', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'housing' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.kategori.housing' },
                { label: 'Set Up Kategori COGS-Infra', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'infra' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.kategori.infra' },
                { label: 'Set Up Kategori COGS-License', href: 'bs.cogs2.buka', params: { layar: 'kategori', komponen: 'license' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.kategori.license' },
            ]},
            { label: 'Actual Costing COGS', icon: ReceiptText, permission: ['cogs2.view'], children: [
                { label: 'Actual Costing COGS-Tanah', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'tanah' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.actual.tanah' },
                { label: 'Actual Costing COGS-Housing', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'housing' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.actual.housing' },
                { label: 'Actual Costing COGS-Infra', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'infra' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.actual.infra' },
                { label: 'Actual Costing COGS-License', href: 'bs.cogs2.buka', params: { layar: 'actual', komponen: 'license' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.actual.license' },
            ]},
            { label: 'Rincian COGS', icon: FileBarChart, permission: ['cogs2.view'], children: [
                { label: 'Rincian COGS', href: 'bs.cogs2.buka', params: { layar: 'rincian' } , permission: ['cogs2.view'] , tampil: 'menu.bs.cogs2.buka.rincian' },
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
