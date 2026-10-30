<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsApInvoice extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ap_invoices';
    protected $fillable = [
        'tenant_id', 'invoice_number', 'invoice_date', 'receive_date', 'due_date', 'term_days', 'supplier_id',
        'unit_id', 'customer_id',
        'category_id', 'supplier_invoice_no', 'tax_invoice_no', 'manual_no', 'trx_type', 'source_type', 'source_id', 'refund_source',
        'expense_account_id', 'payable_account_id', 'description', 'berkas_notes', 'berkas_finance_date', 'transfer_detail', 'kurs',
        'dpp', 'ppn_pct', 'ppn_amount', 'ppn_base', 'ppn_mode', 'pph_mode', 'pph_type', 'pph_pct', 'pph_base', 'pph_amount', 'pph_account_id',
        'total', 'net_payable', 'paid_amount', 'status', 'journal_id', 'created_by', 'jenis', 'credit_position',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];
    protected $casts = [
        'invoice_date' => 'date', 'receive_date' => 'date', 'due_date' => 'date', 'berkas_finance_date' => 'date', 'term_days' => 'integer',
        'cancelled_at' => 'datetime',
        'kurs' => 'decimal:4',
        'dpp' => 'decimal:2', 'ppn_pct' => 'decimal:2', 'ppn_amount' => 'decimal:2', 'ppn_base' => 'decimal:2',
        'pph_pct' => 'decimal:2', 'pph_base' => 'decimal:2', 'pph_amount' => 'decimal:2',
        'total' => 'decimal:2', 'net_payable' => 'decimal:2', 'paid_amount' => 'decimal:2',
    ];

    public const TRX_TYPES = ['normal' => 'Normal', 'denda' => 'Realisasi Denda', 'saldo_awal' => 'Saldo Awal'];

    /**
     * JENIS invoice hutang — alurnya sama (registrasi → Memo + BPP → Kuitansi → Approval →
     * dibayar); yang berbeda penerima & akun jurnalnya (akun dari Kategori ber-jenis sama).
     *
     *   judul    nama modul di layar
     *   nomor    prefiks penomoran (BoDocumentNumbers)
     *   izin     awalan izin modulnya: {izin}.view / {izin}.create
     *   rute     awalan nama rute daftar & registrasinya
     *   penerima sebutan kolom penerima pengganti "Supplier"
     */
    public const JENIS = [
        'invoice' => ['judul' => 'Invoice Hutang', 'nomor' => 'AP.INV', 'izin' => 'ap.invoice', 'rute' => 'bs.ap', 'penerima' => 'Supplier'],
        'reimburse' => ['judul' => 'Reimburse Biaya Karyawan', 'nomor' => 'AP.RMB', 'izin' => 'ap.reimburse', 'rute' => 'bs.ap.reimburse', 'penerima' => 'Karyawan'],
        'deposit' => ['judul' => 'Deposit Renovasi Customer', 'nomor' => 'AP.DEP', 'izin' => 'ap.deposit', 'rute' => 'bs.ap.deposit', 'penerima' => 'Customer'],
    ];

    /**
     * Supplier pemilik hutang ini — TERMASUK yang sudah dihapus (soft delete).
     *
     * Tanpa withTrashed(), menghapus supplier membuat relasi ini memulangkan null dan seluruh
     * hutangnya berkumpul di baris "—" pada Aging Hutang: satu angka gelondongan milik siapa
     * pun yang supplier-nya hilang, tidak bisa ditagih dan tidak bisa ditelusuri. Id-nya masih
     * tersimpan rapi; yang hilang hanya namanya, dan itu tidak perlu terjadi.
     *
     * Menghapus supplier yang masih memegang hutang kini DITOLAK (SupplierController::destroy),
     * jadi ini menangani data yang sudah terlanjur terhapus sebelum larangan itu ada.
     */
    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id')->withTrashed(); }
    public function category(): BelongsTo { return $this->belongsTo(BsApCategory::class, 'category_id'); }
    /** Akun yang dikredit jurnal invoice ini (dibekukan saat registrasi). */
    public function payableAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'payable_account_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Unit\Models\Unit::class, 'unit_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class, 'customer_id'); }
    public function payments(): HasMany { return $this->hasMany(BsApPayment::class, 'ap_invoice_id'); }
    public function lines(): HasMany { return $this->hasMany(BsApInvoiceLine::class, 'ap_invoice_id'); }
    public function schedules(): HasMany { return $this->hasMany(BsApInvoiceSchedule::class, 'ap_invoice_id'); }

    /** Apakah invoice ini berasal dari Purchase Order. */
    public function isFromPo(): bool { return $this->source_type === 'po' && $this->source_id !== null; }

    /**
     * Purchase Order asal invoice ini, atau NULL bila bukan dari PO.
     *
     * SENGAJA bukan relasi Eloquent: keterkaitan memakai pasangan generik source_type/source_id
     * (source_id juga dipakai SPK/refund/batch), sehingga belongsTo polos pada source_id bisa
     * salah menarik PO milik id sumber lain saat di-eager-load. Pengecekan source_type wajib.
     */
    public function purchaseOrder(): ?BsPurchaseOrder
    {
        return $this->isFromPo() ? BsPurchaseOrder::find($this->source_id) : null;
    }

    /** Yang dibayar ke supplier = net_payable (total − PPh dipungut); fallback ke total untuk data lama. */
    public function getPayableAmountAttribute(): float
    {
        $net = (float) $this->net_payable;

        return $net > 0 ? $net : (float) $this->total;
    }

    public function getOutstandingAttribute(): float { return $this->payable_amount - (float) $this->paid_amount; }

    /**
     * Akun HUTANG invoice menurut Struktur Jurnalnya: akun semula bila masih dikredit, selain itu
     * akun kredit terbesar. Null bila jurnal tidak punya baris kredit.
     *
     * Struktur Jurnal bisa disusun manual (wizard Registrasi, Edit invoice Posting FO), sehingga
     * akun kreditnya bisa lain dari akun Kategori. payable_account_id dibaca BPP (memisahkan baris
     * hutang dari rinciannya) dan Kuitansi/pelunasan (akun yang didebit) — harus ikut jurnal.
     *
     * @param  iterable<array{account_id:mixed,credit?:mixed}|object>  $lines
     */
    /**
     * Nilai HUTANG tiap invoice menurut jurnalnya: jumlah kredit pada akun hutangnya (Utang Usaha
     * Supplier / BYMHD / akun hutang lain yang dikredit) — nilai yang memang dibayar ke supplier,
     * sesudah PPh dipotong. Invoice tanpa jurnal (saldo awal) memakai nett (payable_amount).
     * Satu query untuk seluruh daftar.
     *
     * @param  iterable<BsApInvoice>  $invoices
     * @return array<int,float> id invoice → nilai hutang
     */
    public static function nilaiHutangJurnal(iterable $invoices): array
    {
        $inv = collect($invoices);
        $baris = \Illuminate\Support\Facades\DB::table('bs_journal_lines as l')
            ->join('bs_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.journal_id', $inv->pluck('journal_id')->filter()->unique()->values())
            ->where('l.credit', '>', 0)
            ->get(['l.journal_id', 'l.account_id', 'l.credit', 'a.code'])->groupBy('journal_id');
        // Utamakan COA Utang Usaha Supplier & BYMHD (permintaan Finance): akun hutang invoice data
        // lama bisa keliru (mis. Uang Muka Pembelian yang ikut dikredit).
        $utama = array_filter([config('business_support.gl_accounts.payable'), config('business_support.gl_accounts.accrued_expense')]);

        $hasil = [];
        foreach ($inv as $i) {
            $kredit = $baris[$i->journal_id] ?? collect();
            $diUtama = $kredit->whereIn('code', $utama);
            if ($diUtama->isNotEmpty()) {
                $hasil[$i->id] = round((float) $diUtama->sum('credit'), 2);

                continue;
            }
            $akun = $kredit->isEmpty() ? null : self::akunHutangDariJurnal($kredit, (int) $i->payable_account_id);
            $hasil[$i->id] = $akun
                ? round((float) $kredit->where('account_id', $akun)->sum('credit'), 2)
                : round($i->payable_amount, 2);
        }

        return $hasil;
    }

    public static function akunHutangDariJurnal(iterable $lines, ?int $semula): ?int
    {
        $kredit = collect($lines)->map(fn ($l) => (array) $l)
            ->filter(fn ($l) => (float) ($l['credit'] ?? 0) > 0 && ! empty($l['account_id']));
        if ($kredit->isEmpty()) {
            return null;
        }
        if ($semula && $kredit->contains(fn ($l) => (int) $l['account_id'] === $semula)) {
            return $semula;
        }
        // Kredit terbesar di akun KEWAJIBAN (kode 2…) lebih dulu — kredit pada akun aset (mis. Uang
        // Muka Pembelian yang dikompensasi) bukan hutang.
        $kodeAkun = \Illuminate\Support\Facades\DB::table('bs_accounts')
            ->whereIn('id', $kredit->pluck('account_id')->unique()->values())->pluck('code', 'id');
        $kewajiban = $kredit->filter(fn ($l) => str_starts_with((string) ($kodeAkun[$l['account_id']] ?? ''), '2'));

        return (int) ($kewajiban->isNotEmpty() ? $kewajiban : $kredit)->sortByDesc(fn ($l) => (float) $l['credit'])->first()['account_id'];
    }

    // ── APPROVAL SEBELUM BAYAR (invoice dari Upload Berkas) ──────────────────────

    /** document_type approval invoice hutang (Accounting → Finance → GM → Direktur → MW / BA). */
    public const APPROVAL_DOCUMENT_TYPE = 'ap_invoice';

    /**
     * Approval terakhir invoice ini, atau null bila invoice tidak pernah diajukan
     * (semua invoice lama / bukan dari Upload Berkas — tetap bisa dibayar seperti biasa).
     */
    public function approvalPembayaran(): ?\App\Domain\Approval\Models\Approval
    {
        return \App\Domain\Approval\Models\Approval::query()
            ->where('document_type', self::APPROVAL_DOCUMENT_TYPE)
            ->where('approvable_type', $this->getMorphClass())
            ->where('approvable_id', $this->id)
            ->latest('id')
            ->first();
    }

    /** Tolak pembayaran (bayar langsung / kuitansi) selama approval invoice belum disetujui penuh. */
    public function assertBolehDibayar(): void
    {
        $approval = $this->approvalPembayaran();
        if ($approval === null || $approval->status === 'approved') {
            return;
        }

        throw new \App\Exceptions\BusinessRuleException(
            $approval->status === 'rejected'
                ? "Invoice {$this->invoice_number} DITOLAK di approval — tidak bisa dibayar."
                : "Invoice {$this->invoice_number} masih menunggu approval (Accounting → Finance → GM → Direktur → MW / BA) — belum bisa dibayar.",
            errorCode: 'AP_MENUNGGU_APPROVAL',
        );
    }

    // ── EDIT & BATAL ─────────────────────────────────────────────────────────────

    public const STATUS_BATAL = 'batal';

    /** Penanda catatan PO yang diterbitkan otomatis oleh GeneratePoFromApInvoice. */
    public const PENANDA_PO_OTOMATIS = 'Dibuat otomatis dari Invoice Hutang';

    public function batal(): bool { return $this->status === self::STATUS_BATAL; }

    /**
     * Purchase Order yang DITERBITKAN OTOMATIS untuk invoice ini (bukan PO sungguhan yang
     * di-posting menjadi invoice). Hanya PO semacam ini yang boleh ikut diperbarui/dibatalkan.
     */
    public function poOtomatis(): ?BsPurchaseOrder
    {
        $po = $this->purchaseOrder();

        return $po && str_starts_with((string) $po->notes, self::PENANDA_PO_OTOMATIS) ? $po : null;
    }

    /**
     * Dibuat lewat wizard Registrasi (Invoice Hutang / Reimburse / Deposit) — bukan hasil Posting
     * dari FO, SPK, PO, atau Saldo Awal. Hanya invoice semacam ini yang punya Edit & Batal:
     * yang lain bertaut ke dokumen sumbernya, dan membatalkannya di sini akan membuat dokumen
     * sumber itu tetap tercatat "sudah diinvoice".
     */
    /** Sumber Posting Invoice dari FO (antrean bs_ap_refund_queue; 'batch' = posting gabungan). */
    public const SUMBER_POSTING_FO = ['refund', 'komisi', 'blt', 'gmc', 'batch'];

    /**
     * Lahir dari Posting Invoice dari FO — punya Edit sendiri (ApInvoiceFoEditController), sebab
     * wizard Registrasi bertumpu pada Kategori yang tidak dimiliki invoice semacam ini.
     */
    public function dariPostingFo(): bool
    {
        return in_array((string) $this->source_type, self::SUMBER_POSTING_FO, true)
            && ($this->trx_type ?? 'normal') !== 'saldo_awal';
    }

    public function dariRegistrasi(): bool
    {
        return $this->category_id !== null
            && ($this->trx_type ?? 'normal') !== 'saldo_awal'
            && (empty($this->source_type) || $this->poOtomatis() !== null);
    }

    /**
     * Alasan invoice ini SUDAH DIPROSES FAT — null bila belum (masih boleh diedit pembuatnya).
     *
     * @return string|null kalimat yang ditampilkan apa adanya di layar
     */
    public function alasanDiprosesFat(): ?string
    {
        if ($this->berkas_finance_date !== null) {
            return 'Berkas sudah diterima Finance';
        }
        if ($this->kuitansiAktifAtauMenunggu()) {
            return 'Sudah dibuatkan Kuitansi Hutang';
        }
        if ((float) $this->paid_amount > 0 || $this->payments()->exists()) {
            return 'Sudah ada pembayaran';
        }
        if (BsApMemorial::where('ap_invoice_id', $this->id)->exists()) {
            return 'Sudah ada Penghapusan Nilai Hutang';
        }
        if ($this->journal_id && BsJournal::whereKey($this->journal_id)->where('status', 'posted')->exists()) {
            return 'Jurnalnya sudah di-approve di General Ledger';
        }

        return null;
    }

    /** Ada Kuitansi Hutang yang masih menunggu approval atau sudah aktif untuk invoice ini. */
    public function kuitansiAktifAtauMenunggu(): bool
    {
        return BsApKuitansiLine::where('ap_invoice_id', $this->id)
            ->whereIn('kuitansi_id', BsApKuitansi::query()->select('id')->whereIn('status', ['pending', 'active']))
            ->exists();
    }
}
