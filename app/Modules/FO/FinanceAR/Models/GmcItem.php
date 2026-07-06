<?php

namespace App\Modules\FO\FinanceAR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Rincian komponen GMC/gimmick. Satu GMC (mis. "PPJB") bisa terdiri beberapa
 * komponen biaya, tiap komponen ke supplier berbeda (bs_suppliers @ ipro_bo).
 * Saat "Posting BO", tiap komponen → 1 baris antrian Posting Invoice ke supplier-nya.
 */
class GmcItem extends Model
{
    protected $table = 'fo_gmc_items';

    protected $fillable = ['gmc_id', 'nama', 'supplier_id', 'nilai', 'urutan'];

    protected $casts = ['nilai' => 'decimal:2'];

    /** @var array<int,string>|null memo id→nama supplier BO (per request) */
    private static ?array $supplierMap = null;

    public function gmc(): BelongsTo
    {
        return $this->belongsTo(Gmc::class);
    }

    /** Opsi supplier = master Supplier Center BO (bs_suppliers). @return array<int,string> id→label. */
    public static function supplierOptions(): array
    {
        return self::$supplierMap ??= DB::connection(config('ipro.connection', 'ipro_bo'))
            ->table('bs_suppliers')->where('is_active', 1)->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($s) => [(int) $s->id => trim(($s->code ? $s->code.' — ' : '').$s->name)])
            ->all();
    }

    /** Nama supplier BO dari id (untuk tampilan). */
    public function supplierName(): ?string
    {
        return $this->supplier_id ? (self::supplierOptions()[(int) $this->supplier_id] ?? null) : null;
    }
}
