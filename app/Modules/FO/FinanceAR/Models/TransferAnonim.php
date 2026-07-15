<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Transfer masuk belum teridentifikasi. status: BARU/TERIDENTIFIKASI/SELESAI → jadi TTS. */
class TransferAnonim extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_transfer_anonim';

    protected $fillable = [
        'tenant_id', 'tgl_transfer', 'bank_id', 'coa_bank_code', 'amount', 'keterangan_bank',
        'customer_id', 'tts_id', 'status', 'keterangan',
    ];

    protected $casts = [
        'tgl_transfer' => 'date',
        'amount' => 'decimal:2',
    ];

    /** @var array<string,string>|null memo kode→nama akun bank CoA (per request) */
    private static ?array $coaBankMap = null;

    /**
     * Opsi "Bank Penerima" = akun bank postable di Chart of Account BO
     * (ipro_bo.bs_accounts, grup BANK). @return array<string,string> kode → nama.
     */
    public static function coaBankOptions(): array
    {
        return self::$coaBankMap ??= DB::connection(config('ipro.connection', 'ipro_bo'))
            ->table('bs_accounts')
            ->where('code', 'like', config('ipro.coa_bank_prefix', '10101020').'%')
            ->where('is_postable', 1)->where('is_active', 1)
            ->orderBy('code')->pluck('name', 'code')->all();
    }

    /** Nama akun bank CoA dari kode tersimpan (untuk tampilan). */
    public function coaBankName(): ?string
    {
        if (! $this->coa_bank_code) {
            return null;
        }

        return self::coaBankOptions()[$this->coa_bank_code] ?? $this->coa_bank_code;
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tts(): BelongsTo
    {
        return $this->belongsTo(Tts::class);
    }
}
