<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Save, HandCoins, Plus, Trash2, Check, Search, Copy, Share2, ClipboardPaste, Files, FileText, ChevronDown, Building2, Upload, Link2, RefreshCw, X, Signature, CircleCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import { pilihCc, opsiCc } from '@/Composables/costCenterAkun';
import PesanWajib from '@/Components/UI/PesanWajib.vue';
import { tandai, kosong, pesanKolom, CINCIN_MERAH } from '@/Composables/useWajib';
import { gabungBerkas } from '@/Composables/useGabungPdf';
import DropBerkas, { type FileBerkas } from '@/Components/UI/DropBerkas.vue';
import { berkasTitipan, tebakJenis } from '@/Composables/berkasTitipan';
import { bangunStrukturJurnal, akunKurang, samaDenganBawaan, barisStruktur, akunBiayaPphDitanggung } from './strukturJurnal';

type BerkasPilihan = {
    id: number; number: string; status: string; status_label: string; supplier_id: number | null; supplier: string | null;
    supplier_invoice_no: string | null; description: string; document_date: string | null; needed_date: string | null;
    amount: number | null; sisa: number | null; dept_head: string | null; uploader: string | null;
    bisa_diinvoice: boolean; bisa_diubah: boolean;
    files: Array<{ id: number; name: string; jenis: string | null; mime: string; url: string }>;
};

const props = defineProps<{
    /** Modul: Invoice Hutang / Reimburse Karyawan / Deposit Renovasi — alur sama, penerima & akun beda. */
    jenis: { kunci: string; judul: string; penerima: string; rute: string };
    suppliers: Array<any>;
    /** Pilihan penerima Reimburse (karyawan) & Deposit (customer). */
    employees?: Array<any>;
    customers?: Array<any>;
    categories: Array<any>;
    pphAccounts: Array<any>;
    pphDefaults: Record<string, number>;
    ppnAccount: any;
    /** Jenis PPh → akun BEBAN untuk PPh ditanggung (pasangan debit dari pphDefaults). */
    pphBorneDefaults: Record<string, number | null>;
    /** Cadangan akun beban bila jenis PPh belum dipilih. */
    pphBorneExpense: any;
    accounts: Array<any>;
    costCenters: Array<any>;
    /** Cost center unit kerja pengaju — mengisi tiap baris jurnal secara otomatis. */
    ccPengguna?: number | null;
    /** Semua unit kerja pengaju (Utama dulu) — dicocokkan dengan daftar cost center COA. */
    ccUser?: number[];
    /** Hanya Accounting (ap.costcenter.override) yang boleh mengubahnya. */
    bisaUbahCc?: boolean;
    /** Kode COA yang tidak dianggarkan — tidak diisi cost center & tidak dituntut mengisinya. */
    coaTanpaAnggaran?: string[];
    /** Mode EDIT (sebelum diproses FAT): isian invoice lama. null/undefined = registrasi baru. */
    invoiceEdit?: { id: number; invoice_number: string; pihak_nama: string | null; journal_id?: number | null; data: Record<string, any> } | null;
    /** Registrasi dari Upload Berkas (?berkas=) — isian awal + file pendukung; tersimpan → diajukan ke approval. */
    dariBerkas?: {
        id: number; number: string; supplier_id: number | null; supplier_invoice_no: string | null;
        amount: number | null; description: string; files: Array<{ name: string; jenis: string | null; url: string }>;
        /** v2: tanggal dokumen, pajak, cost center, dan sisa untuk invoice berikutnya. */
        invoice_date?: string | null; dpp?: number | null; ppn_mode?: 'none' | 'with'; ppn_pct?: number; ppn_incl?: boolean;
        cost_center_id?: number | null; sisa?: number | null; invoiced?: number;
    } | null;
    /** Registrasi baru Invoice Hutang: berkas unggahan user sendiri — WAJIB dipilih di langkah Input Transaksi. null = tidak dipakai. */
    berkasSaya?: BerkasPilihan[] | null;
    /** Draft isian wizard (disimpan saat user menekan "Edit" berkas, atau "Simpan Draft"). */
    draftInvoice?: { id?: number | null; data: Record<string, any>; step: number; updated_at: string | null } | null;
    /** TTD Dept Head: saya = pembuat adalah Dept Head (TTD otomatis); nama = Dept Head (atasan) pembuat. */
    ttdDeptHead?: { saya: boolean; nama: string | null } | null;
    /** Pengajuan Budget (alokasi / koreksi langkah Struktur) yang sedang dilanjutkan — dibuka dari ?pengajuan=. */
    pengajuanBudget?: {
        id: number; status: 'menunggu' | 'disetujui' | 'ditolak'; catatan: string | null; invoice_number: string | null;
        diajukan: string | null; diputuskan: string | null; data: Record<string, any>;
        item: Array<{ jenis: string; doc_no: string; nilai: number; status: string; approval_url: string | null }>;
    } | null;
    departments: Array<any>;
    ledgers: Array<any>;
    locations: Array<any>;
    units: Array<any>;
    /** Memo Urgent yang sudah disetujui Direktur & belum dipakai invoice lain (satu memo per invoice, opsional). */
    /** Memo Urgent disetujui Direktur tapi belum dibayar di Kuitansi Hutang — belum bisa ditarik. */
    memoMenungguBayar?: Array<{ number: string; subject: string }>;
    memoUrgent?: Array<{ id: number; number: string; date: string | null; department: string | null; supplier_id: number | null; supplier: string | null; subject: string; amount: number; status: string; url: string | null;
        /** Rincian pajak dari form Memo Urgent — mengisi & MENGUNCI tahap 2; null = memo lama. */
        pajak?: { dpp: number; ppn_mode: 'none' | 'with'; ppn_pct: number; ppn_incl: boolean; ppn_base: number; pph_mode: 'none' | 'withheld' | 'borne'; pph_type: string | null; pph_pct: number; pph_base: number } | null }>;
    bisaBuatMemo?: boolean;
    /** Link berkas supplier yang sedang dilanjutkan (?link=) — nomor invoice sudah dipesan. */
    linkBerkas?: { id: number; url: string; invoice_number: string; status: string; filled_at: string | null; berkas_upload_id: number | null; riwayat?: Array<{ at: string; berkas_number: string; no_invoice: string | null; file: number }>; menerima?: boolean } | null;
    /** Jenis dokumen untuk Upload File (blok Berkas Tagihan, langkah Input Transaksi). */
    jenisBerkas?: Array<{ id: number; code?: string; name: string; is_mandatory: boolean }>;
}>();

const steps = ['Pilih ' + props.jenis.penerima, 'Input Transaksi', 'Detil Tagihan', 'Struktur', 'Selesai'];
/* EDIT: penerima tidak bisa diganti (ganti penerima = Batal lalu buat baru), jadi wizard dimulai
   dari Input Transaksi dan tidak bisa mundur ke langkah 1. */
const modeEdit = !! props.invoiceEdit;
/* Draft dipulihkan pada registrasi baru — kecuali dibuka dari ?berkas= (server tidak mengirimnya). */
const pakaiDraft = !modeEdit && !!props.draftInvoice && !props.pengajuanBudget;
// Melanjutkan Pengajuan Budget → langsung ke langkah Struktur (tempat statusnya ditampilkan).
const step = ref(props.pengajuanBudget ? 4 : (modeEdit ? 2 : (pakaiDraft ? props.draftInvoice!.step : 1)));
const today = new Date().toISOString().slice(0, 10);
const supplierSearch = ref('');

const form = useForm<any>({
    supplier_id: '', category_id: '', supplier_invoice_no: '', manual_no: '',
    invoice_date: today, receive_date: today, term_days: '', due_date: '',
    description: '', dpp: 0,
    ppn_mode: 'none', ppn_pct: 11, ppn_base: 0, ppn_incl: false, // #140 · ppn_incl: false=Exclude PPN, true=Include PPN
    pph_mode: 'none', pph_type: '', pph_pct: 0, pph_base: 0, pph_account_id: '', // #92/#94 — default tarif PPh 0% (dipungut & ditanggung)
    lines: [], schedules: [], journal_lines: [],
    // Koreksi budget yang diajukan bersama invoice (baris Struktur yang melewati sisa budget bulan ini).
    koreksi: [],
    // ATAU alokasi: kekurangannya diambil dari sisa bulan yang sama di budget lain (menunggu Approval Alokasi Budget).
    alokasi: [],
    // Memo Urgent yang ditarik ke invoice ini (opsional).
    memo_internal_id: null,
    // Penerima Reimburse (karyawan) / Deposit (customer + rekening yang diketik).
    employee_id: '', customer_id: '', bank_name: '', bank_account_no: '', bank_account_name: '',
    // Dari Upload Berkas: isian awal diambil dari berkas; berkas_upload_id membuat server
    // mengajukan invoice ini ke approval begitu tersimpan.
    berkas_upload_id: props.dariBerkas?.id ?? '',
    // Link berkas supplier (Generate Link) — invoice memakai nomor yang dipesan link ini.
    link_id: '' as number | '',
    // Pengajuan Budget yang sudah disetujui Finance — syarat menyimpan invoice beralokasi / berkoreksi.
    pengajuan_id: '' as number | '',
    ...(props.dariBerkas ? {
        supplier_id: props.dariBerkas.supplier_id ?? '',
        supplier_invoice_no: props.dariBerkas.supplier_invoice_no ?? '',
        description: props.dariBerkas.description,
        dpp: props.dariBerkas.dpp ?? props.dariBerkas.amount ?? 0,
        ...(props.dariBerkas.invoice_date ? { invoice_date: props.dariBerkas.invoice_date } : {}),
        ...(props.dariBerkas.ppn_mode ? { ppn_mode: props.dariBerkas.ppn_mode, ppn_pct: props.dariBerkas.ppn_pct ?? 11, ppn_incl: !!props.dariBerkas.ppn_incl } : {}),
    } : {}),
    ...(props.invoiceEdit?.data ?? {}),
    ...(pakaiDraft ? props.draftInvoice!.data : {}),
    ...(props.pengajuanBudget?.data ?? {}),
    // Draft yang sedang dibuka (Simpan Draft / Ajukan ke Dept Head menulis ke sini; dihapus saat invoice tersimpan).
    draft_id: (pakaiDraft ? props.draftInvoice!.id : null) ?? '',
});

/* PENERIMA — satu-satunya bagian yang berbeda antarmodul. Invoice Hutang memilih supplier,
   Reimburse memilih karyawan, Deposit memilih customer; server mencatat keduanya sebagai
   supplier berkategori khusus supaya Kuitansi & pembayaran menempuh jalur yang sama. */
const kolomPihak = props.jenis.kunci === 'reimburse' ? 'employee_id' : (props.jenis.kunci === 'deposit' ? 'customer_id' : 'supplier_id');
const daftarPihak = computed<any[]>(() => (props.jenis.kunci === 'reimburse' ? (props.employees ?? []) : (props.jenis.kunci === 'deposit' ? (props.customers ?? []) : props.suppliers)));
const rekeningLengkap = computed(() => !!form.bank_name && !!form.bank_account_no && !!form.bank_account_name);

// Pembulatan ke RUPIAH PENUH (round) untuk semua perhitungan; PPh Pasal 4(2) → rounddown (floor).
const r0 = (v: any) => Math.round(Number(v) || 0);
const fl0 = (v: any) => Math.floor(Number(v) || 0);
/**
 * Pembulatan PPN: desimal tepat 0,5 dibulatkan ke BAWAH, di atasnya ke atas.
 *
 * Math.round membulatkan 0,5 ke ATAS, sehingga PPN kerap satu rupiah lebih besar daripada
 * yang dihitung Accounting — dan selisih satu rupiah pada faktur pajak harus dijelaskan.
 * ceil(v − 0,5) memberi 550.000,50 → 550.000 dan 550.000,51 → 550.001. Nilainya tidak pernah
 * negatif di sini (tarif & objek PPN keduanya ≥ 0), jadi tidak perlu penanganan tanda.
 * RegisterApInvoice memakai rumus yang sama di server.
 */
const rpnn = (v: any) => Math.ceil((Number(v) || 0) - 0.5);
const dppR = computed(() => r0(form.dpp)); // Nilai Transaksi dibulatkan ke rupiah penuh

// #94: tarif PPh default per jenis (PPh 21 = 2,5% · PPh 23 = 2% · PPh Pasal 4(2) = 1,75%).
// Auto-isi saat jenis dipilih; field tarif (pph_pct) tetap bisa diubah manual oleh user.
const PPH_RATES: Record<string, number> = { pph21: 2.5, pph23: 2.0, pph4_2: 1.75 };
watch(() => form.pph_type, (t) => {
    // #94: jenis PPh → auto-isi tarif default (tetap bisa diubah manual di input tarif).
    if (t && PPH_RATES[t] !== undefined) form.pph_pct = PPH_RATES[t];
    // #113: jenis PPh → otomatis pilih akun PPh default (tetap bisa diubah manual lewat dropdown).
    // #113: jenis PPh → akun Hutang PPh. Kini SATU-SATUNYA jalan mengisinya — dropdown pemilih
    // akun sudah dihilangkan dari layar. Karena itu ia harus ikut DIKOSONGKAN saat jenisnya
    // dikosongkan: dulu nilai lamanya sengaja dibiarkan bertahan karena pengguna masih bisa
    // membetulkannya sendiri, sekarang tidak bisa lagi. Akun yang tertinggal tidak cocok tidak
    // sekadar salah muara — keterangan baris di buku besar dibaca dari NAMA AKUN (pasalPph),
    // sehingga bisa tertulis "PPh 21" padahal jenisnya PPh 23.
    //
    // Jenis yang akunnya belum ada di CoA juga dikosongkan, BUKAN dibiarkan memakai akun jenis
    // sebelumnya: baris Hutang PPh yang hilang dilaporkan akunKurang, sedangkan akun yang salah
    // tidak dilaporkan apa pun.
    form.pph_account_id = t ? (props.pphDefaults?.[t] ?? '') : '';
});
// #92/#PPh-acuan: saat masuk mode dipungut/ditanggung, default nilai objek PPh = ACUAN (Objek PPN bila "Dengan PPN", selain itu DPP).
watch(() => form.pph_mode, (m) => { if ((m === 'withheld' || m === 'borne') && !Number(form.pph_base)) form.pph_base = pphRefBase.value; });
// #140: saat masuk mode "Dengan PPN", default nilai objek PPN = DPP (bisa diubah).
watch(() => form.ppn_mode, (m) => { if (m === 'with') form.ppn_base = Number(form.dpp) || 0; });
// #140: saat DPP berubah & objek PPN masih = DPP lama (atau 0), ikutkan default ke DPP baru — kecuali user sudah override manual.
watch(() => form.dpp, (val, old) => {
    if (form.ppn_mode === 'with' && (!Number(form.ppn_base) || Number(form.ppn_base) === Number(old))) form.ppn_base = Number(val) || 0;
});
/*
 * TIDAK ADA LAGI KONVERSI SAAT TOGGLE Include/Exclude.
 *
 * Dulu sakelarnya menulis ulang isi kolom (÷ atau × (1+tarif)), dan karena kedua arah
 * membulatkan ke rupiah penuh, menekannya bolak-balik menggeser angkanya:
 * 5.000.000 → 4.504.505 → 5.000.001. Sekarang kolomnya tetap berisi apa yang diketik orang;
 * yang berbeda hanya ARTI angka itu — bruto bila Include, DPP bila Exclude — dan ringkasan
 * di bawahnya yang memecahnya. Tidak ada pembulatan bolak-balik, jadi tidak ada yang bergeser.
 */

const filteredSuppliers = computed(() => {
    const q = supplierSearch.value.toLowerCase();
    return q ? daftarPihak.value.filter((s: any) => (s.name + s.code).toLowerCase().includes(q)) : daftarPihak.value;
});
const supplier = computed(() => modeEdit
    ? { name: props.invoiceEdit?.pihak_nama }
    : daftarPihak.value.find((s: any) => s.id === form[kolomPihak]));
const category = computed(() => props.categories.find((c: any) => c.id === form.category_id));

/**
 * Pilihan Kategori Invoice untuk SearchSelect — bisa DIKETIK, bukan lagi digulir.
 *
 * Kategorinya ada puluhan dan namanya berawalan mirip ("BIAYA AIR KANTOR", "BIAYA INTERNET",
 * "BIAYA LISTRIK KANTOR", …), sehingga mencarinya dengan menggulir daftar berarti membaca
 * satu per satu — dan salah pilih kategori berarti invoice-nya mendarat di akun yang salah.
 *
 * `label` disusun di sini, bukan dibiarkan SearchSelect menyusunnya sendiri: penyaringnya
 * mencocokkan terhadap LABEL, jadi label "NAMA (KODE)" membuat pengetikan nama MAUPUN kode
 * sama-sama menemukan barisnya. Bentuk tulisannya pun tetap sama persis dengan yang dulu
 * tampil di <select>, supaya tidak ada yang merasa daftarnya berubah.
 */
const kategoriOpsi = computed(() => props.categories.map((c: any) => ({ ...c, label: `${c.name} (${c.code})` })));
// #140: PPN dihitung dari NILAI OBJEK PPN (default = DPP, clamp ≤ DPP), bukan dari seluruh DPP.
const ppnOn = computed(() => form.ppn_mode === 'with');
/**
 * INCLUDE PPN: ANGKA YANG DIKETIK ADALAH TOTAL, dan angka itulah yang dikunci.
 *
 * Dulu menekan Include mengubah isi kolomnya sendiri menjadi nilai net (5.000.000 → 4.504.505),
 * lalu PPN dihitung ulang dari net itu — dan hasil penjumlahannya meleset dari angka yang
 * tadi diketik: 4.504.505 + 495.496 = 5.000.001. Satu rupiah yang tidak pernah diketik siapa
 * pun ikut ke faktur, jurnal, dan memo.
 *
 * Melesetnya BUKAN kesalahan pembulatan. 5.000.000 memang tidak bisa dipecah menjadi DPP bulat
 * + PPN bulat pada tarif 11%: 5.000.000 ÷ 1,11 bukan bilangan bulat, dan tetangga terdekatnya
 * (4.504.505 + 495.495,55) pecahannya tidak genap. Diuji pada 7.276 nilai: pembulatan ke bawah
 * tepat 50,5% kali, 0,5-ke-bawah 90,1% — tidak ada aturan pembulatan yang benar selalu.
 *
 * Yang eksak hanya satu: PPN dihitung dari totalnya (× tarif/(100+tarif)), lalu DPP = total −
 * PPN. Penjumlahannya kembali ke angka semula menurut definisi, bukan menurut keberuntungan
 * pembulatan. Kolomnya pun tidak lagi berubah sendiri — yang diketik orang tetap terbaca.
 */
const inclOn = computed(() => ppnOn.value && form.ppn_incl === true);
/** Objek PPN apa adanya dari kolomnya: bruto bila Include, DPP bila Exclude. */
const objekIsi = computed(() => ppnOn.value ? Math.min(r0(form.ppn_base) || dppR.value, dppR.value) : 0);
/** Bagian di luar objek PPN — selalu diukur terhadap angka yang diketik. */
const ppnNonObject = computed(() => ppnOn.value ? Math.max(0, dppR.value - objekIsi.value) : 0);
/** Objek PPN dalam nilai NET — sama dengan isian bila Exclude, dikurangi PPN-nya bila Include. */
const ppnBase = computed(() => inclOn.value ? objekIsi.value - ppn.value : objekIsi.value);
// #incl-ppn: pembagian ÷(1+tarif) dilakukan LANGSUNG ke field DPP & objek PPN saat toggle Include (lihat watch),
// jadi kalkulasi di sini selalu pakai nilai field apa adanya (PPN = objek PPN × tarif). Dibulatkan ke rupiah penuh.
const ppn = computed(() => {
    if (! ppnOn.value) return 0;
    const pct = Number(form.ppn_pct) || 0;

    // Include → PPN diambil DARI DALAM totalnya (tarif/(100+tarif)); Exclude → ditambahkan di atas DPP.
    return inclOn.value
        ? rpnn(objekIsi.value * pct / (100 + pct))
        : rpnn(objekIsi.value * pct / 100);
});
/** DPP yang sebenarnya: Include → angka yang diketik dikurangi PPN-nya; Exclude → apa adanya. */
const dppNet = computed(() => inclOn.value ? dppR.value - ppn.value : dppR.value);
// #PPh-acuan: acuan objek PPh = OBJEK PPN (bila "Dengan PPN"), selain itu DPP.
const pphRefBase = computed(() => ppnOn.value ? ppnBase.value : dppNet.value);
// Objek PPh default mengikuti acuan (Objek PPN/DPP) selama belum di-override manual.
watch(pphRefBase, (val, old) => {
    if ((form.pph_mode === 'withheld' || form.pph_mode === 'borne') && (!Number(form.pph_base) || Number(form.pph_base) === Number(old))) {
        form.pph_base = Number(val) || 0;
    }
});
// #92: PPh dihitung dari NILAI OBJEK PPh (default = Objek PPN, clamp ≤ DPP), bukan dari total transaksi.
// PPh aktif untuk mode Dipungut (withheld) maupun Ditanggung (borne).
const pphOn = computed(() => form.pph_mode === 'withheld' || form.pph_mode === 'borne');
const pphBase = computed(() => pphOn.value ? Math.min(r0(form.pph_base) || pphRefBase.value, dppNet.value) : 0);
const pphNonObject = computed(() => Math.max(0, dppNet.value - pphBase.value));
// Nilai PPh terhitung (utk JURNAL). PPh Pasal 4(2) → rounddown (floor); jenis lain → round. Selaras backend.
const pphJournal = computed(() => {
    if (!pphOn.value) return 0;
    const raw = pphBase.value * (Number(form.pph_pct) || 0) / 100;
    return form.pph_type === 'pph4_2' ? fl0(raw) : r0(raw);
});
// TAMPILAN ringkasan: Dipotong = nilai PPh; Ditanggung (borne) → 0 (tak mengurangi pembayaran; supplier dibayar penuh).
// Jurnal Ditanggung tetap mencatat Dr Biaya PPh / Cr Hutang PPh (pakai pphJournal).
const pph = computed(() => (form.pph_mode === 'borne' ? 0 : pphJournal.value));
const PPH_LABELS: Record<string, string> = { pph21: 'PPh 21', pph23: 'PPh 23', pph4_2: 'PPh Pasal 4(2)' };

/**
 * Uraian tagihan yang ikut ditempelkan ke keterangan baris PPN & PPh.
 *
 * Diambil dari keterangan invoice; bila kosong, dari baris Detil Tagihan pertama. Tanpa ini,
 * baris PPN dan PPh di General Ledger hanya berbunyi "PPN Masukan" dan "PPh dipotong" —
 * benar tapi tidak memberi tahu tagihan mana, sehingga menelusurinya harus lewat nomor jurnal.
 */
const uraianTagihan = computed(() => {
    const d = String(form.description ?? '').trim();
    return d || String(form.lines?.[0]?.description ?? '').trim();
});
const denganUraian = (teks: string) => (uraianTagihan.value ? teks + ' — ' + uraianTagihan.value : teks);

/**
 * Pasal PPh dibaca dari NAMA AKUN yang dipilih, bukan dari jenis PPh di atasnya.
 *
 * Akunlah yang menentukan ke mana angkanya bermuara — "HUTANG PPH PASAL 23" adalah utang
 * PPh 23 apa pun yang tertulis di kolom jenis. Kalau keduanya berbeda, keterangan yang
 * mengikuti akun-lah yang cocok dengan buku besarnya. Jenis PPh tetap dipakai sebagai
 * cadangan bila nama akunnya tidak menyebut pasal apa pun.
 */
function pasalPph(accountId: any): string {
    const a = (props.pphAccounts ?? []).find((x: any) => String(x.id) === String(accountId));
    const n = String(a?.name ?? '').toUpperCase();
    // 4 ayat 2 diperiksa DULU: namanya memuat angka 4 dan 2, yang bisa tertangkap pola lain.
    if (/PASAL\s*4|4\s*\(?\s*2|4\s*AYAT/.test(n)) return 'PPh Pasal 4(2)';
    if (/\b21\b/.test(n)) return 'PPh Pasal 21';
    if (/\b23\b/.test(n)) return 'PPh Pasal 23';
    return PPH_LABELS[form.pph_type] || 'PPh';
}
/**
 * Label kotak ringkasan SELALU "PPh dipotong" — permintaan Accounting, 12 Okt 2026.
 *
 * Kotak itu menjawab satu hal: berapa yang DIPOTONG dari pembayaran ke supplier. Pada mode
 * Ditanggung jawabannya nol — perusahaan menanggungnya sendiri, supplier tetap dibayar penuh —
 * jadi "PPh dipotong Rp 0" memang keadaan yang sebenarnya. Dua nama untuk satu kotak justru
 * membuat orang mengira ada dua angka yang berbeda.
 *
 * Yang DITANGGUNG tetap tercatat utuh di jurnalnya (Dr Biaya PPh / Cr Hutang PPh) — lihat
 * pphJournal; yang berubah hanya tulisan pada ringkasan ini.
 */
const pphLabel = computed(() => 'PPh dipotong');
// Include → total PERSIS angka yang diketik. Exclude → DPP + PPN, seperti sebelumnya.
const total = computed(() => dppNet.value + ppn.value);
// Dipotong → PPh dipotong dari pembayaran supplier (net = total − PPh).
// Ditanggung → supplier dibayar PENUH (net = total); PPh jadi beban perusahaan.
const net = computed(() => form.pph_mode === 'borne' ? total.value : total.value - pph.value);
// Tgl. Jatuh Tempo = Tgl. Terima (receive_date) + Jatuh Tempo (hari); fallback ke Tgl. Input bila Tgl. Terima kosong.
const dueDate = computed(() => {
    const days = Number(form.term_days) || 0;
    const base = form.receive_date || form.invoice_date;
    // Memo Urgent: jatuh tempo hari itu juga (0 hari).
    if (!base || days < 0 || (days === 0 && !form.memo_internal_id)) return '';
    return new Date(new Date(base).getTime() + days * 86400000).toISOString().slice(0, 10);
});
// Sinkron ke form.due_date agar nilai yang tersimpan = tampilan kotak Tgl. Jatuh Tempo.
watch(dueDate, (v) => { form.due_date = v; }, { immediate: true });
const linesSum = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));
// Detil Tagihan wajib sama dengan DPP (net, sesudah PPN include dikeluarkan) bila barisnya diisi.
const barisTakSamaDpp = computed(() => form.lines.length > 0 && Math.abs(linesSum.value - dppNet.value) >= 1);
const schedSum = computed(() => form.schedules.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));
const jurnalDebit = computed(() => form.journal_lines.reduce((s: number, l: any) => s + (Number(l.debit) || 0), 0));
const jurnalKredit = computed(() => form.journal_lines.reduce((s: number, l: any) => s + (Number(l.credit) || 0), 0));
const jurnalNilai = computed(() => form.journal_lines.reduce((s: number, l: any) => s + (Number(l.nilai) || 0), 0));
const jurnalBalanced = computed(() => Math.abs(jurnalDebit.value - jurnalKredit.value) < 1 && jurnalDebit.value > 0);
const activeJournalLines = computed(() => form.journal_lines.filter((l: any) => l.account_id && ((Number(l.debit) || 0) > 0 || (Number(l.credit) || 0) > 0)));

/* ── Cost center wajib ──────────────────────────────────────────────────────
   Baris jurnal tanpa cost center tidak terhitung di Budget Control maupun laporan per cost
   center, dan itu tidak muncul sebagai galat apa pun — hanya angka yang kurang, berbulan
   kemudian. Karena itu diwajibkan di sini.

   KECUALI akun bertanda cost_center_optional (CoaTanpaCostCenterSeeder): Utang Usaha
   Supplier dan sebagian akun neraca lain memang tidak menanggung cost center. Mewajibkannya
   untuk SEMUA baris akan mengunci wizard ini pada invoice yang justru paling biasa —
   Dr beban (ber-cost center) / Cr Utang Usaha (tanpa). Aturannya dibuat sama dengan Jurnal
   Memorial dan dengan rambu server, bukan aturan ketiga yang berdiri sendiri. */
function ccOptional(accountId: any) {
    const acc = props.accounts.find((a: any) => a.id === Number(accountId));
    if (!acc) return false;
    // DUA sumber, keduanya berarti "tidak dibebankan per cost center":
    //   1. penanda cost_center_optional di COA;
    //   2. akun yang memang TIDAK DIANGGARKAN (daftar Accounting).
    // PPN Masukan hanya masuk yang kedua — tanpa ini cost center-nya ikut terisi dan tiap
    // invoice ber-PPN tertahan gerbang anggaran.
    if (acc.cost_center_optional) return true;
    return (props.coaTanpaAnggaran ?? []).includes(String(acc.code));
}
/** Baris ini kurang cost center? Baris tanpa akun tidak dihitung — ia memang boleh kosong. */
function ccKurang(l: any) {
    return !!l?.account_id && ! l?.cost_center_id && ! ccOptional(l.account_id);
}
/** Label akun yang masih kurang cost center — dipakai pesan penahan tombol Berikutnya. */
const kurangCc = computed<string[]>(() => {
    const out = new Set<string>();
    for (const l of form.journal_lines as any[]) {
        if (ccKurang(l)) out.add(accountLabel(l.account_id));
    }

    return [...out];
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtNum(v: number) { return Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
/* Langkah 1 berisi dua baris terlipat — Memo Urgent & penerima. Klik baris untuk membukanya;
   hanya satu yang terbuka sekaligus supaya jelas mana yang sedang dipilih. */
const bukaLangkah1 = ref<'memo' | 'pihak' | null>(null);
const bukaTutup = (k: 'memo' | 'pihak') => { bukaLangkah1.value = bukaLangkah1.value === k ? null : k; };
function pickSupplier(s: any) {
    form[kolomPihak] = s.id;
    if (props.jenis.kunci !== 'deposit') bukaLangkah1.value = null;
    // Deposit: rekening customer diketik dulu di langkah ini (master Customer tak menyimpan bank).
    if (props.jenis.kunci === 'deposit') {
        if (! form.bank_account_name) form.bank_account_name = s.name;
        return;
    }
    step.value = 2;
}
function addLine() { form.lines.push({ description: form.description || '', qty: 1, unit_price: 0, amount: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
function addSchedule() { form.schedules.push({ due_date: dueDate.value, amount: 0 }); }
function removeSchedule(i: number) { form.schedules.splice(i, 1); }

function accountLabel(id: number) { const a = props.accounts.find((x: any) => x.id === id); return a ? `${a.code} — ${a.name}` : '—'; }
function accountName(id: number | null) { const a = props.accounts.find((x: any) => x.id === id); return a ? a.name : ''; }
function accountByCode(code: string) { return props.accounts.find((x: any) => String(x.code) === code); }

// ── Salin / Tempel akun antar baris jurnal ────────────────────────────────
// Akun sering berulang di beberapa baris. Tombol kecil di kolom Account menyalin
// "KODE - NAMA" lalu menempelkannya ke baris lain tanpa perlu mencari ulang di dropdown.
// Papan klip internal (copiedAccountId) dipakai lebih dulu; papan klip sistem menjadi
// cadangan agar akun juga bisa ditempel dari/ke halaman & aplikasi lain.
const copiedAccountId = ref<number | null>(null);
const copiedAccountLabel = ref('');
const copyFlash = ref<number | null>(null);   // indeks baris yang baru disalin (umpan balik singkat)
const pasteFlash = ref<number | null>(null);

async function copyAccount(l: any, i: number) {
    if (!l.account_id) return;
    copiedAccountId.value = l.account_id;
    copiedAccountLabel.value = accountLabel(l.account_id);
    // Papan klip sistem bersifat "usaha terbaik" — bisa ditolak browser tanpa HTTPS/izin.
    try { await navigator.clipboard?.writeText(copiedAccountLabel.value); } catch { /* abaikan */ }
    copyFlash.value = i;
    setTimeout(() => { if (copyFlash.value === i) copyFlash.value = null; }, 1200);
}

async function pasteAccount(l: any, i: number) {
    let id = copiedAccountId.value;
    if (!id) {
        // Belum ada yang disalin di halaman ini → coba baca papan klip sistem,
        // cocokkan dengan KODE akun di awal teks ("10201010102 - BANGUNAN").
        try {
            const txt = (await navigator.clipboard?.readText())?.trim() ?? '';
            const code = (txt.match(/^\s*([0-9]{3,})/) || [])[1];
            const acc = code ? accountByCode(code) : undefined;
            if (acc) { id = acc.id; copiedAccountId.value = acc.id; copiedAccountLabel.value = accountLabel(acc.id); }
        } catch { /* abaikan */ }
    }
    if (!id) return;
    pilihAkun(l, id);   // cost center ikut akun yang ditempel
    pasteFlash.value = i;
    setTimeout(() => { if (pasteFlash.value === i) pasteFlash.value = null; }, 1200);
}
function costCenterLabel(id: number | null) { if (!id) return ''; const c = props.costCenters.find((x: any) => x.id === id); return c ? c.code : ''; }
function dimSummary(l: any) {
    const parts = [costCenterLabel(l.cost_center_id)].filter(Boolean);
    if (l.currency && l.currency !== 'IDR') parts.push(`${l.currency}@${l.kurs}`);
    return parts.join(' · ');
}
// Cost center tiap baris MENGIKUTI UNIT KERJA PENGAJU. Dulu null, sehingga harus dipilih
// tangan tiap baris — dan salah pilih membebankan biaya ke departemen lain tanpa galat.
//
// KECUALI akun yang memang boleh tanpa cost center (Utang Usaha, Kas, PPN). Mengisinya di
// sana bukan cuma mubazir: baris itu lalu ikut masuk gerbang anggaran, dan setiap invoice
// ber-PPN akan ditolak karena PPN Masukan memang tidak pernah dianggarkan.
// currency & kurs TETAP dikirim (IDR, 1) walau isiannya dihapus dari layar: kolomnya ada di
// bs_journal_lines dan dibaca laporan. Yang dibuang hanya isiannya — seluruh transaksi di
// sini rupiah, dan pilihan yang tak pernah diubah hanya menambah kolom yang harus dilewati.
function dimDefaults(accountId?: any) {
    const cc = accountId && ccOptional(accountId) ? null : ccBawaan(accountId);
    return { cost_center_id: cc, currency: 'IDR', kurs: 1 };
}
// Satu baris jurnal ala eProperty: input satu "Nilai Transaksi" + pilih sisi (Debit/Kredit) lewat radio.
// debit/credit tetap dijaga sinkron untuk submit ke backend & perhitungan balance.
function mkJournalLine(account_id: number | null, description: string, amount: number, side: 'debit' | 'credit') {
    return { ...barisStruktur(account_id, description, amount, side), ...dimDefaults(account_id) };
}
function applyAmount(l: any) {
    const v = Number(l.nilai) || 0;
    if (l.side === 'credit') { l.credit = v; l.debit = 0; }
    else { l.side = 'debit'; l.debit = v; l.credit = 0; }
}
function setSide(l: any, side: 'debit' | 'credit') { l.side = side; applyAmount(l); }
/**
 * Akun BEBAN untuk PPh ditanggung — mengikuti JENIS PPh yang dipilih.
 *
 * Dr Biaya PPh 21 (70101010109) · PPh 23 (70101010107) · PPh Pasal 4(2) (70101010108),
 * jadi satu jenis PPh menentukan sepasang akun sekaligus bersama Hutang PPh-nya. Sebelumnya
 * ketiganya bermuara ke satu akun beban umum, sehingga beban PPh 21, 23, dan 4(2) tidak bisa
 * dipisahkan lagi di buku besar maupun SPT.
 *
 * Cadangan dipakai bila jenis PPh belum dipilih padahal tarifnya sudah diisi manual — keadaan
 * yang masih mungkin karena jenis PPh tidak diwajibkan gerbang mana pun. Tanpa cadangan itu
 * baris Biaya PPh tidak terbentuk dan jurnalnya tidak balance.
 *
 * Pemetaannya HARUS sama dengan yang dipakai RegisterApInvoice pada jalur otomatis — keduanya
 * membaca config gl_accounts.pph_borne_expense yang sama. Kalau berbeda, invoice yang sama akan
 * berjurnal berbeda tergantung dari pintu mana ia masuk, dan dua-duanya tetap balance.
 */
const akunBiayaPph = computed<number | null>(
    () => akunBiayaPphDitanggung(form.pph_type, props.pphBorneDefaults, props.pphBorneExpense?.id),
);

/** Bahan struktur bawaan — aturannya sendiri ada di ./strukturJurnal.ts supaya bisa diuji. */
const bahanStruktur = computed(() => ({
    dpp: dppNet.value,
    ppn: ppn.value,
    pph: pphJournal.value,
    ppnAktif: ppnOn.value,
    pphMode: (form.pph_mode as 'none' | 'withheld' | 'borne'),
    akun: {
        beban: category.value?.debit_id ?? null,
        hutangUsaha: category.value?.payable_id ?? null,
        ppnMasukan: props.ppnAccount?.id ?? null,
        hutangPph: form.pph_account_id ? Number(form.pph_account_id) : null,
        biayaPph: akunBiayaPph.value,
    },
    uraian: {
        beban: form.description || '',
        ppn: denganUraian('PPN Masukan'),
        hutangUsaha: 'Hutang ' + (supplier.value?.name || 'supplier'),
        pphDebit: denganUraian('Biaya ' + pasalPph(form.pph_account_id) + ' ditanggung'),
        pphKredit: denganUraian(form.pph_mode === 'borne'
            ? 'Hutang ' + pasalPph(form.pph_account_id) + ' ditanggung'
            : pasalPph(form.pph_account_id) + ' dipotong'),
    },
}));
function defaultJournal() {
    return bangunStrukturJurnal(bahanStruktur.value).map((b: any) => ({ ...b, ...dimDefaults(b.account_id) }));
}
/** Akun yang dibutuhkan aturan PPN/PPh tetapi belum tersedia — disebutkan, bukan didiamkan. */
const akunStrukturKurang = computed(() => akunKurang(bahanStruktur.value));

/* Struktur bawaan yang TERAKHIR dibentuk — pembanding untuk tahu apakah yang di layar masih asli
   atau sudah disunting tangan. */
let strukturBawaanTerakhir: any[] = [];
function pasangStruktur(baris: any[]) {
    strukturBawaanTerakhir = baris.map((b) => ({ ...b }));
    form.journal_lines = baris;
}

/* COST CENTER IKUT AKUN YANG DIPILIH — dulu cost center hanya ditentukan saat baris DIBUAT
   (sebelum COA-nya diketahui), sehingga "+ Baris → pilih Utang Usaha Customer" tetap membawa
   cost center user. Kini disetel ulang tiap kali COA dipilih/diganti:
     akun "dikosongkan" (Setup → Akun) → kosong · akun bercost center bawaan → itu ·
     selain itu → unit kerja user bila masih kosong. Cost center yang diisi tangan pada akun
     biasa tidak ditimpa. */
function pilihAkun(l: any, v: any) {
    l.account_id = v;
    const acc: any = props.accounts.find((a: any) => a.id === Number(v));
    if (!v || !acc) return;
    if (ccOptional(v)) { l.cost_center_id = null; return; }
    // COA berdaftar cost center → unit kerja user bila ada di daftar, selain itu default COA.
    if ((acc.cc_ids ?? []).length) { l.cost_center_id = ccBawaan(v); return; }
    if (!l.cost_center_id) l.cost_center_id = props.dariBerkas?.cost_center_id ?? props.ccPengguna ?? null;
}
/** Cost center bawaan baris (CostCenterAkun) — unit kerja user dicocokkan dengan daftar COA. */
function ccBawaan(accountId: any): number | null {
    const milik = props.ccUser ?? (props.ccPengguna ? [props.ccPengguna] : []);
    return pilihCc(props.accounts.find((a: any) => a.id === Number(accountId)), milik);
}
/** Pilihan cost center baris: dibatasi daftar COA bila ada. */
function ccOpsiBaris(accountId: any) {
    return opsiCc(props.accounts.find((a: any) => a.id === Number(accountId)), props.costCenters);
}
function addJournalLine() { form.journal_lines.push(mkJournalLine(null, '', 0, 'debit')); }
function addJournalLines(n: number) { for (let k = 0; k < n; k++) addJournalLine(); }
function removeJournalLine(i: number) { form.journal_lines.splice(i, 1); }

/* STRUKTURNYA IKUT BERUBAH SAAT PPN/PPh DIUBAH.
   Sebelumnya struktur hanya dibentuk SEKALI, yaitu saat pertama kali masuk langkah Struktur
   ketika daftarnya masih kosong. Siapa pun yang kembali ke Input Transaksi untuk menyalakan PPN
   lalu maju lagi akan menemukan struktur lamanya utuh — tanpa baris PPN Masukan, dan Hutang
   Usaha masih sebesar DPP saja. Jurnalnya tetap balance, jadi tidak ada satu pun galat yang
   memberi tahu; yang ketahuan belakangan hanya PPN Masukan yang tidak pernah tercatat.

   Yang sudah DISUNTING TANGAN tidak ditimpa — pekerjaan orang tidak boleh lenyap hanya karena ia
   kembali membetulkan satu angka. Selisihnya diberitahukan lewat strukturUsang.

   Dulu ada tombol "Hitung ulang" untuk menyusun ulang struktur yang terlanjur disunting; tombol
   itu dihilangkan atas permintaan. Jalan menyusun ulang tetap ada dan memakai watcher di bawah:
   hapus seluruh barisnya, lalu Sebelumnya → Berikutnya. Saat masuk langkah 4 dengan daftar kosong,
   watch(step) membentuknya kembali dari nilai transaksi. Kedua pesan di layar (peringatan
   strukturUsang & baris "belum ada baris") menyebutkan langkah itu — kalau tidak, keduanya
   menyuruh menekan tombol yang sudah tidak ada. */
const strukturUsang = computed(() => step.value === 4
    && form.journal_lines.length > 0
    && ! samaDenganBawaan(form.journal_lines as any[], defaultJournal()));

watch(bahanStruktur, () => {
    if (form.journal_lines.length === 0) return;                       // belum pernah dibentuk
    if (! samaDenganBawaan(form.journal_lines as any[], strukturBawaanTerakhir)) return; // sudah disunting
    pasangStruktur(defaultJournal());
}, { deep: true });

/**
 * Nilai Transaksi saat baris Detil Tagihan dibentuk/diselaraskan terakhir kali.
 *
 * Dipakai membedakan baris yang MASIH bawaan dari baris yang sudah disentuh orang. Tanpa
 * penanda ini, menyelaraskan baris ke DPP yang baru berarti menimpa rincian yang diketik
 * sendiri — dan rincian tagihan adalah satu-satunya tempat uraian per barang tercatat.
 */
const dppBaris = ref(0);

/**
 * Selaraskan Detil Tagihan dengan Nilai Transaksi.
 *
 * Dulu baris hanya dibentuk SEKALI, saat Detil Tagihan pertama dibuka dalam keadaan kosong.
 * Kalau orang kembali ke Input Transaksi dan mengubah nilainya, barisnya tetap memakai angka
 * lama: layarnya menampilkan "Total baris Rp 2.702.703 · DPP Rp 3.000.000" dan menyuruh orang
 * membetulkan sendiri angka yang sebetulnya memang diturunkan dari nilai transaksi.
 *
 * Yang diselaraskan HANYA baris bawaan — satu baris yang nominalnya masih sama persis dengan
 * DPP sewaktu ia dibentuk. Begitu orang memecahnya jadi beberapa baris atau mengubah
 * nominalnya, barisnya miliknya: selisihnya tetap ditampilkan sebagai peringatan, tetapi
 * isinya tidak disentuh.
 */
function selaraskanBaris() {
    const dpp = dppNet.value;

    if (form.lines.length === 0) {
        form.lines.push({ description: form.description || '', qty: 1, unit_price: dpp, amount: dpp });
        dppBaris.value = dpp;

        return;
    }

    const satuBarisBawaan = form.lines.length === 1
        && Number(form.lines[0].qty) === 1
        && Number(form.lines[0].amount) === dppBaris.value;

    if (! satuBarisBawaan || dpp === dppBaris.value) {
        return;
    }

    form.lines[0].unit_price = dpp;
    form.lines[0].amount = dpp;
    dppBaris.value = dpp;
}

// Nilai transaksi berubah selagi Detil Tagihan sudah terbentuk → baris bawaannya ikut.
watch(dppNet, () => { if (form.lines.length > 0) selaraskanBaris(); });

// Saat masuk Detil Tagihan, baris bawaan dibentuk/diselaraskan dengan Nilai Transaksi.
// Saat masuk Struktur, default 1 termin = net jika kosong.
watch(step, (s) => {
    if (s === 3) selaraskanBaris();
    if (s === 4) {
        if (form.journal_lines.length === 0) pasangStruktur(defaultJournal());
        // Jadwal pembayaran dihilangkan dari registrasi — backend membuat default 1 termin = net otomatis.
    }
});

/* ── BERKAS (langkah Input Transaksi) ──
   Registrasi baru Invoice Hutang wajib punya satu berkas: diunggah di sini (Upload File) atau dari supplier (Generate Link).
   Server memeriksa ulang (milik user, sudah di-TTD Dept Head) saat invoice disimpan. */
const wajibBerkas = Array.isArray(props.berkasSaya);
const berkasDipilih = computed<BerkasPilihan | null>(() => props.berkasSaya?.find((b) => b.id === Number(form.berkas_upload_id)) ?? null);
// Berkas di draft yang sudah tidak bisa dipilih (mis. sudah diinvoice) → dikosongkan.
if (wajibBerkas && form.berkas_upload_id && !berkasDipilih.value) form.berkas_upload_id = '';
const supplierBeda = computed(() => !!berkasDipilih.value?.supplier_id && !!form.supplier_id && berkasDipilih.value.supplier_id !== Number(form.supplier_id));
/** Invoice baru bisa disimpan bila berkasnya sudah di-TTD Dept Head. */
const berkasBelumSiap = computed(() => wajibBerkas && !!berkasDipilih.value && !berkasDipilih.value.bisa_diinvoice);

/* Dua jalan berkas di langkah Input Transaksi:
   - Upload File  : unggah di sini → jadi Upload Berkas Anda + langsung diajukan ke TTD Dept Head.
   - Generate Link: link tanpa login untuk supplier. Nomor invoice dipesan & isian wizard disimpan di
     link; tidak bisa lanjut sampai supplier mengunggah. Begitu masuk: notifikasi + titik di daftar. */
type LinkInfo = { id: number; url: string; invoice_number: string; status: string; filled_at: string | null; berkas_upload_id: number | null; berkas_number?: string | null;
    supplier_invoice_no?: string | null; riwayat?: Array<{ at: string; berkas_number: string; no_invoice: string | null; file: number }>; menerima?: boolean };
const linkAktif = ref<LinkInfo | null>(props.linkBerkas ?? null);
const modeBerkas = ref<'upload' | 'link'>(linkAktif.value || form.link_id ? 'link' : 'upload');
const pesanLink = ref('');
const galatLink = ref('');
const memprosesLink = ref(false);
async function buatLink() {
    memprosesLink.value = true; galatLink.value = '';
    try {
        const { data } = await (window as any).axios.post(route('bs.ap.berkas-link.store'), { data: form.data(), step: step.value });
        linkAktif.value = data;
        form.link_id = data.id;
        form.berkas_upload_id = '';
        pesanLink.value = `Link dibuat — nomor invoice ${data.invoice_number} dipesan dan isian ini tersimpan sebagai draft.`;
    } catch (e: any) {
        galatLink.value = e?.response?.data?.message ?? 'Link gagal dibuat — coba lagi.';
    } finally { memprosesLink.value = false; }
}
async function cekLink() {
    if (!linkAktif.value) return;
    memprosesLink.value = true; galatLink.value = '';
    try {
        const { data } = await (window as any).axios.get(route('bs.ap.berkas-link.status', linkAktif.value.id));
        const baruTerisi = data.status === 'terisi' && linkAktif.value.status !== 'terisi';
        linkAktif.value = data;
        // Nomor invoice yang diketik supplier di halaman link = No. Invoice Supplier (dikunci).
        if (data.supplier_invoice_no) form.supplier_invoice_no = data.supplier_invoice_no;
        // Unggahan (ulang) supplier = berkas baru → pilih berkas terbarunya.
        if (data.berkas_upload_id && (baruTerisi || Number(form.berkas_upload_id) !== Number(data.berkas_upload_id) || !berkasDipilih.value)) {
            router.reload({ only: ['berkasSaya'], onSuccess: () => { form.berkas_upload_id = data.berkas_upload_id; } });
        }
    } catch { galatLink.value = 'Status link gagal dimuat.'; } finally { memprosesLink.value = false; }
}
async function batalLink() {
    if (!linkAktif.value || !confirm(`Batalkan link ini? Nomor ${linkAktif.value.invoice_number} hangus dan supplier tidak bisa mengunggah lagi.`)) return;
    await (window as any).axios.post(route('bs.ap.berkas-link.batal', linkAktif.value.id));
    linkAktif.value = null; form.link_id = ''; form.berkas_upload_id = ''; pesanLink.value = '';
}
const tersalin = ref(false);
function salinLink() {
    if (!linkAktif.value) return;
    navigator.clipboard?.writeText(linkAktif.value.url);
    tersalin.value = true; setTimeout(() => { tersalin.value = false; }, 1500);
}
/* Share: lembar bagikan bawaan perangkat (HP / Safari / Chrome Mac & Windows); bila tidak didukung,
   menu WhatsApp / Email / salin pesan. Pesannya sudah berisi nomor invoice + link. */
const menuBagikan = ref(false);
const pesanBagikan = computed(() => linkAktif.value
    ? `Yth. ${supplier.value?.name ?? 'Bapak/Ibu'}, mohon isi nomor invoice dan unggah dokumen tagihan Anda (invoice, kuitansi, faktur pajak) melalui link berikut:\n${linkAktif.value.url}\nTerima kasih.`
    : '');
async function bagikanLink() {
    if (!linkAktif.value) return;
    if (typeof navigator.share === 'function') {
        try {
            await navigator.share({ title: 'Unggah dokumen tagihan ' + linkAktif.value.invoice_number, text: pesanBagikan.value });
            return;
        } catch (e: any) {
            if (e?.name === 'AbortError') return; // dibatalkan pengguna
        }
    }
    menuBagikan.value = !menuBagikan.value;
}
const urlWhatsApp = computed(() => 'https://wa.me/?text=' + encodeURIComponent(pesanBagikan.value));
const urlEmail = computed(() => 'mailto:?subject=' + encodeURIComponent('Unggah dokumen tagihan ' + (linkAktif.value?.invoice_number ?? '')) + '&body=' + encodeURIComponent(pesanBagikan.value));
function salinPesan() {
    navigator.clipboard?.writeText(pesanBagikan.value);
    menuBagikan.value = false;
    tersalin.value = true; setTimeout(() => { tersalin.value = false; }, 1500);
}
// Selagi menunggu supplier & berada di langkah Input Transaksi (blok Berkas), status dicek berkala.
let pantau: ReturnType<typeof setInterval> | null = null;
watch([step, () => linkAktif.value?.status], ([s, st]) => {
    if (pantau) { clearInterval(pantau); pantau = null; }
    if (s === 2 && st === 'menunggu') pantau = setInterval(cekLink, 15000);
}, { immediate: true });
onBeforeUnmount(() => { if (pantau) clearInterval(pantau); });
// Dibuka dari link yang sudah terisi → berkasnya langsung terpilih.
if (linkAktif.value?.berkas_upload_id && !form.berkas_upload_id) form.berkas_upload_id = linkAktif.value.berkas_upload_id;

// Drop box: file + jenis (Invoice/Kuitansi/Faktur Pajak/Lainnya); "Lainnya" wajib keterangan; jenis wajib harus ada.
const unggahan = ref<FileBerkas[]>([]);
const mengunggah = ref(false);
const galatUnggah = ref('');
const idLainnyaBerkas = computed(() => (props.jenisBerkas ?? []).find((j) => j.code === 'LAINNYA')?.id ?? null);
/* Dibuka dari tombol kecil Upload file di daftar AP (?mulai=berkas): langsung ke langkah 2, mode
   Upload File, file yang dipilih sudah masuk drop box (jenis ditebak dari nama file). */
const mulaiBerkas = wajibBerkas && !modeEdit && !props.pengajuanBudget && !linkAktif.value
    && new URLSearchParams(window.location.search).get('mulai') === 'berkas';
if (mulaiBerkas) {
    step.value = 2;
    modeBerkas.value = 'upload';
    const MAKS = 10 * 1024 * 1024;
    unggahan.value = berkasTitipan.value.filter((f) => /\.(pdf|jpe?g|png)$/i.test(f.name) && f.size <= MAKS).slice(0, 10)
        .map((f) => ({ file: f, berkas_id: tebakJenis(f.name, props.jenisBerkas ?? []), keterangan: '' }));
    berkasTitipan.value = [];
    onMounted(() => nextTick(() => document.getElementById('blok-berkas')?.scrollIntoView({ behavior: 'smooth', block: 'start' })));
}
const kurangWajib = computed(() => (props.jenisBerkas ?? []).filter((j) => j.is_mandatory && !unggahan.value.some((u) => u.berkas_id === j.id)).map((j) => j.name));
/* No. Invoice Supplier (registrasi Invoice Hutang): terkunci sampai ada berkasnya — Upload File: setelah
   file di-drop; Generate Link: diisi supplier sendiri di halaman link dan tetap terkunci (nomor aktif). */
const noInvoiceDariLink = computed(() => modeBerkas.value === 'link' && !!linkAktif.value);
const kunciNoInvoice = computed(() => wajibBerkas && (noInvoiceDariLink.value || (!berkasDipilih.value && unggahan.value.length === 0)));
const ketNoInvoice = computed(() => !kunciNoInvoice.value ? ''
    : noInvoiceDariLink.value ? (linkAktif.value?.status === 'terisi' ? 'Diisi supplier lewat link — nomor aktif invoice ini.' : 'Akan diisi supplier di halaman link.')
    : 'Drop file tagihan di Berkas Tagihan (bawah) dulu, baru bisa diisi.');
const bisaUnggah = computed(() => unggahan.value.length > 0 && !mengunggah.value && !!form[kolomPihak] && !kurangWajib.value.length
    && unggahan.value.every((u) => u.berkas_id && (u.berkas_id !== idLainnyaBerkas.value || u.keterangan.trim())));
async function unggahBerkas() {
    mengunggah.value = true; galatUnggah.value = '';
    const fd = new FormData();
    fd.append('data', JSON.stringify(form.data()));
    unggahan.value.forEach((u, i) => {
        fd.append(`files[${i}][file]`, u.file); fd.append(`files[${i}][berkas_id]`, String(u.berkas_id)); fd.append(`files[${i}][keterangan]`, u.keterangan);
    });
    try {
        const { data } = await (window as any).axios.post(route('bs.ap.berkas-langsung.upload'), fd);
        router.reload({ only: ['berkasSaya'], onSuccess: () => { form.berkas_upload_id = data.id; } });
        unggahan.value = [];
    } catch (e: any) {
        const r = e?.response?.data;
        galatUnggah.value = r?.message && !r?.errors ? r.message : (r?.errors ? String(Object.values(r.errors)[0]) : 'Upload gagal — coba lagi.');
    } finally { mengunggah.value = false; }
}

// Tambah file sesudah TTD (link nonaktif) — masuk ke berkas yang sama.
const tambahan = ref<FileBerkas[]>([]);
const menambah = ref(false);
const galatTambah = ref('');
const bisaTambah = computed(() => tambahan.value.length > 0 && !menambah.value
    && tambahan.value.every((u) => u.berkas_id && (u.berkas_id !== idLainnyaBerkas.value || u.keterangan.trim())));
async function tambahFile() {
    if (!linkAktif.value) return;
    menambah.value = true; galatTambah.value = '';
    const fd = new FormData();
    tambahan.value.forEach((u, i) => {
        fd.append(`files[${i}][file]`, u.file); fd.append(`files[${i}][berkas_id]`, String(u.berkas_id)); fd.append(`files[${i}][keterangan]`, u.keterangan);
    });
    try {
        await (window as any).axios.post(route('bs.ap.berkas-link.file', linkAktif.value.id), fd);
        tambahan.value = [];
        router.reload({ only: ['berkasSaya'] });
    } catch (e: any) {
        const r = e?.response?.data;
        galatTambah.value = r?.message && !r?.errors ? r.message : (r?.errors ? String(Object.values(r.errors)[0]) : 'Gagal menambah file — coba lagi.');
    } finally { menambah.value = false; }
}

// "Lihat semua": semua file berkas → satu PDF (sama dengan halaman Upload Berkas).
const semua = ref<{ url: string; nama: string } | null>(null);
const menggabung = ref(false);
const galatGabung = ref('');
async function lihatSemua() {
    const b = berkasDipilih.value;
    if (!b) return;
    menggabung.value = true;
    galatGabung.value = '';
    try {
        semua.value = await gabungBerkas(b);
    } catch {
        galatGabung.value = 'Gagal menggabungkan file — coba buka satu per satu.';
    } finally {
        menggabung.value = false;
    }
}
function tutupSemua() {
    if (semua.value) URL.revokeObjectURL(semua.value.url);
    semua.value = null;
}
onBeforeUnmount(tutupSemua);

/* DRAFT. "Simpan Draft" menyimpan isian wizard; dipulihkan saat Registrasi Invoice Hutang dibuka lagi.
   Tombolnya redup selama tidak ada perubahan sejak terakhir tersimpan; sesudah disimpan ada tanda "Tersimpan". */
const menyimpanDraft = ref(false);
const jepret = () => JSON.stringify({ ...form.data(), draft_id: undefined });
const tersimpanIsi = ref(pakaiDraft || props.linkBerkas ? jepret() : '');
const tersimpanPada = ref<string | null>(props.draftInvoice?.updated_at ?? null);
const draftBerubah = computed(() => jepret() !== tersimpanIsi.value);
const jamTersimpan = computed(() => tersimpanPada.value
    ? new Date(tersimpanPada.value).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const galatDraft = ref('');
function tandaiTersimpan(at?: string | null) { tersimpanIsi.value = jepret(); tersimpanPada.value = at ?? new Date().toISOString(); }
async function simpanDraft(diam = false) {
    if (menyimpanDraft.value) return;
    menyimpanDraft.value = true; galatDraft.value = '';
    try {
        // Menunggu supplier: draft disimpan di link-nya sendiri (satu user bisa menunggu beberapa link).
        if (form.link_id) {
            await (window as any).axios.put(route('bs.ap.berkas-link.draft', form.link_id), { data: form.data(), step: step.value });
            tandaiTersimpan();
        } else {
            const { data } = await (window as any).axios.post(route('bs.ap.draft.store'), { data: form.data(), step: step.value, draft_id: form.draft_id || null });
            form.draft_id = data.id;
            tandaiTersimpan(data.updated_at);
        }
    } catch (e: any) {
        if (!diam) galatDraft.value = e?.response?.data?.message ?? 'Draft gagal disimpan — coba lagi.';
    } finally { menyimpanDraft.value = false; }
}
function buangDraft() {
    if (!confirm('Buang draft ini? Isian yang tersimpan akan dihapus dan wizard dimulai dari awal.')) return;
    router.delete(route('bs.ap.draft.destroy', form.draft_id ? { draft: form.draft_id } : {}));
}
const draftPada = props.draftInvoice?.updated_at
    ? new Date(props.draftInvoice.updated_at).toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '';

/* ── SISA BUDGET & KOREKSI BUDGET (langkah Struktur) ──
   Sisa = anggaran BULAN tanggal invoice − realisasi bulan itu − nilai transaksi invoice ini, per
   anggaran (akun × cost center, atau akun "semua cost center"). Angkanya dari server
   (bs.ap.sisa-budget) — aturan yang sama dengan penjaga budget saat simpan. Baris yang minus
   bisa diajukan Koreksi Budget; invoice tetap jalan dan koreksinya masuk Approval Inbox. */
/** baru = akun wajib-anggaran yang belum punya anggaran → dianggap 0 (budget_id negatif; Master Budget nol dibuat saat diajukan). */
type SisaInfo = { budget_id: number; cakupan: 'cc' | 'semua'; anggaran: number; terpakai: number; anggaran_tahun: number; terpakai_tahun: number; label: string; tanda: number; baru?: boolean } | null;
const sisaInfo = ref<Record<string, SisaInfo>>({});
const bulanBudget = ref('');
const kunciBudget = (l: any) => `${l.account_id}|${l.cost_center_id ?? ''}`;
let timerSisa: ReturnType<typeof setTimeout> | null = null;
function muatSisa() {
    const pairs = [...new Set((form.journal_lines as any[]).filter((l) => l.account_id).map(kunciBudget))];
    if (!pairs.length || !form.invoice_date) return;
    if (timerSisa) clearTimeout(timerSisa);
    timerSisa = setTimeout(async () => {
        try {
            const { data } = await window.axios.get(route('bs.ap.sisa-budget'), {
                params: { date: form.invoice_date, pairs, exclude_journal_id: props.invoiceEdit?.journal_id ?? undefined },
            });
            sisaInfo.value = { ...sisaInfo.value, ...data.sisa };
            bulanBudget.value = data.bulan;
        } catch { /* kolom sisa dibiarkan kosong; server tetap menjaga saat simpan */ }
    }, 250);
}
watch(() => [step.value, form.invoice_date, (form.journal_lines as any[]).map(kunciBudget).join(',')], () => {
    if (step.value >= 4) muatSisa();
});
watch(() => form.invoice_date, () => { sisaInfo.value = {}; });
/** Pemakaian invoice ini per anggaran — sisi pengeluaran akunnya saja (debit utk akun beban), sama dgn server. */
const pakaiInvoice = computed<Record<number, number>>(() => {
    const out: Record<number, number> = {};
    for (const l of form.journal_lines as any[]) {
        const info = l.account_id ? sisaInfo.value[kunciBudget(l)] : null;
        if (!info) continue;
        out[info.budget_id] = (out[info.budget_id] ?? 0) + (info.tanda > 0 ? Number(l.debit) || 0 : Number(l.credit) || 0);
    }
    return out;
});
/* Ringkasan di bawah Struktur Jurnal: per anggaran, sisa TAHUNAN & BULANAN (Master Budget = anggaran
   berlaku − realisasi) sebelum invoice ini, pemakaian invoice ini, dan sisa sesudahnya — langsung
   berubah saat baris jurnal diubah. */
const ringkasanBudget = computed(() => {
    const out: Array<{ budget_id: number; label: string; invoice: number;
        tahun: { anggaran: number; realisasi: number; sebelum: number; sesudah: number };
        bulan: { anggaran: number; realisasi: number; sebelum: number; sesudah: number } }> = [];
    for (const l of form.journal_lines as any[]) {
        const info = l.account_id ? sisaInfo.value[kunciBudget(l)] : null;
        if (!info || out.some((o) => o.budget_id === info.budget_id)) continue;
        const pakai = r0(pakaiInvoice.value[info.budget_id] ?? 0);
        const sebelumT = r0(info.anggaran_tahun - info.terpakai_tahun);
        const sebelumB = r0(info.anggaran - info.terpakai);
        out.push({ budget_id: info.budget_id, label: info.label, invoice: pakai,
            tahun: { anggaran: r0(info.anggaran_tahun), realisasi: r0(info.terpakai_tahun), sebelum: sebelumT, sesudah: sebelumT - pakai },
            bulan: { anggaran: r0(info.anggaran), realisasi: r0(info.terpakai), sebelum: sebelumB, sesudah: sebelumB - pakai } });
    }
    return out;
});
const sisaAnggaran = (info: NonNullable<SisaInfo>) => r0(info.anggaran - info.terpakai - (pakaiInvoice.value[info.budget_id] ?? 0));
/** Sisa budget untuk baris; undefined = belum dimuat, null = akun tanpa anggaran. */
function sisaBaris(l: any): number | null | undefined {
    if (!l.account_id) return undefined;
    const info = sisaInfo.value[kunciBudget(l)];
    if (info === undefined) return undefined;
    return info ? sisaAnggaran(info) : null;
}
const labelCc = (id: number | null) => {
    const c = props.costCenters.find((x: any) => x.id === id);
    return c ? `${c.code} — ${c.name}` : 'Semua cost center';
};
/** Anggaran yang terlampaui oleh invoice ini — satu baris per anggaran (baris jurnal sama akun & cost center digabung). */
const overBudget = computed(() => {
    const out: Array<{ budget_id: number; account_id: number; cost_center_id: number | null; keterangan: string; kurang: number }> = [];
    for (const l of form.journal_lines as any[]) {
        const info = l.account_id ? sisaInfo.value[kunciBudget(l)] : null;
        if (!info || out.some((o) => o.budget_id === info.budget_id)) continue;
        const sisa = sisaAnggaran(info);
        if (sisa < 0) {
            out.push({ budget_id: info.budget_id, account_id: l.account_id, cost_center_id: info.cakupan === 'cc' ? l.cost_center_id : null,
                keterangan: String(l.description ?? '').trim(), kurang: -sisa });
        }
    }
    return out;
});
const koreksiUntuk = (budgetId: number) => (form.koreksi as any[]).find((k) => k.budget_id === budgetId);
const alokasiUntuk = (budgetId: number) => (form.alokasi as any[]).find((k) => k.budget_id === budgetId);
/** Anggaran baris ini (null = akunnya tanpa anggaran). kurang = kekurangan bila terlampaui, 0 bila masih cukup. */
function budgetBaris(l: any) {
    const info = l.account_id ? sisaInfo.value[kunciBudget(l)] : null;
    if (!info) return null;
    const sisa = sisaAnggaran(info);
    return { budget_id: info.budget_id, account_id: l.account_id, cost_center_id: info.cakupan === 'cc' ? l.cost_center_id : null,
        keterangan: String(l.description ?? '').trim(), kurang: sisa < 0 ? -sisa : 0 };
}
/* Baris merah WAJIB diselesaikan dengan SALAH SATU: Koreksi Budget (tambah anggaran, lewat Approval
   Inbox) atau Alokasi (pindah dari sisa bulan ini di budget lain, lewat Approval Alokasi Budget). Baris yang
   anggarannya masih cukup juga boleh memakainya (opsional) — nilai awalnya = nilai baris. */
function ajukanKoreksi(l: any) {
    const o = budgetBaris(l);
    if (!o || koreksiUntuk(o.budget_id) || alokasiUntuk(o.budget_id)) return;
    form.koreksi.push({ budget_id: o.budget_id, account_id: o.account_id, cost_center_id: o.cost_center_id, keterangan: o.keterangan, nilai: o.kurang || r0(l.debit) });
}
function ajukanAlokasi(l: any) {
    const o = budgetBaris(l);
    if (!o || koreksiUntuk(o.budget_id) || alokasiUntuk(o.budget_id)) return;
    form.alokasi.push({ budget_id: o.budget_id, account_id: o.account_id, cost_center_id: o.cost_center_id, keterangan: o.keterangan, nilai: o.kurang || r0(l.debit), dari_budget_id: '' });
    muatSumber();
}
/** Alokasi / Koreksi yang dipilih untuk anggaran baris ini (null = belum). */
function pilihanBudget(l: any): 'alokasi' | 'koreksi' | null {
    const o = budgetBaris(l);
    if (!o) return null;
    return alokasiUntuk(o.budget_id) ? 'alokasi' : (koreksiUntuk(o.budget_id) ? 'koreksi' : null);
}
/* Ganti-ganti selama invoice belum disimpan: klik pilihan yang aktif = lepas; klik yang lain = pindah
   (nilai yang sudah diketik ikut dibawa). */
function pilihBudget(l: any, jenis: 'alokasi' | 'koreksi') {
    const o = budgetBaris(l);
    if (!o) return;
    const a = alokasiUntuk(o.budget_id);
    const k = koreksiUntuk(o.budget_id);
    const nilai = a?.nilai ?? k?.nilai;
    form.alokasi = (form.alokasi as any[]).filter((x) => x.budget_id !== o.budget_id);
    form.koreksi = (form.koreksi as any[]).filter((x) => x.budget_id !== o.budget_id);
    if ((jenis === 'alokasi' && a) || (jenis === 'koreksi' && k)) return; // lepas
    jenis === 'alokasi' ? ajukanAlokasi(l) : ajukanKoreksi(l);
    const baru = jenis === 'alokasi' ? alokasiUntuk(o.budget_id) : koreksiUntuk(o.budget_id);
    if (baru && nilai !== undefined && Number(nilai) > 0) baru.nilai = nilai;
}
function hapusKoreksi(i: number) { form.koreksi.splice(i, 1); }
function hapusAlokasi(i: number) { form.alokasi.splice(i, 1); }
/** Tabel koreksi/alokasi bisa diubah di langkah Struktur; di Selesai hanya ditampilkan. */
const ubahKoreksi = computed(() => step.value === 4);
// Anggaran yang tak lagi dipakai baris jurnal (akun dihapus/diganti) → koreksi/alokasinya dibuang otomatis.
const budgetDipakai = computed(() => (form.journal_lines as any[]).map((l) => budgetBaris(l)?.budget_id).filter(Boolean) as number[]);
watch(budgetDipakai, (dipakai) => {
    if (!Object.keys(sisaInfo.value).length) return;
    form.koreksi = (form.koreksi as any[]).filter((k) => dipakai.includes(k.budget_id));
    form.alokasi = (form.alokasi as any[]).filter((k) => dipakai.includes(k.budget_id));
});
const kurangKoreksi = (k: any) => overBudget.value.find((o) => o.budget_id === k.budget_id)?.kurang ?? 0;

/* Budget asal alokasi: anggaran approved yang masih punya sisa di bulan invoice (server). */
type Sumber = { id: number; doc_no: string; label: string; sisa: number };
const sumberAlokasi = ref<Sumber[]>([]);
async function muatSumber() {
    if (!form.invoice_date) return;
    try {
        const { data } = await window.axios.get(route('bs.ap.sumber-alokasi'), {
            params: { date: form.invoice_date, kecuali: (form.alokasi as any[]).map((a) => a.budget_id), exclude_journal_id: props.invoiceEdit?.journal_id ?? undefined },
        });
        sumberAlokasi.value = data.budgets;
    } catch { sumberAlokasi.value = []; }
}
watch(() => form.invoice_date, () => { if ((form.alokasi as any[]).length) muatSumber(); });
onMounted(() => { if ((form.alokasi as any[]).length) muatSumber(); });
const opsiSumber = (a: any) => sumberAlokasi.value.filter((b) => b.id !== a.budget_id)
    .map((b) => ({ id: b.id, label: `${b.label} · sisa ${fmtNum(b.sisa)}`, sub: b.doc_no }));
/** Sisa budget asal setelah dikurangi alokasi lain dari budget asal yang sama. */
function sisaSumber(a: any): number | null {
    const b = sumberAlokasi.value.find((x) => x.id === a.dari_budget_id);
    if (!b) return null;
    const lain = (form.alokasi as any[]).filter((x) => x !== a && x.dari_budget_id === a.dari_budget_id).reduce((t, x) => t + (Number(x.nilai) || 0), 0);
    return r0(b.sisa - lain);
}
const alokasiSalah = (a: any) => !a.dari_budget_id || !(Number(a.nilai) > 0) || (Number(a.nilai) || 0) < kurangKoreksi(a) || (sisaSumber(a) !== null && (Number(a.nilai) || 0) > (sisaSumber(a) as number));

/** Anggaran terlampaui yang belum diselesaikan: tanpa koreksi/alokasi, nilai di bawah kekurangan, atau alokasi tanpa/melebihi asal. */
const koreksiBelumCukup = computed(() => [
    ...overBudget.value.filter((o) => {
        const k = koreksiUntuk(o.budget_id);
        const a = alokasiUntuk(o.budget_id);
        if (a) return alokasiSalah(a);
        return !k || (Number(k.nilai) || 0) < o.kurang;
    }),
    // Koreksi/alokasi opsional (anggaran masih cukup) tetap harus lengkap: nilai > 0, asal alokasi dipilih.
    ...(form.koreksi as any[]).filter((k) => !overBudget.value.some((o) => o.budget_id === k.budget_id) && !(Number(k.nilai) > 0)),
    ...(form.alokasi as any[]).filter((a) => !overBudget.value.some((o) => o.budget_id === a.budget_id) && alokasiSalah(a)),
]);

/* MEMO URGENT — satu memo (sudah disetujui Direktur) boleh ditarik ke invoice ini.
   Invoice Hutang: memo menyebut supplier-nya. Kalau supplier sudah dipilih, hanya memo supplier itu
   (dan memo lama tanpa supplier) yang tampil; memilih memo mengisi supplier invoice dari memo. */
const memoSupplier = props.jenis.kunci === 'invoice';
const memoCocok = (m: { supplier_id: number | null }) => !memoSupplier || !m.supplier_id || !form.supplier_id || m.supplier_id === Number(form.supplier_id);
const memoCari = ref('');
const memoTampil = computed(() => {
    const q = memoCari.value.trim().toLowerCase();
    const semua = (props.memoUrgent ?? []).filter(memoCocok);
    return q ? semua.filter((m) => [m.number, m.subject, m.department, m.supplier].some((x) => String(x ?? '').toLowerCase().includes(q))) : semua;
});
const memoDipilih = computed(() => (props.memoUrgent ?? []).find((m) => m.id === form.memo_internal_id) ?? null);
function pilihMemo(id: number) { form.memo_internal_id = form.memo_internal_id === id ? null : id; }
/* Dropdown cari memo di langkah 1 — pilihannya sama dengan tabel di langkah Struktur. */
const opsiMemo = computed(() => (props.memoUrgent ?? []).filter(memoCocok).map((m) => ({
    id: m.id, label: `${m.number} — ${m.subject} · ${fmtRp(m.amount)}${m.supplier ? ' · ' + m.supplier : ''}${m.department ? ' · ' + m.department : ''}`,
})));
const memuatMemo = ref(false);
// Memo dipilih → barisnya dilipat lagi, ringkasannya tampil di judul baris.
watch(() => form.memo_internal_id, (id) => {
    if (id && bukaLangkah1.value === 'memo') bukaLangkah1.value = null;
    // Supplier invoice ikut memo (registrasi baru saja — supplier invoice yang diedit terkunci).
    const sup = memoDipilih.value?.supplier_id;
    if (memoSupplier && sup && !modeEdit && Number(form.supplier_id) !== sup) form.supplier_id = sup;
});
// Memo Urgent: jatuh tempo hari itu juga & Nilai Transaksi = nominal memo (masih bisa diubah).
// Memo dilepas → jatuh tempo dikosongkan lagi; nilai hanya ikut dikosongkan bila belum diubah.
let dppDariMemo: number | null = null;
/** Memo dengan rincian pajak → Nilai Transaksi, PPN & PPh tahap 2 ikut memo dan dikunci (server juga memaksa). */
const kunciPajak = computed(() => !!memoDipilih.value?.pajak);
async function isiPajakMemo(p: NonNullable<NonNullable<typeof memoDipilih.value>['pajak']>) {
    // Mode & jenis dulu; watcher-nya mengisi tarif/objek/akun default — sesudah itu ditimpa angka memo.
    form.dpp = p.dpp;
    form.ppn_mode = p.ppn_mode;
    form.ppn_incl = p.ppn_incl;
    form.pph_mode = p.pph_mode;
    form.pph_type = p.pph_type ?? '';
    await nextTick();
    await nextTick();
    form.ppn_pct = p.ppn_mode === 'with' ? p.ppn_pct : 11;
    form.ppn_base = p.ppn_base;
    form.pph_pct = p.pph_pct;
    form.pph_base = p.pph_base;
}
watch(memoDipilih, (m, lama) => {
    if (m?.pajak) {
        form.term_days = 0;
        isiPajakMemo(m.pajak);
        return;
    }
    if (lama?.pajak && !m) {
        // Memo berpajak dilepas → tahap 2 kembali kosong.
        Object.assign(form, { dpp: 0, ppn_mode: 'none', ppn_incl: false, ppn_base: 0, pph_mode: 'none', pph_type: '', pph_pct: 0, pph_base: 0 });
    }
    if (modeEdit) return;
    if (m) {
        form.term_days = 0;
        if (!Number(form.dpp) || Number(form.dpp) === dppDariMemo || (lama && Number(form.dpp) === lama.amount)) {
            form.dpp = m.amount;
            dppDariMemo = m.amount;
        }
    } else if (lama) {
        if (Number(form.term_days) === 0) form.term_days = '';
        if (dppDariMemo !== null && Number(form.dpp) === dppDariMemo) form.dpp = 0;
        dppDariMemo = null;
    }
});
// Supplier diganti ke supplier lain → memo yang suppliernya beda dilepas.
watch(() => form.supplier_id, () => { if (memoDipilih.value && !memoCocok(memoDipilih.value)) form.memo_internal_id = null; });
/** Ambil ulang daftar memo (mis. sesudah membuat / menyetujui memo di tab lain) tanpa mengganggu isian. */
function muatUlangMemo() {
    memuatMemo.value = true;
    router.reload({ only: ['memoUrgent', 'memoMenungguBayar'], onFinish: () => { memuatMemo.value = false; } });
}

/* ── TTD DEPT HEAD ──
   Staf: "Ajukan ke Dept Head" (aktif di langkah Struktur) menyimpan isian sebagai draft lalu mengajukan
   berkasnya ke TTD atasan. Lanjut ke Selesai baru bisa setelah Dept Head menyetujui (status dicek
   berkala; pembuat juga mendapat notifikasi). Pembuat yang Dept Head: TTD otomatis saat disimpan. */
const sayaDeptHead = !!props.ttdDeptHead?.saya;
const perluTtd = computed(() => wajibBerkas && !sayaDeptHead);
const statusTtd = computed<'belum' | 'menunggu' | 'disetujui' | 'ditolak'>(() => {
    const st = berkasDipilih.value?.status;
    if (berkasDipilih.value?.bisa_diinvoice) return 'disetujui';
    return st === 'menunggu_ttd' ? 'menunggu' : (st === 'ditolak' ? 'ditolak' : 'belum');
});
const mengajukanDh = ref(false);
const galatDh = ref('');
const bisaAjukanDh = computed(() => perluTtd.value && step.value === 4 && !!berkasDipilih.value && !mengajukanDh.value
    && (statusTtd.value === 'belum' || statusTtd.value === 'ditolak') && jurnalBalanced.value && kurangCc.value.length === 0);
async function ajukanDeptHead() {
    if (!bisaAjukanDh.value) return;
    mengajukanDh.value = true; galatDh.value = '';
    try {
        const { data } = await (window as any).axios.post(route('bs.ap.ajukan-dept-head'), {
            data: form.data(), step: step.value, draft_id: form.draft_id || null, link_id: form.link_id || null,
        });
        if (data.draft_id) form.draft_id = data.draft_id;
        tandaiTersimpan(data.updated_at);
        router.reload({ only: ['berkasSaya'] });
    } catch (e: any) {
        const r = e?.response?.data;
        galatDh.value = r?.message && !r?.errors ? r.message : (r?.errors ? String(Object.values(r.errors)[0]) : 'Gagal mengajukan — coba lagi.');
    } finally { mengajukanDh.value = false; }
}
// Selagi menunggu TTD: status berkas dicek tiap 20 detik.
let pantauTtd: ReturnType<typeof setInterval> | null = null;
watch(statusTtd, (st) => {
    if (pantauTtd) { clearInterval(pantauTtd); pantauTtd = null; }
    if (st === 'menunggu') pantauTtd = setInterval(() => router.reload({ only: ['berkasSaya'] }), 20000);
}, { immediate: true });
onBeforeUnmount(() => { if (pantauTtd) clearInterval(pantauTtd); });

// Langkah Struktur sudah lengkap selain TTD Dept Head → sebutkan TTD-lah yang menahan tombolnya.
const canNextTanpaTtd = computed(() => step.value === 4 && jurnalBalanced.value && kurangCc.value.length === 0);
function canNext() {
    if (step.value === 1) return !!form[kolomPihak] && (props.jenis.kunci !== 'deposit' || rekeningLengkap.value);
    // Berkas Tagihan ada di langkah ini. Generate Link: tidak bisa lanjut sampai supplier mengunggah dokumen di link itu.
    if (step.value === 2) return !!form[kolomPihak] && !!form.category_id && !!form.supplier_invoice_no && Number(form.dpp) > 0 && !!form.description && (Number(form.term_days) > 0 || !!form.memo_internal_id)
        && (!wajibBerkas || (!!berkasDipilih.value && (modeBerkas.value !== 'link' || linkAktif.value?.status === 'terisi')));
    // Langkah Struktur: selain balance, cost center pada baris yang memerlukannya harus terisi.
    // Baris jurnal tanpa cost center tidak terhitung di Budget Control maupun laporan per cost
    // center — dan itu tidak muncul sebagai galat apa pun, hanya angka yang kurang. Karena itu
    // ditahan di sini, bukan diperbaiki belakangan.
    // Alokasi / koreksi WAJIB disetujui Finance dulu (Pengajuan Budget) sebelum boleh ke Selesai.
    // Sesudah disetujui, anggaran sudah berubah (sisa asal alokasi berkurang) — kelengkapan koreksi/alokasi
    // tidak dinilai ulang; penjaga budget server tetap memeriksa saat simpan.
    if (step.value === 3) return !barisTakSamaDpp.value;
    if (step.value === 4) return jurnalBalanced.value && kurangCc.value.length === 0
        && (!perluTtd.value || statusTtd.value === 'disetujui')
        && (statusPengajuan.value === 'disetujui' || (koreksiBelumCukup.value.length === 0 && !butuhPengajuan.value));
    return true;
}
// "Simpan & Ajukan" (registrasi baru ber-berkas) = lanjut ke langkah berikutnya + simpan draft diam-diam.
function next() {
    if (!canNext() || step.value >= 5) return;
    step.value++;
    if (wajibBerkas && !props.pengajuanBudget && draftBerubah.value) simpanDraft(true);
}

/* ── PENGAJUAN BUDGET ──
   Baris Struktur yang ditutup Alokasi / Koreksi → "Ajukan ke Finance": isian tersimpan sebagai draft
   di pengajuan (nomor invoice dipesan), wizard terkunci sampai Finance memutuskan. Disetujui → lanjut
   ke Selesai; ditolak → isian bisa diubah & diajukan ulang. Status dicek berkala selama menunggu. */
// Hanya invoice yang menarik MEMO URGENT yang ditahan sampai Finance menyetujui. Invoice biasa: alokasi /
// koreksi diajukan bersama invoice saat disimpan (tidak menahan) — langsung ke Selesai.
const adaBudgetBaru = computed(() => (form.koreksi as any[]).length > 0 || (form.alokasi as any[]).length > 0);
const butuhPengajuan = computed(() => adaBudgetBaru.value && (!!form.memo_internal_id || !!props.pengajuanBudget));
const statusPengajuan = computed(() => props.pengajuanBudget?.status ?? null);
/* Setelah Finance menyetujui semua alokasi / koreksi, stepper bisa diklik untuk melompat ke langkah mana pun. */
const bisaLompat = computed(() => statusPengajuan.value === 'disetujui');
const langkahAwal = modeEdit ? 2 : 1;
const kunciPengajuan = computed(() => statusPengajuan.value === 'menunggu' || statusPengajuan.value === 'disetujui');
const bisaAjukan = computed(() => butuhPengajuan.value && statusPengajuan.value !== 'menunggu' && statusPengajuan.value !== 'disetujui'
    && (!perluTtd.value || statusTtd.value === 'disetujui') && jurnalBalanced.value && kurangCc.value.length === 0 && koreksiBelumCukup.value.length === 0);
const mengajukan = ref(false);
const konfirmasiFinance = ref(false);
const jenisPengajuan = computed(() => [(form.alokasi as any[]).length ? 'alokasi' : '', (form.koreksi as any[]).length ? 'koreksi' : ''].filter(Boolean).join(' / '));
const galatPengajuan = ref('');
async function ajukanFinance() {
    mengajukan.value = true; galatPengajuan.value = '';
    try {
        const { data } = await (window as any).axios.post(route('bs.ap.pengajuan-budget.store'), {
            jenis: props.jenis.kunci, data: form.data(), invoice_id: props.invoiceEdit?.id ?? null,
            pengajuan_id: statusPengajuan.value === 'ditolak' ? props.pengajuanBudget!.id : null,
        });
        sudahKirim = true;
        router.visit(data.kembali ?? data.url);
    } catch (e: any) {
        const r = e?.response?.data;
        galatPengajuan.value = r?.message && !r?.errors ? r.message : (r?.errors ? String(Object.values(r.errors)[0]) : 'Gagal mengajukan — coba lagi.');
        mengajukan.value = false;
    }
}
function batalPengajuan() {
    if (!props.pengajuanBudget || !confirm('Batalkan pengajuan budget ini? Nomor invoice yang dipesan tidak dipakai dan isian tidak disimpan.')) return;
    router.delete(route('bs.ap.pengajuan-budget.destroy', props.pengajuanBudget.id));
}
let pantauPengajuan: ReturnType<typeof setInterval> | null = null;
watch(statusPengajuan, (s) => {
    if (pantauPengajuan) { clearInterval(pantauPengajuan); pantauPengajuan = null; }
    if (s === 'menunggu') pantauPengajuan = setInterval(() => router.reload({ only: ['pengajuanBudget'] }), 15000);
}, { immediate: true });
onBeforeUnmount(() => { if (pantauPengajuan) clearInterval(pantauPengajuan); });
const fmtTgl = (s: string | null) => (s ? new Date(s).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
function prev() { if (step.value > (modeEdit ? 2 : 1)) step.value--; }

/* Tombol "Kembali" pada REGISTRASI = mulai lagi dari awal di halaman yang sama: isian dikosongkan
   dan wizard kembali ke langkah 1. Ditanyakan dulu bila sudah ada yang diisi, supaya ketikan
   tidak hilang karena salah klik. (Mode Edit tetap kembali ke rincian invoice.) */
function ulangDariAwal() {
    if (form.isDirty && ! confirm('Isian akan dikosongkan dan dimulai lagi dari langkah 1. Lanjutkan?')) return;
    form.reset();
    form.clearErrors();
    supplierSearch.value = '';
    strukturBawaanTerakhir = [];
    dppBaris.value = 0;
    step.value = 1;
}

/* ── KOLOM WAJIB: bingkai merah + pesan ──
   Tombol Berikutnya tetap dimatikan canNext(); tandanya memberi tahu KOLOM MANA yang menahannya.
   Galat server (alasan sebenarnya, mis. terlalu panjang) didahulukan daripada "Wajib diisi". */
const galatServer = (kolom: string): string | undefined => (form.errors as Record<string, string | undefined>)[kolom];
const pesan = computed(() => ({
    pihak: pesanKolom(galatServer(kolomPihak), kosong(form[kolomPihak]), 'Wajib dipilih — klik salah satu nama di daftar'),
    bank_name: pesanKolom(galatServer('bank_name'), kosong(form.bank_name)),
    bank_account_no: pesanKolom(galatServer('bank_account_no'), kosong(form.bank_account_no)),
    bank_account_name: pesanKolom(galatServer('bank_account_name'), kosong(form.bank_account_name)),
    category_id: pesanKolom(galatServer('category_id'), kosong(form.category_id)),
    supplier_invoice_no: pesanKolom(galatServer('supplier_invoice_no'), kosong(form.supplier_invoice_no)),
    term_days: pesanKolom(galatServer('term_days'), ! (Number(form.term_days) > 0 || !!form.memo_internal_id), 'Wajib diisi — tentukan jangka waktu jatuh tempo'),
    description: pesanKolom(galatServer('description'), kosong(form.description)),
    dpp: pesanKolom(galatServer('dpp'), ! (Number(form.dpp) > 0), 'Wajib diisi — lebih dari 0'),
    manual_no: pesanKolom(galatServer('manual_no'), false),
    receive_date: pesanKolom(galatServer('receive_date'), false),
    berkas: pesanKolom(galatServer('berkas_upload_id'), wajibBerkas && !berkasDipilih.value, 'Wajib — unggah file tagihan atau tunggu dokumen dari link supplier'),
}));
/** Uraian baris Detil Tagihan wajib (server: lines.*.description). */
const pesanUraian = (i: number) => pesanKolom(galatServer(`lines.${i}.description`), kosong(form.lines[i]?.description));

/* Kolom → langkah tempatnya. Bila server menolak, wizard melompat ke langkah kolom pertama yang
   salah — kalau tidak, bingkai merahnya ada di langkah yang tidak sedang terlihat. */
const LANGKAH_KOLOM: Array<[RegExp, number]> = [
    [/^(supplier_id|employee_id|customer_id|bank_)/, 1],
    [/^(category_id|supplier_invoice_no|manual_no|invoice_date|receive_date|due_date|term_days|description|transfer_detail|kurs|dpp|ppn_|pph_)/, 2],
    [/^berkas_upload_id/, 2],
    [/^lines\./, 3],
    [/^(journal_lines|koreksi)\./, 4],
];
function submit() {
    const opsi = {
        // Isian TIDAK dibuang saat server menolak: tanpa preserveState halaman dimuat ulang, wizard
        // kembali ke langkah 1 dan seluruh isian hilang — tepat yang dikeluhkan di panduan.
        preserveState: true,
        preserveScroll: true,
        // Server menjawab TANPA pindah halaman (mis. ditolak anggaran → dialog, pesan galat) →
        // tidak ada yang tersimpan; wizard boleh disimpan lagi. Simpan yang berhasil pindah penuh
        // ke cetak Memo (Inertia::location) dan tidak melewati onSuccess ini.
        onSuccess: () => { sudahKirim = false; },
        onError: (galat: Record<string, string>) => {
            sudahKirim = false;   // ditolak server → wizard tetap di sini, boleh disimpan lagi
            const kolom = Object.keys(galat);
            const tujuan = kolom.map(k => LANGKAH_KOLOM.find(([pola]) => pola.test(k))?.[1] ?? 5);
            if (tujuan.length) step.value = Math.max(modeEdit ? 2 : 1, Math.min(...tujuan));
        },
    };
    sudahKirim = true;
    if (modeEdit) {
        form.put(route('bs.ap.update', props.invoiceEdit!.id), opsi);
    } else {
        form.post(route(props.jenis.kunci === 'invoice' ? 'bs.ap.store' : props.jenis.rute + '.store'), opsi);
    }
}
/* SIMPAN GANDA LEWAT BACK (ESTATE 23 Okt 2026: satu deposit tersimpan 3x). Sesudah tersimpan,
   layar pindah penuh ke cetak Memo/BPP; tombol Back browser lalu bisa MEMULIHKAN wizard yang masih
   terisi di langkah terakhir (bfcache), dan Simpan berikutnya membuat invoice baru lagi. Wizard yang
   dipulihkan sesudah dikirim langsung dialihkan ke daftar (edit: ke detail invoice) — invoice yang
   baru tersimpan ada di sana. */
let sudahKirim = false;
function saatDipulihkan(ev: PageTransitionEvent) {
    if (ev.persisted && sudahKirim) {
        window.location.replace(modeEdit ? route('bs.ap.show', props.invoiceEdit!.id) : route(props.jenis.rute + '.index'));
    }
}
onMounted(() => window.addEventListener('pageshow', saatDipulihkan));
onBeforeUnmount(() => window.removeEventListener('pageshow', saatDipulihkan));
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const dimCls = 'h-8 w-full rounded-md border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900';
const cellArea = 'w-full rounded-md border border-neutral-300 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div :class="['space-y-4', step === 4 ? 'max-w-none' : 'max-w-4xl']">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-danger-600" /> <template v-if="invoiceEdit">Edit {{ jenis.judul }} <span class="font-mono text-lg text-neutral-500">{{ invoiceEdit.invoice_number }}</span></template><template v-else>Registrasi {{ jenis.judul }}</template></h1>
            <Link v-if="invoiceEdit" :href="route('bs.ap.show', invoiceEdit.id)" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
            <button v-else type="button" @click="ulangDariAwal" title="Kosongkan isian & mulai lagi dari langkah 1" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</button>
        </div>

        <!-- Dari Upload Berkas -->
        <div v-if="dariBerkas" class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm dark:border-primary-900 dark:bg-primary-950/40">
            <p class="font-semibold">Dari berkas <span class="font-mono">{{ dariBerkas.number }}</span> — sudah ditandatangani Dept Head</p>
            <p v-if="pengajuanBudget?.status === 'disetujui'" class="mt-1 text-neutral-600 dark:text-neutral-300">Pengajuan budget invoice ini sudah disetujui penuh lewat alur approval invoice — setelah disimpan, invoice <b>tidak diajukan approval lagi</b> dan bisa langsung dibayar.</p>
            <p v-else class="mt-1 text-neutral-600 dark:text-neutral-300">Setelah disimpan, invoice ini langsung masuk approval <b>Accounting → Finance → GM → Direktur → MW / BA</b> dan baru bisa dibayar setelah disetujui penuh.</p>
            <p v-if="dariBerkas.invoiced" class="mt-1 text-neutral-600 dark:text-neutral-300">Sudah diinvoice Rp {{ Math.round(dariBerkas.invoiced).toLocaleString('id-ID') }} — sisa <b>Rp {{ Math.round(dariBerkas.sisa ?? 0).toLocaleString('id-ID') }}</b>. Nilai invoice tidak boleh melebihi sisa.</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <a v-for="(f, i) in dariBerkas.files" :key="i" :href="f.url" target="_blank" rel="noopener" class="rounded-md border border-primary-200 bg-white px-2 py-1 text-xs text-primary-700 hover:underline dark:border-primary-800 dark:bg-neutral-900 dark:text-primary-300">
                    📎 {{ f.jenis ? f.jenis + ' · ' : '' }}{{ f.name }}
                </a>
            </div>
        </div>

        <div v-if="pakaiDraft" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm dark:border-primary-900 dark:bg-primary-950/40">
            <span>Melanjutkan <b>draft</b> yang tersimpan{{ draftPada ? ' ' + draftPada : '' }}.</span>
            <button type="button" class="text-xs text-danger-600 hover:underline" @click="buangDraft">Buang draft</button>
        </div>
        <!-- Stepper -->
        <div class="card flex flex-wrap items-center gap-1 p-3 text-sm">
            <template v-for="(s, i) in steps" :key="i">
                <!-- Pengajuan budget sudah disetujui: semua isian sudah final, langkah bisa diklik bebas (lihat / kembali ke Selesai). -->
                <component :is="bisaLompat && i+1 >= langkahAwal ? 'button' : 'div'" type="button" @click="bisaLompat && i+1 >= langkahAwal && (step = i+1)"
                    class="flex items-center gap-2 rounded-lg px-2.5 py-1" :class="[step === i+1 ? 'bg-primary-600 text-white' : (step > i+1 || (bisaLompat && i+1 >= langkahAwal) ? 'text-success-600' : 'text-neutral-400'),
                        bisaLompat && i+1 >= langkahAwal && step !== i+1 ? 'cursor-pointer hover:bg-success-50 dark:hover:bg-success-900/30' : '']">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs" :class="step > i+1 || (bisaLompat && step !== i+1) ? 'bg-success-100 dark:bg-success-900/40' : (step === i+1 ? 'bg-white/20' : 'bg-neutral-100 dark:bg-neutral-800')"><Check v-if="step > i+1 || (bisaLompat && step !== i+1)" class="h-3 w-3" /><span v-else>{{ i+1 }}</span></span>
                    {{ s }}
                </component>
                <ArrowRight v-if="i < steps.length-1" class="h-3.5 w-3.5 text-neutral-300" />
            </template>
            <!-- Simpan Draft: di kanan "Selesai"; redup bila tidak ada perubahan sejak tersimpan. -->
            <div v-if="wajibBerkas && !pengajuanBudget" class="ml-auto flex items-center gap-2">
                <span v-if="galatDraft" class="text-xs text-danger-500">{{ galatDraft }}</span>
                <span v-else-if="jamTersimpan && !draftBerubah" class="inline-flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400"><CircleCheck class="h-3.5 w-3.5" /> Tersimpan di draft · {{ jamTersimpan }}</span>
                <span v-else-if="jamTersimpan" class="text-xs text-neutral-400">Ada perubahan belum disimpan</span>
                <button type="button" :disabled="menyimpanDraft || !draftBerubah" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium hover:bg-neutral-50 disabled:cursor-default disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="simpanDraft()">
                    <Save class="h-3.5 w-3.5" /> {{ menyimpanDraft ? 'Menyimpan…' : 'Simpan Draft' }}</button>
            </div>
        </div>

        <!-- Nav — di atas isian (tepat di bawah stepper) supaya tombolnya tidak perlu dicari di bawah. -->
        <div class="flex items-center justify-between">
            <button v-if="step > (modeEdit ? 2 : 1)" type="button" @click="prev" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700"><ArrowLeft class="h-4 w-4" /> Sebelumnya</button>
            <span v-else></span>
            <!-- Alasan tombolnya mati DISEBUTKAN, bukan dibiarkan ditebak. Tombol nonaktif tanpa
                 keterangan pada wizard berlangkah membuat orang menyangka layarnya rusak. -->
            <span v-if="step === 4 && !kurangCc.length && koreksiBelumCukup.length && statusPengajuan !== 'disetujui'" class="self-center text-xs text-danger-500">
                Melebihi sisa budget — klik "Alokasi" atau "Koreksi" pada baris merah{{ form.koreksi.length || form.alokasi.length ? ', pilih budget asal alokasi, dan isi nilainya minimal sebesar kekurangan' : '' }}.
            </span>
            <span v-if="step === 4 && kurangCc.length" class="self-center text-xs text-danger-500">
                Cost center wajib diisi untuk akun: {{ kurangCc.join(', ') }}.
            </span>
            <span v-if="step === 2 && wajibBerkas && !berkasDipilih" class="self-center text-xs text-danger-500">Pilih berkas tagihan dulu.</span>
            <span v-if="step === 5 && berkasBelumSiap && perluTtd" class="self-center text-xs text-amber-600">Berkas belum di-TTD Dept Head — ajukan di langkah Struktur.</span>
            <span v-if="step === 4 && perluTtd && statusTtd !== 'disetujui' && canNextTanpaTtd" class="self-center text-xs text-amber-600">{{ statusTtd === 'menunggu' ? 'Lanjut setelah Dept Head menyetujui.' : 'Ajukan ke Dept Head dulu.' }}</span>
            <div class="flex items-center gap-2">
            <!-- TTD Dept Head (staf): diajukan di langkah Struktur; Simpan & Ajukan menunggu persetujuannya. -->
            <span v-if="perluTtd && statusTtd === 'menunggu'" class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">Menunggu TTD Dept Head{{ berkasDipilih?.dept_head ? ' · ' + berkasDipilih.dept_head : '' }}</span>
            <span v-else-if="perluTtd && statusTtd === 'disetujui'" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">Sudah di-TTD Dept Head</span>
            <span v-else-if="perluTtd && statusTtd === 'ditolak'" class="rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 dark:bg-danger-950/40 dark:text-danger-300">Ditolak Dept Head — perbaiki lalu ajukan ulang</span>
            <span v-if="galatDh" class="self-center text-xs text-danger-500">{{ galatDh }}</span>
            <button v-if="perluTtd && !pengajuanBudget" type="button" :disabled="!bisaAjukanDh"
                :title="step < 4 ? 'Aktif di langkah Struktur' : (statusTtd === 'menunggu' ? 'Sudah diajukan — menunggu Dept Head' : (statusTtd === 'disetujui' ? 'Sudah di-TTD Dept Head' : ''))"
                class="inline-flex items-center gap-1.5 rounded-lg border border-primary-300 px-4 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-primary-800 dark:text-primary-300 dark:hover:bg-primary-950/40"
                @click="ajukanDeptHead"><Signature class="h-4 w-4" /> {{ mengajukanDh ? 'Mengajukan…' : 'Ajukan ke Dept Head' }}</button>
            <span v-if="galatPengajuan" class="self-center text-xs text-danger-500">{{ galatPengajuan }}</span>
            <span v-if="step === 4 && statusPengajuan === 'menunggu'" class="self-center text-xs text-amber-600">Menunggu approval.</span>
            <!-- Ada alokasi / koreksi yang belum disetujui: Selanjutnya membuka konfirmasi pengajuan ke Finance. -->
            <Button v-if="step === 4 && butuhPengajuan && statusPengajuan !== 'disetujui' && statusPengajuan !== 'menunggu'" :disabled="!bisaAjukan || mengajukan" @click="konfirmasiFinance = true">
                <template #icon-left><ArrowRight class="h-4 w-4" /></template>{{ mengajukan ? 'Mengajukan…' : 'Selanjutnya' }}</Button>
            <Button v-else-if="step < 5" :disabled="!canNext()" @click="next"><template #icon-left><ArrowRight class="h-4 w-4" /></template>{{ wajibBerkas && !pengajuanBudget ? 'Simpan & Ajukan' : 'Selanjutnya' }}</Button>
            <Button v-else :disabled="form.processing || (berkasBelumSiap && perluTtd)" @click="submit"><template #icon-left><Save class="h-4 w-4" /></template>{{ modeEdit ? 'Simpan Perubahan' : 'Simpan & Buat Jurnal' }}</Button>
            </div>
        </div>

        <!-- Pengajuan Budget: status persetujuan Finance atas alokasi / koreksi langkah Struktur. -->
        <div v-if="pengajuanBudget" :class="['rounded-xl border p-4 text-sm',
            pengajuanBudget.status === 'disetujui' ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'
            : pengajuanBudget.status === 'ditolak' ? 'border-danger-200 bg-danger-50 dark:border-danger-900 dark:bg-danger-950/30'
            : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30']">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-semibold">
                        <template v-if="pengajuanBudget.status === 'disetujui'">✓ Budget disetujui — lanjutkan ke Selesai &amp; simpan invoice.</template>
                        <template v-else-if="pengajuanBudget.status === 'ditolak'">Pengajuan budget ditolak — ubah alokasi / koreksi di langkah Struktur lalu ajukan ulang.</template>
                        <template v-else>Menunggu approval (alur invoice) — invoice belum bisa diselesaikan.</template>
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-600 dark:text-neutral-300">
                        <template v-if="pengajuanBudget.invoice_number">No. invoice dipesan <span class="font-mono font-semibold">{{ pengajuanBudget.invoice_number }}</span> · </template>
                        diajukan {{ fmtTgl(pengajuanBudget.diajukan) }}<template v-if="pengajuanBudget.diputuskan"> · diputuskan {{ fmtTgl(pengajuanBudget.diputuskan) }}</template>
                        <template v-if="pengajuanBudget.status === 'menunggu'"> · dicek otomatis tiap 15 detik; Anda juga dapat notifikasi. Halaman ini boleh ditutup.</template>
                    </p>
                    <p v-if="pengajuanBudget.status === 'ditolak' && pengajuanBudget.catatan" class="mt-1 text-xs font-medium text-danger-700 dark:text-danger-300">{{ pengajuanBudget.catatan }}</p>
                </div>
                <button v-if="pengajuanBudget.status !== 'disetujui'" type="button" class="text-xs text-danger-600 hover:underline" @click="batalPengajuan">Batalkan pengajuan</button>
            </div>
            <ul class="mt-2 flex flex-wrap gap-2 text-xs">
                <li v-for="it in pengajuanBudget.item" :key="it.doc_no" class="rounded-md border border-neutral-200 bg-white px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                    <span class="font-medium">{{ it.jenis }}</span>
                    <a v-if="it.approval_url" :href="it.approval_url" class="ml-1 font-mono text-primary-600 hover:underline">{{ it.doc_no }}</a><span v-else class="ml-1 font-mono">{{ it.doc_no }}</span>
                    · {{ fmtRp(it.nilai) }} ·
                    <span :class="it.status === 'approved' ? 'text-emerald-700 dark:text-emerald-300' : it.status === 'rejected' ? 'text-danger-600' : 'text-amber-700 dark:text-amber-300'">{{ it.status === 'approved' ? 'Disetujui' : it.status === 'rejected' ? 'Ditolak' : it.status === 'batal' ? 'Dibatalkan' : 'Menunggu' }}</span>
                </li>
            </ul>
        </div>

        <!-- Menunggu / sudah disetujui Finance: isian langkah 1–4 dikunci (yang disetujui = yang diajukan). -->
        <fieldset :disabled="kunciPengajuan" class="min-w-0">
        <!-- Step 1: Pilih Supplier -->
        <div v-show="step === 1" class="card p-5">
            <!-- Dua baris terlipat: klik untuk memilih Memo Urgent atau penerima. -->
            <div class="divide-y divide-neutral-200 overflow-hidden rounded-lg border border-neutral-200 dark:divide-neutral-800 dark:border-neutral-800">
                <!-- Memo Urgent (opsional) — memo yang sudah disetujui Direktur; dibuat di modul Memo Urgent. -->
                <div>
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="bukaLangkah1 === 'memo' ? 'bg-neutral-50 dark:bg-neutral-800/50' : ''" @click="bukaTutup('memo')">
                        <FileText class="h-5 w-5 shrink-0" :class="memoDipilih ? 'text-primary-600' : 'text-neutral-400'" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">Memo Urgent <span class="text-xs font-normal text-neutral-400">(opsional)</span></span>
                            <span v-if="memoDipilih" class="block truncate text-xs text-primary-700 dark:text-primary-300">{{ memoDipilih.number }} — {{ memoDipilih.subject }} · {{ fmtRp(memoDipilih.amount) }}</span>
                            <span v-else class="block text-xs text-neutral-400">{{ opsiMemo.length ? opsiMemo.length + ' memo siap ditarik — klik untuk memilih' : 'Belum ada memo yang siap ditarik' }}</span>
                        </span>
                        <Check v-if="memoDipilih" class="h-4 w-4 text-success-600" />
                        <ChevronDown class="h-4 w-4 text-neutral-400 transition-transform" :class="bukaLangkah1 === 'memo' ? 'rotate-180' : ''" />
                    </button>
                    <div v-if="bukaLangkah1 === 'memo'" class="border-t border-neutral-100 bg-neutral-50/60 px-4 py-3 dark:border-neutral-800 dark:bg-neutral-800/30">
                        <div class="mb-1.5 flex flex-wrap items-center justify-end gap-3 text-xs">
                            <button type="button" class="text-neutral-500 hover:text-primary-600 disabled:opacity-50" :disabled="memuatMemo" @click="muatUlangMemo">{{ memuatMemo ? 'Memuat…' : 'Muat ulang' }}</button>
                            <a v-if="bisaBuatMemo" :href="route('approval-inbox.memo')" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline"><FileText class="h-3.5 w-3.5" /> Daftar Memo Urgent</a>
                        </div>
                        <SearchSelect v-model="form.memo_internal_id" :options="opsiMemo" :placeholder="opsiMemo.length ? '— cari no. memo / perihal —' : '— belum ada memo yang disetujui Direktur —'" :no-truncate="true" />
                        <p v-if="memoDipilih" class="mt-1 text-[11px] text-neutral-500">{{ memoDipilih.date }} · {{ memoDipilih.department || '—' }}<template v-if="memoDipilih.supplier"> · Supplier: <b>{{ memoDipilih.supplier }}</b> (terisi otomatis)</template> · <span class="text-amber-600">{{ memoDipilih.status }}</span>
                            <a v-if="memoDipilih.url" :href="memoDipilih.url" target="_blank" rel="noopener" class="ml-1 text-primary-600 hover:underline">lihat memo</a></p>
                        <p v-else class="mt-1 text-[11px] text-neutral-400">Memo baru dibuat di modul Memo Urgent (tab baru). Memo Urgent muncul di sini setelah disetujui Direktur <b>dan dibayar di Kuitansi Hutang</b> — klik "Muat ulang".</p>
                        <div v-if="memoMenungguBayar?.length" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300">
                            <b>{{ memoMenungguBayar.length }} Memo Urgent belum bisa ditarik — bayar dulu</b> di
                            <a :href="route('bs.ap.kuitansi.create', { mode: 'memo' })" target="_blank" rel="noopener" class="font-semibold underline">Kuitansi Hutang › Bayar Memo Urgent</a>:
                            {{ memoMenungguBayar.map((m) => m.number).join(', ') }}
                        </div>
                    </div>
                </div>
                <!-- Penerima (supplier / karyawan / customer) -->
                <div>
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="bukaLangkah1 === 'pihak' ? 'bg-neutral-50 dark:bg-neutral-800/50' : ''" @click="bukaTutup('pihak')">
                        <Building2 class="h-5 w-5 shrink-0" :class="supplier ? 'text-primary-600' : 'text-neutral-400'" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ jenis.penerima }} <span class="text-xs font-normal text-danger-500">*</span></span>
                            <span v-if="supplier" class="block truncate text-xs text-primary-700 dark:text-primary-300">{{ supplier.code }} {{ supplier.name }}</span>
                            <span v-else class="block text-xs text-neutral-400">Klik untuk mencari {{ jenis.kunci === 'invoice' ? 'supplier / kontraktor' : jenis.penerima.toLowerCase() }}</span>
                        </span>
                        <Check v-if="supplier" class="h-4 w-4 text-success-600" />
                        <ChevronDown class="h-4 w-4 text-neutral-400 transition-transform" :class="bukaLangkah1 === 'pihak' ? 'rotate-180' : ''" />
                    </button>
                    <div v-if="bukaLangkah1 === 'pihak'" class="border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <div class="relative mb-3"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="supplierSearch" :placeholder="jenis.kunci === 'invoice' ? 'Cari supplier / kontraktor…' : 'Cari ' + jenis.penerima.toLowerCase() + '…'" :class="[inputCls,'pl-9']" /></div>
                        <div class="max-h-96 divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
                            <button v-for="s in filteredSuppliers" :key="s.id" type="button" @click="pickSupplier(s)" class="flex w-full items-center justify-between px-2 py-2.5 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="form[kolomPihak] === s.id ? 'bg-primary-50 dark:bg-primary-900/20' : ''">
                                <span><span class="font-mono text-xs text-neutral-400">{{ s.code }}</span> <span class="font-medium">{{ s.name }}</span><span v-if="s.category" class="ml-2 rounded bg-neutral-100 px-1.5 text-[10px] text-neutral-500 dark:bg-neutral-800">{{ s.category }}</span></span>
                                <ArrowRight class="h-4 w-4 text-neutral-300" />
                            </button>
                            <p v-if="!filteredSuppliers.length" class="px-2 py-6 text-center text-sm text-neutral-400">Tidak ada {{ jenis.penerima.toLowerCase() }} yang cocok.</p>
                        </div>
                    </div>
                </div>
            </div>
            <PesanWajib :pesan="pesan.pihak" />
            <!-- Deposit: rekening tujuan pengembalian diketik — master Customer tidak menyimpan data bank. -->
            <div v-if="jenis.kunci === 'deposit' && form.customer_id" class="mt-4 grid gap-3 rounded-lg border border-neutral-200 p-4 md:grid-cols-3 dark:border-neutral-700">
                <div class="md:col-span-3 text-sm">Customer: <span class="font-semibold">{{ supplier?.name }}</span> — isi rekening tujuan pengembalian deposit:</div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bank *</label><input v-model="form.bank_name" placeholder="mis. BCA" :class="tandai(inputCls, !!pesan.bank_name)" /><PesanWajib :pesan="pesan.bank_name" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Rekening *</label><input v-model="form.bank_account_no" :class="tandai(inputCls, !!pesan.bank_account_no)" /><PesanWajib :pesan="pesan.bank_account_no" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Atas Nama *</label><input v-model="form.bank_account_name" :class="tandai(inputCls, !!pesan.bank_account_name)" /><PesanWajib :pesan="pesan.bank_account_name" /></div>
            </div>
        </div>

        <!-- Step 2: Input Transaksi -->
        <div v-show="step === 2" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div class="md:col-span-3 rounded-lg bg-neutral-50 px-4 py-2 text-sm dark:bg-neutral-800">{{ jenis.penerima }}: <span class="font-semibold">{{ supplier?.name }}</span></div>
                <!--
                    Kategori Invoice bisa DIKETIK. Atribut `required` bawaan <select> memang ikut
                    hilang, tetapi tidak ada penjaga yang hilang: tombol "Berikutnya" sudah dikunci
                    canNext() selama category_id kosong, dan server memvalidasi
                    required|integer|exists:bs_ap_categories,id.
                -->
                <!-- Tipe Transaksi DITIADAKAN — server selalu menyimpan "Normal". Saldo awal hutang punya
                     menunya sendiri (Account Payable › Saldo Awal). -->
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Invoice *</label>
                    <div :class="pesan.category_id ? CINCIN_MERAH : ''">
                        <SearchSelect v-model="form.category_id" :options="kategoriOpsi" placeholder="— ketik atau pilih kategori —" />
                    </div>
                    <PesanWajib :pesan="pesan.category_id" />
                    <p v-if="category" class="mt-1 text-[11px] text-neutral-400">Dr {{ category.debit }} · Cr {{ category.payable }}</p>
                    <p v-else class="mt-1 text-[11px] text-neutral-400">Ketik nama atau kode kategori untuk mencarinya.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">{{ jenis.kunci === 'invoice' ? 'No. Invoice Supplier' : 'No. Bukti / Nota' }} *</label><input v-model="form.supplier_invoice_no" required :readonly="kunciNoInvoice" :class="[tandai(inputCls, !!pesan.supplier_invoice_no && !kunciNoInvoice), kunciNoInvoice ? 'cursor-not-allowed bg-neutral-100 text-neutral-500 dark:bg-neutral-800' : '']" /><p v-if="kunciNoInvoice" class="mt-1 text-[11px] text-neutral-500">🔒 {{ ketNoInvoice }}</p><PesanWajib v-else :pesan="pesan.supplier_invoice_no" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Input *</label><input v-model="form.invoice_date" type="date" disabled title="Otomatis tanggal input, tidak bisa diubah" class="h-9 w-full cursor-not-allowed rounded-lg border border-neutral-300 bg-neutral-100 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800" /><p class="mt-1 text-[10px] text-neutral-400">Otomatis tanggal input — tidak bisa diubah.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Terima</label><input v-model="form.receive_date" type="date" :class="tandai(inputCls, !!pesan.receive_date)" /><PesanWajib :pesan="pesan.receive_date" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo (hari) <span class="text-danger-500">*</span></label><input v-model.number="form.term_days" type="number" :min="form.memo_internal_id ? 0 : 1" required placeholder="mis. 30" :disabled="!!form.memo_internal_id" :class="[tandai(inputCls, !!pesan.term_days),'text-right', form.memo_internal_id ? 'cursor-not-allowed bg-neutral-100 dark:bg-neutral-800' : '']" /><PesanWajib :pesan="pesan.term_days" />
                    <p v-if="form.memo_internal_id" class="mt-1 text-[11px] text-primary-700 dark:text-primary-300">Memo Urgent — jatuh tempo hari itu juga.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Jatuh Tempo</label><input :value="dueDate" type="date" disabled title="Otomatis: Tgl. Terima + Jatuh Tempo (hari)" class="h-9 w-full cursor-not-allowed rounded-lg border border-neutral-300 bg-neutral-100 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800" /><p class="mt-1 text-[10px] text-neutral-400">Tgl. Terima + Jatuh Tempo (hari) — tidak bisa diubah.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.manual_no" :class="tandai(inputCls, !!pesan.manual_no)" /><PesanWajib :pesan="pesan.manual_no" /></div>
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="tandai(inputCls, !!pesan.description)" /><PesanWajib :pesan="pesan.description" /></div>
            </div>
            <!-- Memo Urgent berpajak → seluruh tahap 2 dikunci (fieldset disabled). -->
            <fieldset :disabled="kunciPajak" class="card grid gap-4 p-5 md:grid-cols-3" :class="kunciPajak ? 'bg-neutral-50/60 dark:bg-neutral-900/40' : ''">
                <p v-if="kunciPajak" class="md:col-span-3 -mb-1 rounded-lg bg-primary-50 px-3 py-2 text-xs text-primary-800 dark:bg-primary-900/20 dark:text-primary-200">🔒 Nilai Transaksi, PPN &amp; PPh dikunci — mengikuti rincian Memo Urgent {{ memoDipilih?.number }}. Lepas memonya di langkah 1 untuk mengubah.</p>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Transaksi *</label><div :class="pesan.dpp ? CINCIN_MERAH : ''"><MoneyInput v-model="form.dpp" placeholder="0" /></div><PesanWajib :pesan="pesan.dpp" />
                    <p v-if="memoDipilih && !kunciPajak && !pesan.dpp" class="mt-1 text-[11px] text-primary-700 dark:text-primary-300">{{ Number(form.dpp) === memoDipilih.amount ? 'Terisi dari' : 'Nominal' }} Memo Urgent {{ memoDipilih.number }}: {{ fmtRp(memoDipilih.amount) }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPN</label><div class="flex gap-2"><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="none" class="sr-only" />Tanpa PPN</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='with' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="with" class="sr-only" />Dengan PPN</label></div>
                    <div v-if="form.ppn_mode==='with'" class="mt-2 space-y-1.5">
                        <!-- #incl-ppn: Exclude = PPN di atas objek · Include = objek sudah termasuk PPN (÷ 1+tarif) -->
                        <div class="flex gap-1.5">
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1 text-[11px]" :class="!form.ppn_incl ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_incl" type="radio" :value="false" class="sr-only" />Exclude PPN</label>
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1 text-[11px]" :class="form.ppn_incl ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_incl" type="radio" :value="true" class="sr-only" />Include PPN</label>
                        </div>
                        <input v-model.number="form.ppn_pct" type="number" step="0.5" min="0" max="100" title="Tarif PPN %" class="h-8 w-full rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                        <!-- #140/#incl-ppn: objek PPN (Include → field DPP & objek PPN sudah di-ekstrak ke nilai NET via watch) -->
                        <MoneyInput v-model="form.ppn_base" placeholder="Nilai objek PPN" />
                        <p class="text-[10px] text-neutral-400">Objek PPN: {{ fmtRp(ppnBase) }} · Non-objek: {{ fmtRp(ppnNonObject) }}</p>
                    </div></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPh</label><div class="flex gap-1.5"><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="none" class="sr-only" />Tanpa PPh</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='withheld' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="withheld" class="sr-only" />Dipotong</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='borne' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="borne" class="sr-only" />Ditanggung</label></div>
                    <div v-if="form.pph_mode==='withheld' || form.pph_mode==='borne'" class="mt-2 space-y-1.5">
                        <!-- #94: jenis PPh → tarif default (bisa diubah manual).
                             Jenis & tarif BERDAMPINGAN, rasio lebarnya diatur lewat flex (3 : 1) —
                             min-w-0 wajib, kalau tidak lebar minimum bawaan select/input menahan
                             keduanya dan rasionya tidak pernah benar-benar berlaku pada kolom sempit.

                             Dropdown "Akun PPh" DIHILANGKAN: akunnya kini sepenuhnya ditentukan jenis
                             PPh — Hutang PPh <jenis> lewat pphDefaults dan Biaya PPh <jenis> lewat
                             pphBorneDefaults. Membiarkannya bisa dipilih sendiri hanya membuka peluang
                             akun yang tidak cocok dengan jenisnya, dan itu tidak sekadar salah muara:
                             keterangan baris di buku besar dibaca dari NAMA AKUN (pasalPph), sehingga
                             bisa tertulis "PPh 21" padahal jenisnya PPh 23. -->
                        <div class="flex gap-1">
                            <select v-model="form.pph_type" class="h-8 min-w-0 flex-[3] rounded-lg border border-neutral-300 px-1 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">— jenis PPh —</option>
                                <option value="pph21">PPh 21 (2,5%)</option>
                                <option value="pph23">PPh 23 (2%)</option>
                                <option value="pph4_2">PPh Pasal 4 Ayat 2 (1,75%)</option>
                            </select>
                            <input v-model.number="form.pph_pct" type="number" step="0.25" min="0" max="100" title="Tarif % (bisa diubah manual)" class="h-8 min-w-0 flex-1 rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                        </div>
                        <!-- #92: nilai objek PPh (default = Objek PPN bila Dengan PPN, selain itu DPP); PPh dihitung dari nilai ini saja -->
                        <MoneyInput v-model="form.pph_base" placeholder="Nilai objek PPh" />
                        <p class="text-[10px] text-neutral-400">Objek PPh: {{ fmtRp(pphBase) }} · Non-objek: {{ fmtRp(pphNonObject) }}</p>
                    </div></div>
                <div class="md:col-span-3 grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800 sm:grid-cols-4">
                    <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(dppNet) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                    <div><div class="text-xs text-neutral-400">{{ pphLabel }}</div><div class="font-mono" :class="pph > 0 ? 'text-danger-600' : 'text-neutral-500'">{{ fmtRp(pph) }}</div></div>
                    <div><div class="text-xs text-neutral-400">Dibayar (net)</div><div class="font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(net) }}</div></div>
                </div>
            </fieldset>

            <!-- Berkas Tagihan — WAJIB, di langkah Input Transaksi (unggah sendiri atau link supplier). -->
            <div v-if="wajibBerkas" id="blok-berkas" class="card scroll-mt-4 p-5">
                <label for="berkas-pilih" class="mb-1 block text-sm font-semibold">Berkas Tagihan <span class="text-danger-500">*</span></label>
                <p v-if="!form[kolomPihak]" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                    Pilih supplier dulu — Upload File &amp; Generate Link memakai supplier invoice ini.
                    <button type="button" class="ml-1 font-medium underline" @click="step = 1">Pilih supplier</button>
                </p>
                <!-- Dua jalan: unggah sendiri, atau link untuk supplier. Link yang sudah dibuat mengunci pilihan ini. -->
                <div class="mb-3 grid gap-2 sm:grid-cols-2">
                    <button type="button" :disabled="!!linkAktif" class="flex items-start gap-2 rounded-lg border p-3 text-left disabled:cursor-not-allowed disabled:opacity-50"
                        :class="modeBerkas === 'upload' ? 'border-primary-500 bg-primary-50/60 dark:bg-primary-900/20' : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        @click="modeBerkas = 'upload'">
                        <Upload class="mt-0.5 h-4 w-4 shrink-0 text-primary-600" />
                        <span><span class="block text-sm font-medium">Upload File</span><span class="block text-xs text-neutral-500">Unggah sendiri / pilih berkas Anda — TTD Dept Head diajukan di langkah Struktur.</span></span>
                    </button>
                    <button type="button" class="flex items-start gap-2 rounded-lg border p-3 text-left"
                        :class="modeBerkas === 'link' ? 'border-primary-500 bg-primary-50/60 dark:bg-primary-900/20' : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        @click="modeBerkas = 'link'">
                        <Link2 class="mt-0.5 h-4 w-4 shrink-0 text-primary-600" />
                        <span><span class="block text-sm font-medium">Generate Link</span><span class="block text-xs text-neutral-500">Link tanpa login untuk supplier mengunggah dokumennya sendiri.</span></span>
                    </button>
                </div>

                <!-- GENERATE LINK -->
                <div v-if="modeBerkas === 'link'" class="mb-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <template v-if="!linkAktif">
                        <p class="text-sm">Sistem membuat link untuk <b>{{ supplier?.name ?? 'supplier' }}</b>. Nomor invoice langsung dipesan (belum aktif ke mana-mana) dan isian ini tersimpan sebagai draft. Anda tidak bisa lanjut dari langkah ini sampai supplier mengunggah dokumennya.</p>
                        <Button class="mt-3" :disabled="memprosesLink" @click="buatLink"><template #icon-left><Link2 class="h-4 w-4" /></template>{{ memprosesLink ? 'Membuat…' : 'Generate Link' }}</Button>
                    </template>
                    <template v-else>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-xs text-neutral-500">No. invoice dipesan <span class="font-mono font-semibold text-neutral-800 dark:text-neutral-100">{{ linkAktif.invoice_number }}</span></div>
                            <span v-if="linkAktif.menerima === false" class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">Link nonaktif — berkas sudah di-TTD</span>
                            <span v-else :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', linkAktif.status === 'terisi' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300']">
                                {{ linkAktif.status === 'terisi' ? 'Supplier sudah mengunggah' : 'Menunggu supplier' }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <input :value="linkAktif.url" readonly class="h-9 min-w-0 flex-1 rounded-lg border border-neutral-300 bg-neutral-50 px-3 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-800" @focus="($event.target as HTMLInputElement).select()" />
                            <button type="button" class="inline-flex h-9 items-center gap-1 rounded-lg border border-neutral-300 px-3 text-xs hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="salinLink"><Copy class="h-3.5 w-3.5" />{{ tersalin ? 'Tersalin' : 'Salin' }}</button>
                            <div class="relative">
                                <button type="button" class="inline-flex h-9 items-center gap-1 rounded-lg border border-primary-300 px-3 text-xs font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-800 dark:text-primary-300 dark:hover:bg-primary-950/40" @click="bagikanLink"><Share2 class="h-3.5 w-3.5" />Share</button>
                                <div v-if="menuBagikan" class="absolute right-0 z-20 mt-1 w-48 overflow-hidden rounded-lg border border-neutral-200 bg-white text-xs shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                                    <a :href="urlWhatsApp" target="_blank" rel="noopener" class="block px-3 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-800" @click="menuBagikan = false">WhatsApp</a>
                                    <a :href="urlEmail" class="block px-3 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-800" @click="menuBagikan = false">Email</a>
                                    <button type="button" class="block w-full px-3 py-2 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800" @click="salinPesan">Salin pesan + link</button>
                                </div>
                            </div>
                            <a :href="linkAktif.url" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-lg border border-neutral-300 px-3 text-xs hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Buka</a>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                            <button type="button" class="inline-flex items-center gap-1 text-primary-600 hover:underline disabled:opacity-50" :disabled="memprosesLink" @click="cekLink"><RefreshCw class="h-3.5 w-3.5" /> Cek status</button>
                            <span v-if="linkAktif.status === 'menunggu'" class="text-neutral-400">dicek otomatis tiap 15 detik · Anda juga dapat notifikasi saat supplier mengunggah</span>
                            <span v-else-if="linkAktif.berkas_number" class="text-neutral-500">Berkas {{ linkAktif.berkas_number }}<template v-if="linkAktif.filled_at"> · terakhir diunggah {{ new Date(linkAktif.filled_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) }}</template></span>
                            <button v-if="linkAktif.status === 'menunggu'" type="button" class="ml-auto text-danger-500 hover:underline" @click="batalLink">Batalkan link</button>
                        </div>
                    </template>
                    <!-- Link tetap bisa dipakai supplier mengunggah ULANG sampai invoice disimpan; tiap unggahan tercatat. -->
                    <details v-if="(linkAktif?.riwayat?.length ?? 0) > 0" class="mt-2 text-xs">
                        <summary class="cursor-pointer text-neutral-500 hover:text-primary-600">Riwayat unggahan supplier ({{ linkAktif!.riwayat!.length }}) — {{ linkAktif!.menerima === false ? 'link sudah nonaktif' : 'link masih bisa dipakai mengunggah ulang' }}</summary>
                        <ul class="mt-1 space-y-0.5 pl-4">
                            <li v-for="(r, i) in linkAktif!.riwayat" :key="i" :class="i === 0 ? 'font-medium' : 'text-neutral-400 line-through decoration-neutral-300'">
                                {{ new Date(r.at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) }} · {{ r.berkas_number }} · {{ r.file }} file<template v-if="r.no_invoice"> · No. {{ r.no_invoice }}</template>{{ i === 0 ? ' (aktif)' : ' (diganti)' }}
                            </li>
                        </ul>
                    </details>
                    <p v-if="pesanLink" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">{{ pesanLink }}</p>
                    <p v-if="galatLink" class="mt-2 text-xs text-danger-500">{{ galatLink }}</p>
                </div>

                <template v-if="modeBerkas === 'upload'">
                <!-- Unggah di sini: jadi berkas Anda (supplier, no. tagihan, nilai & keterangan dari isian ini) + diajukan ke TTD Dept Head.
                     Mengunggah lagi = mengganti berkas invoice ini. -->
                <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="mb-2 text-xs font-medium text-neutral-500">{{ berkasDipilih ? 'Ganti dengan file baru' : 'Unggah file tagihan' }}</div>
                    <DropBerkas v-model="unggahan" :jenis="jenisBerkas ?? []" />
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <span v-if="unggahan.length && kurangWajib.length" class="text-xs text-amber-600">Belum ada: {{ kurangWajib.join(', ') }}</span>
                        <Button size="sm" :disabled="!bisaUnggah" @click="unggahBerkas"><template #icon-left><Upload class="h-4 w-4" /></template>{{ mengunggah ? 'Mengunggah…' : 'Upload' }}</Button>
                        <span v-if="galatUnggah" class="text-xs text-danger-500">{{ galatUnggah }}</span>
                    </div>
                    <PesanWajib v-if="!berkasDipilih" :pesan="pesan.berkas" />
                </div>
                </template>

                <!-- Rincian berkas terpilih + semua filenya -->
                <div v-if="berkasDipilih" class="mt-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-semibold">{{ berkasDipilih.number }}</p>
                            <p class="truncate text-sm text-neutral-600 dark:text-neutral-300">{{ berkasDipilih.description }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', berkasDipilih.bisa_diinvoice ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300']">{{ berkasDipilih.status_label }}</span>
                        </div>
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                        <div><dt class="text-neutral-400">Supplier</dt><dd>{{ berkasDipilih.supplier ?? '—' }}</dd></div>
                        <div><dt class="text-neutral-400">No. tagihan</dt><dd>{{ berkasDipilih.supplier_invoice_no ?? '—' }}</dd></div>
                        <div><dt class="text-neutral-400">Total tagihan</dt><dd class="font-mono">{{ berkasDipilih.amount != null ? fmtRp(berkasDipilih.amount) : '—' }}</dd></div>
                        <div><dt class="text-neutral-400">Sisa belum diinvoice</dt><dd class="font-mono">{{ berkasDipilih.sisa != null ? fmtRp(berkasDipilih.sisa) : '—' }}</dd></div>
                    </dl>
                    <p v-if="supplierBeda" class="mt-2 text-xs text-amber-600">Supplier berkas ({{ berkasDipilih.supplier }}) berbeda dengan supplier invoice ini — pastikan berkasnya benar.</p>
                    <!-- Link nonaktif (berkas sudah di-TTD penuh): pembuat invoice masih bisa menambah file ke berkas ini. -->
                    <div v-if="modeBerkas === 'link' && linkAktif?.menerima === false" class="mt-3 rounded-lg border border-dashed border-neutral-300 p-3 dark:border-neutral-700">
                        <div class="mb-2 text-xs font-medium text-neutral-500">Tambah file ke berkas ini <span class="font-normal text-neutral-400">— link supplier sudah nonaktif; file lama &amp; TTD tidak berubah</span></div>
                        <DropBerkas v-model="tambahan" :jenis="jenisBerkas ?? []" :maks="Math.max(0, 10 - berkasDipilih.files.length)" />
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <Button size="sm" :disabled="!bisaTambah" @click="tambahFile"><template #icon-left><Upload class="h-4 w-4" /></template>{{ menambah ? 'Menambahkan…' : 'Tambah File' }}</Button>
                            <span v-if="galatTambah" class="text-xs text-danger-500">{{ galatTambah }}</span>
                        </div>
                    </div>
                    <p v-if="berkasBelumSiap && perluTtd" class="mt-2 text-xs text-amber-600">Berkas ini belum di-TTD Dept Head. Lanjutkan isian sampai langkah Struktur, lalu tekan "Ajukan ke Dept Head".</p>
                    <p v-else-if="berkasBelumSiap" class="mt-2 text-xs text-neutral-500">Anda Dept Head — berkas ini ditandatangani otomatis atas nama Anda saat invoice disimpan.</p>

                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-xs font-medium text-neutral-500">File berkas ({{ berkasDipilih.files.length }})</span>
                        <button v-if="berkasDipilih.files.length > 1" type="button" :disabled="menggabung" title="Gabungkan semua file jadi satu PDF"
                            class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700 transition hover:bg-primary-100 disabled:opacity-60 dark:bg-primary-950/40 dark:text-primary-300"
                            @click="lihatSemua"><Files class="h-3 w-3" />{{ menggabung ? 'Menggabung…' : 'Lihat semua' }}</button>
                    </div>
                    <p v-if="galatGabung" class="mt-1 text-xs text-danger-500">{{ galatGabung }}</p>
                    <ul class="mt-1 divide-y divide-neutral-100 text-sm dark:divide-neutral-800">
                        <li v-for="f in berkasDipilih.files" :key="f.id" class="flex items-center justify-between gap-2 py-1.5">
                            <span class="flex min-w-0 items-center gap-2"><FileText class="h-4 w-4 shrink-0 text-neutral-400" /><span class="shrink-0 text-xs font-medium text-neutral-500">{{ f.jenis ?? 'Jenis lain' }}</span><span class="truncate">{{ f.name }}</span></span>
                            <a :href="f.url" target="_blank" rel="noopener" class="shrink-0 text-xs text-primary-600 hover:underline">Buka</a>
                        </li>
                        <li v-if="!berkasDipilih.files.length" class="py-1.5 text-xs text-neutral-400">Belum ada file.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Step 3: Detil Tagihan -->
        <div v-show="step === 3" class="card p-5">
            <div class="mb-2 flex items-center justify-between"><h2 class="font-semibold">Detil Tagihan</h2><button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button></div>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-1">Uraian</th><th class="w-20 py-1 text-right">Qty</th><th class="w-32 py-1 text-right">Harga</th><th class="w-36 py-1 text-right">Jumlah</th><th class="w-8"></th></tr></thead>
                <tbody>
                    <tr v-for="(l,i) in form.lines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                        <td class="py-1 pr-2"><input v-model="l.description" :class="tandai(inputCls, !!pesanUraian(i))" /><PesanWajib :pesan="pesanUraian(i)" /></td>
                        <td class="py-1 pr-2"><input v-model.number="l.qty" type="number" step="0.01" min="0" :class="[inputCls,'text-right']" @input="l.amount = (Number(l.qty)||0)*(Number(l.unit_price)||0)" /></td>
                        <td class="py-1 pr-2"><input v-model.number="l.unit_price" type="number" step="1" min="0" :class="[inputCls,'text-right']" @input="l.amount = (Number(l.qty)||0)*(Number(l.unit_price)||0)" /></td>
                        <td class="py-1 pr-2"><input v-model.number="l.amount" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></td>
                        <td class="py-1 text-center"><button type="button" @click="removeLine(i)" class="text-danger-500"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!form.lines.length" class="py-4 text-center text-sm text-neutral-400">Opsional — rincian tagihan. Total DPP {{ fmtRp(form.dpp) }} bisa dipecah ke baris.</p>
            <div v-else class="mt-2 flex justify-end gap-6 text-sm"><span class="text-neutral-500">Total baris: <b class="font-mono">{{ fmtRp(linesSum) }}</b></span><span :class="barisTakSamaDpp ? 'text-danger-600' : 'text-success-600'">DPP: {{ fmtRp(dppNet) }}</span></div>
            <p v-if="barisTakSamaDpp" class="mt-1 text-right text-xs text-danger-600">Total baris harus sama dengan DPP — selisih {{ fmtRp(linesSum - dppNet) }}. Betulkan baris sebelum lanjut.</p>
        </div>

        <!-- Step 4: Struktur (Jurnal Dr/Cr) -->
        <div v-show="step === 4" class="space-y-4">
            <!-- Struktur Jurnal — tabel padat (mirip layout eProperty BO) -->
            <div class="card p-5">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Struktur Jurnal (Dr / Cr)</h2>
                    <div class="flex items-center gap-2">
                        <!-- Isi papan klip akun — supaya jelas apa yang akan tertempel. -->
                        <span v-if="copiedAccountLabel" class="inline-flex max-w-[22rem] items-center gap-1.5 truncate rounded-lg border border-sky-300 bg-sky-50 px-2.5 py-1.5 text-xs font-semibold text-sky-800 dark:border-sky-800 dark:bg-sky-950/50 dark:text-sky-200" :title="copiedAccountLabel">
                            <Copy class="h-3.5 w-3.5 shrink-0" /> <span class="shrink-0 font-normal text-sky-600 dark:text-sky-400">Tersalin:</span> <span class="truncate">{{ copiedAccountLabel }}</span>
                        </span>
                    </div>
                </div>
                <p class="mb-2 text-[11px] text-neutral-400">Tips: Kosongkan kolom Account &amp; Nilai Transaksi untuk mengabaikan baris tersebut saat proses. Total Debit harus sama dengan Total Kredit. Tombol <b>salin</b> &amp; <b>tempel</b> kecil di bawah kolom Account dipakai untuk memakai ulang akun yang sama di baris lain.</p>
                <!-- Struktur yang sudah disunting tangan TIDAK ditimpa saat PPN/PPh diubah — tetapi
                     selisihnya harus terbaca, kalau tidak jurnalnya tetap balance sambil melewatkan
                     baris PPN/PPh tanpa satu pun tanda. -->
                <p v-if="strukturUsang" class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    Struktur ini sudah disunting tangan sehingga tidak lagi mengikuti PPN/PPh pada Input Transaksi.
                    Untuk menyusunnya ulang dari nilai transaksinya: hapus seluruh barisnya, lalu tekan
                    <b>Sebelumnya</b> dan <b>Selanjutnya</b> lagi ke langkah ini.
                </p>
                <!-- Akun yang dibutuhkan aturan PPN/PPh tetapi belum ada: barisnya sengaja tidak
                     dibuat tanpa akun, jadi ketiadaannya disebutkan di sini. -->
                <p v-if="akunStrukturKurang.length" class="mb-2 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:bg-danger-900/20 dark:text-danger-300">
                    Akun {{ akunStrukturKurang.join(' & ') }} belum tersedia — barisnya tidak dibentuk. Lengkapi akunnya dulu, atau tambahkan barisnya sendiri di bawah.
                </p>
                <div class="rounded-lg border border-neutral-200 dark:border-neutral-800">
                    <table class="w-full table-fixed border-collapse text-sm">
                        <colgroup>
                            <!-- Kolom Account dilebarkan: memuat pemilih akun + tombol Salin/Tempel berlabel.
                                 SEMBILAN kolom (termasuk Sisa Budget), sama dengan sel tiap baris — jumlah
                                 kolom yang berbeda menggeser seluruh angka Grand Total satu kolom. -->
                            <col style="width:3%" /><col style="width:22%" /><col style="width:10%" /><col style="width:13%" /><col style="width:12%" /><col style="width:12%" /><col style="width:12%" /><col style="width:13%" /><col style="width:3%" />
                        </colgroup>
                        <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                            <tr>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">No</th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Account</th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Cost Center <span class="text-danger-500">*</span></th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Keterangan</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Nilai Transaksi</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Debit</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Kredit</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700" :title="'Anggaran ' + (bulanBudget || 'bulan ini') + ' − realisasi bulan ini − nilai transaksi'">Sisa Budget<span v-if="bulanBudget" class="block text-[10px] font-normal normal-case text-neutral-400">{{ bulanBudget }}</span></th>
                                <th class="border-b border-neutral-200 px-1 py-2 dark:border-neutral-700"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l,i) in form.journal_lines" :key="i" class="border-b border-neutral-100 align-top dark:border-neutral-800">
                                <td class="px-2 py-2 font-mono text-neutral-400">{{ i+1 }}</td>
                                <td class="px-2 py-2">
                                    <!-- Nama akun ditampilkan penuh (turun baris), panel pilihan dilebarkan supaya nama COA terbaca. -->
                                    <SearchSelect :model-value="l.account_id" :options="accounts" placeholder="— pilih akun —" :no-truncate="true" :panel-min="460" @update:model-value="(v) => pilihAkun(l, v)" />
                                    <!-- Salin / tempel akun antar baris — akun kerap berulang.
                                         Tombolnya BERLABEL & berwarna: versi ikon 20px sebelumnya
                                         nyaris tak terbaca dan mudah terlewat operator. -->
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <button type="button" @click="copyAccount(l, i)" :disabled="!l.account_id"
                                            :title="l.account_id ? 'Salin akun: ' + accountLabel(l.account_id) : 'Pilih akun dulu'"
                                            class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-[11px] font-semibold transition-colors duration-200 disabled:cursor-not-allowed disabled:border-neutral-200 disabled:bg-neutral-50 disabled:text-neutral-300 dark:disabled:border-neutral-800 dark:disabled:bg-neutral-900 dark:disabled:text-neutral-600"
                                            :class="copyFlash === i
                                                ? 'border-success-500 bg-success-500 text-white'
                                                : 'border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-900/50'">
                                            <Check v-if="copyFlash === i" class="h-3.5 w-3.5" /><Copy v-else class="h-3.5 w-3.5" />
                                            {{ copyFlash === i ? 'Tersalin' : 'Salin' }}
                                        </button>
                                        <button type="button" @click="pasteAccount(l, i)"
                                            :title="copiedAccountLabel ? 'Tempel akun: ' + copiedAccountLabel : 'Tempel akun dari papan klip'"
                                            class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-[11px] font-semibold transition-colors duration-200"
                                            :class="pasteFlash === i
                                                ? 'border-success-500 bg-success-500 text-white'
                                                : 'border-primary-300 bg-primary-50 text-primary-700 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/50'">
                                            <Check v-if="pasteFlash === i" class="h-3.5 w-3.5" /><ClipboardPaste v-else class="h-3.5 w-3.5" />
                                            {{ pasteFlash === i ? 'Tertempel' : 'Tempel' }}
                                        </button>
                                        <span class="min-w-0 flex-1 break-words text-[11px] text-neutral-500 dark:text-neutral-400" :title="accountName(l.account_id)">{{ accountName(l.account_id) || '—' }}</span>
                                    </div>
                                </td>
                                <!-- Cost center WAJIB, kecuali akun yang memang boleh tanpanya
                                     (mis. Utang Usaha Supplier — lihat ccOptional). Barisnya
                                     ditandai merah selagi kosong, jadi baris mana yang kurang
                                     terlihat langsung tanpa perlu membaca pesan di bawah. -->
                                <td class="px-2 py-2">
                                    <div :class="ccKurang(l) ? 'rounded-lg ring-1 ring-danger-400' : ''">
                                        <SearchSelect v-model="l.cost_center_id" :options="ccOpsiBaris(l.account_id)"
                                            :disabled="! bisaUbahCc"
                                            :placeholder="ccOptional(l.account_id) ? '— tanpa cost center —' : '— cost center —'" />
                                    </div>
                                    <p v-if="ccKurang(l)" class="mt-0.5 text-[10.5px] font-medium text-danger-500">Wajib diisi</p>
                                    <p v-else-if="l.account_id && ccOptional(l.account_id)" class="mt-0.5 text-[10.5px] text-neutral-400">Boleh dikosongkan</p>
                                    <!-- Dikatakan kenapa terkunci. Isian mati tanpa keterangan
                                         membuat orang mengira layarnya rusak. -->
                                    <p v-else-if="! bisaUbahCc" class="mt-0.5 text-[10.5px] text-neutral-400">Mengikuti unit kerja Anda</p>
                                </td>
                                <td class="px-2 py-2"><textarea v-model="l.description" rows="2" :class="cellArea" placeholder="Keterangan baris…"></textarea></td>
                                <!-- MoneyInput, BUKAN input type=number. Peramban tidak pernah mau menampilkan
                                     pemisah ribuan pada type=number: isinya wajib angka mentah yang sah, dan
                                     "1.000.000" bukan salah satunya — jadi selama masih type=number, tanda ribuan
                                     mustahil. MoneyInput juga yang sudah dipakai Nilai Transaksi di langkah Input
                                     Transaksi, sehingga kolom ini justru yang selama ini berbeda sendiri.

                                     AMAN DARI DESIMAL: parse MoneyInput membuang semua non-digit, sehingga nilai
                                     berpecahan akan terbaca 100x lipat ("135.802,37" → 13580237). Itu tidak bisa
                                     terjadi di sini — setiap nilai jurnal sudah dibulatkan ke rupiah penuh oleh
                                     r0/fl0, dan journal_lines selalu mulai kosong (form ini tidak pernah dimuati
                                     invoice lama).

                                     Kelas ber-awalan ! menyetel ulang tinggi & ukuran huruf bawaan MoneyInput agar
                                     sepadan dengan sel tabel padat ini — pola yang sama dipakai CashBook/Ledger.vue. -->
                                <td class="px-2 py-2">
                                    <MoneyInput :model-value="l.nilai" @update:model-value="(v) => { l.nilai = v; applyAmount(l); }"
                                        class="!h-8 !rounded-md !px-2 !text-xs font-mono" />
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <input type="radio" :name="'side-row-' + i" :checked="l.side==='debit'" @change="setSide(l,'debit')" class="h-4 w-4 accent-primary-600" />
                                    <div class="mt-1 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ l.side==='debit' && Number(l.debit) ? fmtNum(l.debit) : '' }}</div>
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <input type="radio" :name="'side-row-' + i" :checked="l.side==='credit'" @change="setSide(l,'credit')" class="h-4 w-4 accent-primary-600" />
                                    <div class="mt-1 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ l.side==='credit' && Number(l.credit) ? fmtNum(l.credit) : '' }}</div>
                                </td>
                                <!-- Sisa budget bulan ini; minus → tombol Koreksi Budget. -->
                                <td class="px-2 py-2 text-right">
                                    <template v-if="sisaBaris(l) === null"><span class="text-[11px] text-neutral-400" title="Akun ini tidak punya anggaran">—</span></template>
                                    <template v-else-if="sisaBaris(l) !== undefined">
                                        <div :class="['font-mono text-xs', (sisaBaris(l) as number) < 0 ? 'font-semibold text-danger-600' : 'text-success-700 dark:text-success-400']">{{ fmtNum(sisaBaris(l) as number) }}</div>
                                        <div v-if="sisaInfo[kunciBudget(l)]?.baru" class="text-[10px] text-danger-500">Belum ada budget (dianggap 0)</div>
                                        <!-- Alokasi / Koreksi: wajib bila minus, opsional bila masih cukup (baris debit ber-anggaran). -->
                                        <template v-if="l.side === 'debit'">
                                            <!-- Pilihan bisa diganti / dilepas selama invoice belum disimpan (diajukan). -->
                                            <div class="mt-1 flex justify-end gap-1" :class="pilihanBudget(l) || (sisaBaris(l) as number) < 0 ? '' : 'opacity-70 hover:opacity-100'">
                                                <button type="button" :class="['inline-flex h-6 items-center gap-0.5 rounded-md border px-2 text-[10.5px] font-semibold', pilihanBudget(l) === 'alokasi' ? 'border-sky-600 bg-sky-600 text-white' : 'border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300']"
                                                    :title="pilihanBudget(l) === 'alokasi' ? 'Klik untuk melepas alokasi' : 'Ambil dari sisa bulan ini di budget lain — menunggu Approval Alokasi Budget'" @click="pilihBudget(l, 'alokasi')"><Check v-if="pilihanBudget(l) === 'alokasi'" class="h-3 w-3" />Alokasi</button>
                                                <button type="button" :class="['inline-flex h-6 items-center gap-0.5 rounded-md border px-2 text-[10.5px] font-semibold', pilihanBudget(l) === 'koreksi' ? 'border-danger-600 bg-danger-600 text-white' : 'border-danger-300 bg-danger-50 text-danger-700 hover:bg-danger-100 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300']"
                                                    :title="pilihanBudget(l) === 'koreksi' ? 'Klik untuk melepas koreksi' : 'Tambah anggaran bulan ini — lewat Approval Inbox'" @click="pilihBudget(l, 'koreksi')"><Check v-if="pilihanBudget(l) === 'koreksi'" class="h-3 w-3" />Koreksi</button>
                                            </div>
                                            <div v-if="pilihanBudget(l)" class="mt-0.5 text-[10px] text-neutral-400">{{ pilihanBudget(l) === 'alokasi' ? 'Dialokasikan' : 'Koreksi diajukan bersama invoice' }} · klik untuk ganti / lepas</div>
                                        </template>
                                    </template>
                                </td>
                                <td class="px-1 py-2 text-center"><button type="button" @click="removeJournalLine(i)" class="text-danger-500"><Trash2 class="h-4 w-4" /></button></td>
                            </tr>
                            <tr v-if="!form.journal_lines.length"><td colspan="9" class="px-2 py-6 text-center text-sm text-neutral-400">Belum ada baris. Tekan "Sebelumnya" lalu "Selanjutnya" lagi ke langkah ini untuk membentuk jurnal otomatis dari nilai transaksinya, atau klik "Tambah 1 Baris".</td></tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                                <!-- 4 kolom (No·Account·Cost Center·Keterangan) — tiap total tepat di bawah kolomnya. -->
                                <td colspan="4" class="px-2 py-2 text-right text-neutral-500">Grand Total</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtNum(jurnalNilai) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtNum(jurnalDebit) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtNum(jurnalKredit) }}</td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex gap-4 text-xs font-medium">
                        <button type="button" @click="addJournalLine" class="inline-flex items-center gap-1 text-primary-600 hover:underline"><Plus class="h-3.5 w-3.5" /> Tambah 1 Baris</button>
                        <button type="button" @click="addJournalLines(10)" class="inline-flex items-center gap-1 text-primary-600 hover:underline"><Plus class="h-3.5 w-3.5" /> Tambah 10 Baris</button>
                    </div>
                    <span v-if="jurnalBalanced" class="inline-flex items-center gap-1 rounded-full bg-success-50 px-3 py-1 text-xs font-medium text-success-700 dark:bg-success-900/30 dark:text-success-300"><Check class="h-3.5 w-3.5" /> Balance</span>
                    <span v-else class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Selisih {{ fmtRp(Math.abs(jurnalDebit - jurnalKredit)) }} — Debit ≠ Kredit</span>
                </div>

                <!-- Sisa Budget Tahunan & Bulanan — dari Master Budget (anggaran berlaku − realisasi), sebelum & sesudah invoice ini. -->
                <div v-if="ringkasanBudget.length" class="mt-4">
                    <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold">Sisa Budget</h3>
                        <span class="text-[11px] text-neutral-400">Master Budget · tahunan {{ String(form.invoice_date ?? '').slice(0, 4) }} &amp; bulanan {{ bulanBudget }} · sebelum → sesudah invoice ini</span>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <table class="w-full text-xs">
                            <thead class="bg-neutral-50 text-[10px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800/60">
                                <tr>
                                    <th rowspan="2" class="border-b border-neutral-200 px-2 py-2 text-left dark:border-neutral-700">Anggaran (akun · cost center)</th>
                                    <th rowspan="2" class="border-b border-l border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Invoice ini</th>
                                    <th colspan="4" class="border-l border-neutral-200 px-2 pt-2 text-center dark:border-neutral-700">Tahunan</th>
                                    <th colspan="4" class="border-l border-neutral-200 px-2 pt-2 text-center dark:border-neutral-700">Bulanan</th>
                                </tr>
                                <tr>
                                    <template v-for="g in 2" :key="g">
                                        <th class="border-b border-l border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Anggaran</th>
                                        <th class="border-b border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Realisasi</th>
                                        <th class="border-b border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Sisa sebelum</th>
                                        <th class="border-b border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Sisa sesudah</th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in ringkasanBudget" :key="b.budget_id" class="border-b border-neutral-100 last:border-0 dark:border-neutral-800">
                                    <td class="px-2 py-2">{{ b.label }}</td>
                                    <td class="border-l border-neutral-200 px-2 py-2 text-right font-mono dark:border-neutral-700">{{ b.invoice ? '− ' + fmtNum(b.invoice) : '0' }}</td>
                                    <template v-for="p in [b.tahun, b.bulan]" :key="p === b.tahun ? 't' : 'b'">
                                        <td class="border-l border-neutral-200 px-2 py-2 text-right font-mono text-neutral-500 dark:border-neutral-700">{{ fmtNum(p.anggaran) }}</td>
                                        <td class="px-2 py-2 text-right font-mono text-neutral-500">{{ fmtNum(p.realisasi) }}</td>
                                        <td :class="['px-2 py-2 text-right font-mono', p.sebelum < 0 ? 'text-danger-600' : '']">{{ fmtNum(p.sebelum) }}</td>
                                        <td :class="['px-2 py-2 text-right font-mono font-semibold', p.sesudah < 0 ? 'text-danger-600' : 'text-success-700 dark:text-success-400']">{{ fmtNum(p.sesudah) }}</td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-1 text-[11px] text-neutral-400">Penjaga budget memakai sisa <b>bulanan</b> — sisa bulanan minus wajib diselesaikan dengan Alokasi atau Koreksi Budget.</p>
                </div>
            </div>

            <!-- Koreksi Budget — baris yang melewati sisa budget bulan ini -->
            <div v-if="form.koreksi.length && step === 4" class="card p-5">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Koreksi Budget</h2>
                    <span class="text-[11px] text-neutral-400">{{ bulanBudget }}</span>
                </div>
                <p v-if="butuhPengajuan" class="mb-2 text-[11px] text-neutral-500">Invoice dari Memo Urgent: klik "Selanjutnya" lalu ajukan approval (alur invoice) — setelah disetujui, nilainya ditambahkan ke budget bulan ini dan invoice bisa diselesaikan.</p>
                <p v-else class="mb-2 text-[11px] text-neutral-500">Ikut approval invoice ini saat disimpan (Approval Inbox) — diputuskan bersama invoicenya; invoice tetap berjalan.</p>
                <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                            <tr>
                                <th class="w-10 px-2 py-2">No</th><th class="px-2 py-2">Account</th><th class="px-2 py-2">Cost Center</th>
                                <th class="px-2 py-2">Keterangan</th><th class="w-44 px-2 py-2 text-right">Nilai Pengajuan</th><th v-if="ubahKoreksi" class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(k, i) in form.koreksi" :key="k.budget_id" class="border-t border-neutral-100 align-top dark:border-neutral-800">
                                <td class="px-2 py-2 font-mono text-neutral-400">{{ i + 1 }}</td>
                                <td class="px-2 py-2 text-xs">{{ accountLabel(k.account_id) }}</td>
                                <td class="px-2 py-2 text-xs">{{ labelCc(k.cost_center_id) }}</td>
                                <td class="px-2 py-2 text-xs">
                                    <textarea v-if="ubahKoreksi" v-model="k.keterangan" rows="2" :class="cellArea" maxlength="200" placeholder="Alasan koreksi…"></textarea>
                                    <span v-else>{{ k.keterangan || '—' }}</span>
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <template v-if="ubahKoreksi">
                                        <MoneyInput :model-value="k.nilai" @update:model-value="(v) => (k.nilai = v)" :class="['!h-8 !rounded-md !px-2 !text-xs font-mono', (Number(k.nilai) || 0) < kurangKoreksi(k) ? CINCIN_MERAH : '']" />
                                        <p :class="['mt-0.5 text-[10.5px]', (Number(k.nilai) || 0) < kurangKoreksi(k) ? 'text-danger-500' : 'text-neutral-400']">{{ kurangKoreksi(k) > 0 ? 'Minimal ' + fmtRp(kurangKoreksi(k)) + ' (kekurangan)' : 'Opsional — anggaran masih cukup' }}</p>
                                    </template>
                                    <span v-else class="font-mono text-xs">{{ fmtRp(k.nilai) }}</span>
                                </td>
                                <td v-if="ubahKoreksi" class="px-1 py-2 text-center"><button type="button" class="text-danger-500" title="Batalkan pengajuan koreksi" @click="hapusKoreksi(i)"><Trash2 class="h-4 w-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Alokasi Budget — kekurangan diambil dari sisa bulan ini di budget lain -->
            <div v-if="form.alokasi.length && step === 4" class="card p-5">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Alokasi Budget</h2>
                    <span class="text-[11px] text-neutral-400">{{ bulanBudget }}</span>
                </div>
                <p v-if="butuhPengajuan" class="mb-2 text-[11px] text-neutral-500">Invoice dari Memo Urgent: klik "Selanjutnya" lalu ajukan approval (alur invoice) — anggaran {{ bulanBudget || 'bulan ini' }} dipindah dari budget asal setelah disetujui, lalu invoice bisa diselesaikan.</p>
                <p v-else class="mb-2 text-[11px] text-neutral-500">Ikut approval invoice ini saat disimpan (Approval Inbox) — anggaran {{ bulanBudget || 'bulan ini' }} dipindah setelah invoice disetujui penuh; invoice tetap berjalan.</p>
                <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                            <tr>
                                <th class="w-10 px-2 py-2">No</th><th class="px-2 py-2">Budget Tujuan</th><th class="px-2 py-2">Ambil Dari (Budget Asal)</th>
                                <th class="px-2 py-2">Keterangan</th><th class="w-44 px-2 py-2 text-right">Nilai Alokasi</th><th v-if="ubahKoreksi" class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, i) in form.alokasi" :key="a.budget_id" class="border-t border-neutral-100 align-top dark:border-neutral-800">
                                <td class="px-2 py-2 font-mono text-neutral-400">{{ i + 1 }}</td>
                                <td class="px-2 py-2 text-xs">{{ accountLabel(a.account_id) }}<div class="text-neutral-400">{{ labelCc(a.cost_center_id) }}</div></td>
                                <td class="min-w-[260px] px-2 py-2 text-xs">
                                    <template v-if="ubahKoreksi">
                                        <SearchSelect v-model="a.dari_budget_id" :options="opsiSumber(a)" placeholder="— pilih budget asal —" :no-truncate="true" :class="!a.dari_budget_id ? CINCIN_MERAH : ''" />
                                        <p v-if="!sumberAlokasi.length" class="mt-0.5 text-[10.5px] text-neutral-400">Tidak ada budget lain yang masih bersisa di {{ bulanBudget }}.</p>
                                        <p v-else-if="sisaSumber(a) !== null" class="mt-0.5 text-[10.5px] text-neutral-500">Sisa asal {{ fmtRp(sisaSumber(a) as number) }} → setelah alokasi {{ fmtRp((sisaSumber(a) as number) - (Number(a.nilai) || 0)) }}</p>
                                    </template>
                                    <span v-else>{{ sumberAlokasi.find((b) => b.id === a.dari_budget_id)?.label ?? '—' }}</span>
                                </td>
                                <td class="px-2 py-2 text-xs">
                                    <textarea v-if="ubahKoreksi" v-model="a.keterangan" rows="2" :class="cellArea" maxlength="200" placeholder="Alasan alokasi…"></textarea>
                                    <span v-else>{{ a.keterangan || '—' }}</span>
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <template v-if="ubahKoreksi">
                                        <MoneyInput :model-value="a.nilai" @update:model-value="(v) => (a.nilai = v)" :class="['!h-8 !rounded-md !px-2 !text-xs font-mono', alokasiSalah(a) && a.dari_budget_id ? CINCIN_MERAH : '']" />
                                        <p :class="['mt-0.5 text-[10.5px]', (Number(a.nilai) || 0) < kurangKoreksi(a) || (sisaSumber(a) !== null && (Number(a.nilai) || 0) > (sisaSumber(a) as number)) ? 'text-danger-500' : 'text-neutral-400']">{{ kurangKoreksi(a) > 0 ? 'Minimal ' + fmtRp(kurangKoreksi(a)) : 'Opsional' }}<template v-if="sisaSumber(a) !== null"> · maks {{ fmtRp(sisaSumber(a) as number) }}</template></p>
                                    </template>
                                    <span v-else class="font-mono text-xs">{{ fmtRp(a.nilai) }}</span>
                                </td>
                                <td v-if="ubahKoreksi" class="px-1 py-2 text-center"><button type="button" class="text-danger-500" title="Batalkan alokasi" @click="hapusAlokasi(i)"><Trash2 class="h-4 w-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Memo Urgent — tarik satu memo yang sudah disetujui Direktur (opsional) -->
            <div v-if="step === 4" class="card p-5">
                <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Memo Urgent <span class="text-xs font-normal text-neutral-400">(opsional · satu memo)</span></h2>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                        <input v-model="memoCari" type="search" placeholder="Cari no. / perihal / departemen…" class="h-8 w-64 rounded-md border border-neutral-300 bg-white pl-7 pr-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                    </div>
                </div>
                <p class="mb-2 text-[11px] text-neutral-500">Memo yang sudah disetujui Direktur dan belum dipakai invoice lain. Klik baris untuk menarik / melepas memo.</p>
                <div class="max-h-72 overflow-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                            <tr><th class="w-8 px-2 py-2"></th><th class="px-2 py-2">No. Memo</th><th class="px-2 py-2">Tanggal</th><th class="px-2 py-2">Departemen</th><th class="px-2 py-2">Supplier</th><th class="px-2 py-2">Perihal</th><th class="px-2 py-2 text-right">Nominal</th><th class="px-2 py-2">Status</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in memoTampil" :key="m.id" class="cursor-pointer border-t border-neutral-100 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50" :class="form.memo_internal_id === m.id ? 'bg-primary-50/60 dark:bg-primary-900/20' : ''" @click="pilihMemo(m.id)">
                                <td class="px-2 py-2 text-center"><input type="radio" :checked="form.memo_internal_id === m.id" class="h-4 w-4 accent-primary-600" @click.stop="pilihMemo(m.id)" /></td>
                                <td class="whitespace-nowrap px-2 py-2 font-mono text-xs">{{ m.number }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-xs">{{ m.date }}</td>
                                <td class="px-2 py-2 text-xs">{{ m.department || '—' }}</td>
                                <td class="px-2 py-2 text-xs">{{ m.supplier || '—' }}</td>
                                <td class="px-2 py-2 text-xs">{{ m.subject }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right font-mono text-xs">{{ fmtRp(m.amount) }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-xs"><span :class="m.status === 'Disetujui' ? 'text-success-700' : 'text-amber-600'">{{ m.status }}</span></td>
                            </tr>
                            <tr v-if="!memoTampil.length"><td colspan="8" class="px-2 py-6 text-center text-xs text-neutral-400">{{ (memoUrgent ?? []).length ? (memoSupplier ? 'Tidak ada memo untuk supplier ini.' : 'Tidak ada memo yang cocok.') : 'Belum ada Memo Urgent yang disetujui Direktur.' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        </fieldset>

        <!-- Step 5: Selesai -->
        <div v-show="step === 5" class="card p-5 text-sm">
            <h2 class="mb-3 font-semibold">Ringkasan</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <div><span class="text-neutral-500">{{ jenis.penerima }}:</span> {{ supplier?.name }}</div>
                <div><span class="text-neutral-500">Kategori:</span> {{ category?.name }}</div>
                <div><span class="text-neutral-500">Jatuh tempo:</span> {{ dueDate }}</div>
                <div class="sm:col-span-2"><span class="text-neutral-500">Keterangan:</span> {{ form.description }}</div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800 sm:grid-cols-4">
                <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(form.dpp) }}</div></div>
                <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                <div><div class="text-xs text-neutral-400">{{ pphLabel }}</div><div class="font-mono" :class="pph > 0 ? 'text-danger-600' : 'text-neutral-500'">{{ fmtRp(pph) }}</div></div>
                <div><div class="text-xs text-neutral-400">Net Dibayar</div><div class="font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(net) }}</div></div>
            </div>
            <div class="mt-3">
                <div class="mb-1 flex items-center justify-between"><span class="text-xs font-medium text-neutral-500">Struktur Jurnal</span><span v-if="jurnalBalanced" class="text-[11px] font-medium text-success-600">● Balance</span><span v-else class="text-[11px] font-medium text-amber-600">● Selisih {{ fmtRp(Math.abs(jurnalDebit - jurnalKredit)) }}</span></div>
                <table class="w-full text-xs">
                    <thead class="text-left uppercase text-neutral-400"><tr><th class="py-1">Account</th><th class="py-1">Dimensi</th><th class="py-1">Keterangan</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right">Kredit</th></tr></thead>
                    <tbody>
                        <tr v-for="(l,i) in activeJournalLines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1 font-mono">{{ accountLabel(l.account_id) }}</td>
                            <td class="py-1 font-mono text-[11px] text-neutral-500">{{ dimSummary(l) || '—' }}</td>
                            <td class="py-1">{{ l.description }}</td>
                            <td class="py-1 text-right font-mono">{{ (Number(l.debit) || 0) > 0 ? fmtRp(l.debit) : '' }}</td>
                            <td class="py-1 text-right font-mono">{{ (Number(l.credit) || 0) > 0 ? fmtRp(l.credit) : '' }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700"><td colspan="3" class="py-1 text-right text-neutral-500">Grand Total</td><td class="py-1 text-right font-mono">{{ fmtRp(jurnalDebit) }}</td><td class="py-1 text-right font-mono">{{ fmtRp(jurnalKredit) }}</td></tr></tfoot>
                </table>
            </div>
            <div v-if="memoDipilih" class="mt-3 rounded-lg border border-neutral-200 p-3 text-xs dark:border-neutral-800">
                <span class="text-neutral-500">Memo Urgent:</span> <span class="font-mono font-semibold">{{ memoDipilih.number }}</span> — {{ memoDipilih.subject }} · {{ fmtRp(memoDipilih.amount) }} · <span class="text-amber-600">{{ memoDipilih.status }}</span>
            </div>
            <div v-if="form.alokasi.length" class="mt-4 rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-950/30">
                <p class="text-sm font-semibold text-sky-800 dark:text-sky-200">Ada alokasi budget</p>
                <p class="mt-0.5 text-xs text-sky-800/90 dark:text-sky-200/90"><template v-if="pengajuanBudget">{{ form.alokasi.length }} alokasi di bawah ini sudah disetujui — anggaran {{ bulanBudget || 'bulan ini' }} sudah dipindah dari budget asal.</template><template v-else>{{ form.alokasi.length }} alokasi di bawah ini ikut approval invoice ini — invoice tetap berjalan.</template></p>
            </div>
            <!-- Koreksi budget yang ikut diajukan -->
            <div v-if="form.koreksi.length" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Ada koreksi budget</p>
                <p class="mt-0.5 text-xs text-amber-800/90 dark:text-amber-200/90"><template v-if="pengajuanBudget">{{ form.koreksi.length }} koreksi budget di bawah ini sudah disetujui dan menambah budget {{ bulanBudget || 'bulan ini' }}.</template><template v-else>{{ form.koreksi.length }} koreksi budget di bawah ini ikut approval invoice ini — invoice tetap berjalan.</template></p>
            </div>
        </div>

        <!-- Koreksi Budget — baris yang melewati sisa budget bulan ini -->
        <div v-if="form.koreksi.length && step === 5" class="card p-5">
            <div class="mb-1 flex items-center justify-between gap-2">
                <h2 class="font-semibold">Koreksi Budget</h2>
                <span class="text-[11px] text-neutral-400">{{ bulanBudget }}</span>
            </div>
            <p class="mb-2 text-[11px] text-neutral-500">{{ pengajuanBudget ? 'Sudah disetujui — tambahannya sudah masuk ke budget bulan ini.' : 'Ikut approval invoice ini — menambah budget bulan ini setelah invoice disetujui penuh.' }}</p>
            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                        <tr>
                            <th class="w-10 px-2 py-2">No</th><th class="px-2 py-2">Account</th><th class="px-2 py-2">Cost Center</th>
                            <th class="px-2 py-2">Keterangan</th><th class="w-44 px-2 py-2 text-right">Nilai Pengajuan</th><th v-if="ubahKoreksi" class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(k, i) in form.koreksi" :key="k.budget_id" class="border-t border-neutral-100 align-top dark:border-neutral-800">
                            <td class="px-2 py-2 font-mono text-neutral-400">{{ i + 1 }}</td>
                            <td class="px-2 py-2 text-xs">{{ accountLabel(k.account_id) }}</td>
                            <td class="px-2 py-2 text-xs">{{ labelCc(k.cost_center_id) }}</td>
                            <td class="px-2 py-2 text-xs">
                                <textarea v-if="ubahKoreksi" v-model="k.keterangan" rows="2" :class="cellArea" maxlength="200" placeholder="Alasan koreksi…"></textarea>
                                <span v-else>{{ k.keterangan || '—' }}</span>
                            </td>
                            <td class="px-2 py-2 text-right">
                                <template v-if="ubahKoreksi">
                                    <MoneyInput :model-value="k.nilai" @update:model-value="(v) => (k.nilai = v)" :class="['!h-8 !rounded-md !px-2 !text-xs font-mono', (Number(k.nilai) || 0) < kurangKoreksi(k) ? CINCIN_MERAH : '']" />
                                    <p :class="['mt-0.5 text-[10.5px]', (Number(k.nilai) || 0) < kurangKoreksi(k) ? 'text-danger-500' : 'text-neutral-400']">{{ kurangKoreksi(k) > 0 ? 'Minimal ' + fmtRp(kurangKoreksi(k)) + ' (kekurangan)' : 'Opsional — anggaran masih cukup' }}</p>
                                </template>
                                <span v-else class="font-mono text-xs">{{ fmtRp(k.nilai) }}</span>
                            </td>
                            <td v-if="ubahKoreksi" class="px-1 py-2 text-center"><button type="button" class="text-danger-500" title="Batalkan pengajuan koreksi" @click="hapusKoreksi(i)"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- Alokasi Budget — kekurangan diambil dari sisa bulan ini di budget lain -->
        <div v-if="form.alokasi.length && step === 5" class="card p-5">
            <div class="mb-1 flex items-center justify-between gap-2">
                <h2 class="font-semibold">Alokasi Budget</h2>
                <span class="text-[11px] text-neutral-400">{{ bulanBudget }}</span>
            </div>
            <p class="mb-2 text-[11px] text-neutral-500">{{ pengajuanBudget ? 'Sudah disetujui — anggaran sudah dipindah dari budget asal ke budget baris ini.' : 'Ikut approval invoice ini — anggaran dipindah setelah invoice disetujui penuh.' }}</p>
            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                        <tr>
                            <th class="w-10 px-2 py-2">No</th><th class="px-2 py-2">Budget Tujuan</th><th class="px-2 py-2">Ambil Dari (Budget Asal)</th>
                            <th class="px-2 py-2">Keterangan</th><th class="w-44 px-2 py-2 text-right">Nilai Alokasi</th><th v-if="ubahKoreksi" class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(a, i) in form.alokasi" :key="a.budget_id" class="border-t border-neutral-100 align-top dark:border-neutral-800">
                            <td class="px-2 py-2 font-mono text-neutral-400">{{ i + 1 }}</td>
                            <td class="px-2 py-2 text-xs">{{ accountLabel(a.account_id) }}<div class="text-neutral-400">{{ labelCc(a.cost_center_id) }}</div></td>
                            <td class="min-w-[260px] px-2 py-2 text-xs">
                                <template v-if="ubahKoreksi">
                                    <SearchSelect v-model="a.dari_budget_id" :options="opsiSumber(a)" placeholder="— pilih budget asal —" :no-truncate="true" :class="!a.dari_budget_id ? CINCIN_MERAH : ''" />
                                    <p v-if="!sumberAlokasi.length" class="mt-0.5 text-[10.5px] text-neutral-400">Tidak ada budget lain yang masih bersisa di {{ bulanBudget }}.</p>
                                    <p v-else-if="sisaSumber(a) !== null" class="mt-0.5 text-[10.5px] text-neutral-500">Sisa asal {{ fmtRp(sisaSumber(a) as number) }} → setelah alokasi {{ fmtRp((sisaSumber(a) as number) - (Number(a.nilai) || 0)) }}</p>
                                </template>
                                <span v-else>{{ sumberAlokasi.find((b) => b.id === a.dari_budget_id)?.label ?? '—' }}</span>
                            </td>
                            <td class="px-2 py-2 text-xs">
                                <textarea v-if="ubahKoreksi" v-model="a.keterangan" rows="2" :class="cellArea" maxlength="200" placeholder="Alasan alokasi…"></textarea>
                                <span v-else>{{ a.keterangan || '—' }}</span>
                            </td>
                            <td class="px-2 py-2 text-right">
                                <template v-if="ubahKoreksi">
                                    <MoneyInput :model-value="a.nilai" @update:model-value="(v) => (a.nilai = v)" :class="['!h-8 !rounded-md !px-2 !text-xs font-mono', alokasiSalah(a) && a.dari_budget_id ? CINCIN_MERAH : '']" />
                                    <p :class="['mt-0.5 text-[10.5px]', (Number(a.nilai) || 0) < kurangKoreksi(a) || (sisaSumber(a) !== null && (Number(a.nilai) || 0) > (sisaSumber(a) as number)) ? 'text-danger-500' : 'text-neutral-400']">{{ kurangKoreksi(a) > 0 ? 'Minimal ' + fmtRp(kurangKoreksi(a)) : 'Opsional' }}<template v-if="sisaSumber(a) !== null"> · maks {{ fmtRp(sisaSumber(a) as number) }}</template></p>
                                </template>
                                <span v-else class="font-mono text-xs">{{ fmtRp(a.nilai) }}</span>
                            </td>
                            <td v-if="ubahKoreksi" class="px-1 py-2 text-center"><button type="button" class="text-danger-500" title="Batalkan alokasi" @click="hapusAlokasi(i)"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Konfirmasi pengajuan alokasi / koreksi budget ke Finance -->
        <div v-if="konfirmasiFinance" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="konfirmasiFinance = false">
            <div class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                <button type="button" class="absolute right-3 top-3 rounded-full p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800" title="Tutup" @click="konfirmasiFinance = false"><X class="h-5 w-5" /></button>
                <h2 class="pr-8 text-lg font-bold">Pengajuan {{ jenisPengajuan }} budget</h2>
                <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                    Anda mengajukan <b>{{ jenisPengajuan }} budget</b>. Permintaan Anda akan disetujui lewat <b>alur approval invoice</b> (Accounting → Finance → GM → Direktur → MW / BA) terlebih dahulu agar dapat diselesaikan.
                </p>
                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button v-if="wajibBerkas && !pengajuanBudget" type="button" :disabled="menyimpanDraft" class="inline-flex h-10 items-center rounded-lg border border-neutral-300 px-4 text-sm font-medium hover:bg-neutral-50 disabled:opacity-60 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="konfirmasiFinance = false; simpanDraft()">Simpan ke Draft</button>
                    <Button :disabled="mengajukan" @click="konfirmasiFinance = false; ajukanFinance()"><template #icon-left><ArrowRight class="h-4 w-4" /></template>{{ mengajukan ? 'Mengajukan…' : 'Selanjutnya (Ajukan Approval)' }}</Button>
                </div>
            </div>
        </div>

        <!-- Lihat semua: satu PDF gabungan berkas terpilih -->
        <div v-if="semua" class="fixed inset-0 z-50 flex flex-col bg-black/70 p-4" @click.self="tutupSemua">
            <div class="mx-auto flex w-full max-w-4xl items-center justify-between gap-3 pb-2 text-white">
                <span class="truncate text-sm">Semua berkas · {{ berkasDipilih?.files.length }} file digabung</span>
                <div class="flex gap-2">
                    <a :href="semua.url" :download="semua.nama" class="rounded-md bg-white/15 px-3 py-1 text-sm hover:bg-white/25">Unduh PDF</a>
                    <button type="button" class="rounded-md bg-white/15 px-3 py-1 text-sm hover:bg-white/25" @click="tutupSemua">Tutup</button>
                </div>
            </div>
            <div class="mx-auto min-h-0 w-full max-w-4xl flex-1 overflow-hidden rounded-lg bg-white">
                <iframe :src="semua.url" class="h-full w-full" :title="semua.nama" />
            </div>
        </div>
    </div>
</template>
