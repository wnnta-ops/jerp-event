<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kartu kredit / Virtual Account (Cash Book → Setup → Set Up Credit Card).
 *
 * Saldonya TIDAK disunting langsung: setiap penambahan & pengurangan lewat
 * BsCreditCardRequest yang harus disetujui. Lihat CreditCardService.
 */
class BsCreditCard extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_credit_cards';

    protected $fillable = [
        'tenant_id', 'account_id', 'card_number', 'name',
        'is_active', 'balance', 'expired_date', 'note', 'created_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'balance'      => 'decimal:2',
        'expired_date' => 'date:Y-m-d',
    ];

    /**
     * Sudah lewat masa berlakunya?
     *
     * Tanpa tanggal expired = belum dibatasi, BUKAN kedaluwarsa. Membaca NULL sebagai
     * kedaluwarsa akan mematikan setiap kartu yang belum pernah diberi masa berlaku.
     */
    public function isExpired(?string $pada = null): bool
    {
        if (! $this->expired_date) {
            return false;
        }

        return $this->expired_date->toDateString() < ($pada ?: now()->toDateString());
    }

    /**
     * Boleh dipakai membayar?
     *
     * Syaratnya AKTIF dan belum kedaluwarsa. Saldo TIDAK ikut menjadi syarat di sini: justru
     * pembayaran Debit VA-lah yang melahirkan permintaan pengisian saldonya, sehingga menuntut
     * saldo lebih dulu membuat kartu yang baru diaktifkan tidak akan pernah bisa diisi.
     */
    public function isUsable(?string $pada = null): bool
    {
        return $this->is_active && ! $this->isExpired($pada);
    }

    /** Label untuk dropdown & daftar: nomor kartu + nama akunnya. */
    public function label(): string
    {
        return trim(($this->card_number ? $this->card_number.' — ' : '').$this->name);
    }

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function requests(): HasMany { return $this->hasMany(BsCreditCardRequest::class, 'credit_card_id'); }
}
