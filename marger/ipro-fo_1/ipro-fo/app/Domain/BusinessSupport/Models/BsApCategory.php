<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kategori Invoice (AP): memetakan kategori → akun debit + akun hutang supplier.
 */
class BsApCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_ap_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'debit_account_id', 'payable_account_id', 'credit_position', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    /**
     * Posisi kredit invoice: utang usaha biasa, atau beban yang sudah terjadi tetapi
     * tagihannya belum menjadi utang usaha.
     *
     * Hanya menentukan PENJURNALAN. Akun yang dipilihnya tetap disimpan di
     * payable_account_id, sebab itulah kolom yang dibaca RegisterApInvoice (dibekukan ke
     * header invoice) dan seluruh jalur pelunasan.
     */
    public const POSISI_HUTANG = 'hutang';

    public const POSISI_BEBAN = 'beban_ymhd';

    public const POSISI = [
        self::POSISI_HUTANG => 'Hutang',
        self::POSISI_BEBAN  => 'Beban Masih Harus Dibayar',
    ];

    /** Akun yang dipakai posisi "Beban Masih Harus Dibayar" (COA dari config, bukan id mati). */
    public static function akunBeban(): ?BsAccount
    {
        return BsAccount::where('code', config('business_support.gl_accounts.accrued_expense'))->first();
    }

    public function debitAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'debit_account_id'); }
    public function payableAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'payable_account_id'); }
}
