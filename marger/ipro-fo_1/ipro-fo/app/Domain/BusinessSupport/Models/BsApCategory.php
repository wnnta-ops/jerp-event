<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori Invoice (AP): memetakan kategori → akun debit + akun hutang supplier.
 */
class BsApCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_ap_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'debit_account_id', 'payable_account_id', 'credit_position', 'is_active', 'jenis'];
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

    /** Modul mana saja yang memakai kategori ini, berikut posisi kredit & akunnya per modul. */
    public function usages(): HasMany { return $this->hasMany(BsApCategoryUsage::class, 'category_id'); }

    /** Pemakaian kategori ini di satu modul; null bila kategori tidak dipakai di modul itu. */
    public function pemakaian(string $jenis): ?BsApCategoryUsage
    {
        return $this->relationLoaded('usages')
            ? $this->usages->firstWhere('jenis', $jenis)
            : $this->usages()->where('jenis', $jenis)->first();
    }

    /** @param  Builder<self>  $q */
    public function scopeDipakaiDi(Builder $q, string $jenis): Builder
    {
        return $q->whereHas('usages', fn ($u) => $u->where('jenis', $jenis));
    }

    /**
     * Kolom lama (jenis, credit_position, payable_account_id) disamakan dengan pemakaian
     * utamanya — Invoice Hutang bila ada, selain itu yang pertama. Pembaca lama yang masih
     * membaca kolom itu (invoice lama tanpa credit_position, seeder) tetap mendapat nilai yang
     * masuk akal.
     */
    public function selaraskanKolomLama(): void
    {
        $utama = $this->usages()->get()->sortBy(fn ($u) => array_search($u->jenis, array_keys(BsApInvoice::JENIS), true))->first();
        if ($utama) {
            $this->forceFill([
                'jenis' => $utama->jenis,
                'credit_position' => $utama->credit_position,
                'payable_account_id' => $utama->payable_account_id,
            ])->saveQuietly();
        }
    }

    protected static function booted(): void
    {
        // Kategori yang dibuat tanpa daftar pemakaian (seeder, data lama) tetap terpakai di
        // modul kolom `jenis`-nya, dengan posisi & akun dari kolomnya sendiri.
        static::created(function (self $c): void {
            if (! $c->usages()->exists()) {
                $c->usages()->create([
                    'tenant_id' => $c->tenant_id,
                    'jenis' => $c->jenis ?: 'invoice',
                    'credit_position' => $c->credit_position ?: self::POSISI_HUTANG,
                    'payable_account_id' => $c->payable_account_id,
                ]);
            }
        });
    }
}
