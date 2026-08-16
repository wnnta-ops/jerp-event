<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsCashTransaction extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cash_transactions';
    protected $fillable = [
        'tenant_id', 'trx_number', 'trx_type', 'trx_date', 'cash_account_id', 'counter_account_id',
        'amount', 'payee', 'description', 'reference', 'journal_id', 'created_by',
        'approval_status', 'approved_by', 'approved_at',
    ];
    /**
     * trx_date memakai format 'date:Y-m-d', BUKAN 'date' saja.
     *
     * Cast 'date' polos diserialisasi Laravel ke ISO-8601 dalam UTC. Aplikasi ini berzona
     * Asia/Jakarta (UTC+7), sehingga tanggal 2026-08-16 keluar sebagai
     * "2026-08-15T17:00:00.000000Z" — dan layar yang memotong 10 karakter pertama membaca
     * 15 Agustus. Tanggal transaksi kas jadi mundur sehari setiap kali dokumen dibuka untuk
     * diedit, dan ikut tersimpan mundur bila operator menekan Simpan tanpa memperbaikinya.
     *
     * Kolomnya memang DATE (tanpa jam), jadi memformatnya Y-m-d bukan sekadar tambalan:
     * itulah bentuk yang jujur mewakili isinya, dan langsung cocok dengan <input type="date">.
     */
    protected $casts = ['trx_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public const TYPES = [
        'in'       => 'Kas Masuk (BKM)',
        'out'      => 'Kas Keluar (BKK)',
        'transfer' => 'Transfer Antar Rekening',
    ];

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
    public function counterAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'counter_account_id'); }

    /** Rincian akun lawan (#15 — split transaction; bisa >1 akun). */
    public function lines(): HasMany { return $this->hasMany(BsCashTransactionLine::class, 'cash_transaction_id'); }
}
