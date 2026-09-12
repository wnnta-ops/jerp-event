<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsJournal extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_journals';
    protected $fillable = [
        'tenant_id', 'cost_center_id', 'journal_number', 'journal_date', 'period_year', 'period_month',
        'source', 'reference', 'description', 'status', 'total_debit', 'total_credit', 'created_by',
        'posted_at', 'finalized_at', 'approved_by',
    ];
    protected $casts = [
        'journal_date' => 'date', 'posted_at' => 'datetime', 'finalized_at' => 'datetime',
        'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2',
        'period_year' => 'integer', 'period_month' => 'integer',
    ];

    /** Siklus: draft (Baru) → final (Final) → posted (Approved/efektif di GL). */
    public const STATUSES = [
        'draft'  => 'Baru',
        'final'  => 'Final',
        'posted' => 'Approved',
    ];

    public const SOURCES = [
        'memorial'   => 'Memorial',
        'saldo_awal' => 'Saldo Awal',
        'ar'         => 'Piutang (AR)',
        'ap'         => 'Hutang (AP)',
        'cash'       => 'Kas/Bank',
        'inventory'  => 'Persediaan',
        // Import Jurnal massal (JournalImportController) sudah lama menulis source='import',
        // tetapi nilainya tak pernah terdaftar di sini. Akibatnya badge Sumber-nya jatuh ke
        // teks mentah "import", dan — yang lebih merepotkan — saringan Sumber pada Detail
        // Transaksi Jurnal dibangun dari daftar INI, sehingga jurnal hasil impor tidak bisa
        // disaring sama sekali. Justru sesudah mengimpor ratusan baris itulah orang paling
        // butuh menyaringnya.
        'import'     => 'Import Jurnal',
    ];

    /**
     * SUMBER MIGRASI DATA AWAL — dibebaskan dari kewajiban cost center.
     *
     * Cost center wajib karena ia yang membuat sebuah baris terbaca sebagai realisasi anggaran.
     * Tetapi jurnal migrasi tidak pernah menjadi realisasi anggaran siapa pun: ia memindahkan
     * saldo yang SUDAH terjadi di sistem lama, bukan mencatat belanja baru. Memaksakan cost
     * center di sana berarti mengarang departemen penanggung untuk angka yang bahkan tidak
     * berasal dari periode berjalan — dan sampai dikarang, seluruh jurnalnya tertahan di Final
     * sehingga saldo awalnya tidak pernah efektif di buku besar.
     */
    public const SUMBER_MIGRASI = ['import', 'saldo_awal'];

    /** Jurnal ini hasil migrasi data awal (Import Jurnal / Import Saldo Awal)? */
    public function migrasiDataAwal(): bool
    {
        return in_array((string) $this->source, self::SUMBER_MIGRASI, true);
    }

    public function lines(): HasMany { return $this->hasMany(BsJournalLine::class, 'journal_id'); }

    /** Riwayat status (append-only): dibuat → finalisasi → approve → batal approve → reject. */
    public function statusLogs(): HasMany { return $this->hasMany(BsJournalStatusLog::class, 'journal_id')->orderBy('id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'created_by'); }
}
