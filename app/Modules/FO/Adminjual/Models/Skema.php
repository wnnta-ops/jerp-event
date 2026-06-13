<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Skema extends TenantAwareModel
{
    protected $table = 'fo_skema';

    protected $fillable = [
        'tenant_id', 'project_id', 'lokasi_id', 'tahap_id', 'code', 'name',
        'cara_bayar', 'dp_persen', 'ang_persen', 'bf_nominal', 'subsidi_persen',
        'tenor_bulan', 'jumlah_termin',
        'present_value', 'diskon_persen', 'bf_dipotong_di', 'tampil_siteplan',
        'keterangan', 'is_active',
    ];

    protected $casts = [
        'dp_persen' => 'decimal:6',
        'ang_persen' => 'decimal:6',
        'bf_nominal' => 'decimal:2',
        'subsidi_persen' => 'decimal:6',
        'present_value' => 'decimal:2',
        'diskon_persen' => 'decimal:6',
        'tampil_siteplan' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // Hapus skema: blokir HANYA bila sudah dipakai di Kontrak (SPRS/SPR) —
        // kontrak bergantung pada skema ini. Sedangkan Price List & termin master
        // ikut terhapus otomatis (cascade) karena tak ada transaksi yang bergantung.
        static::deleting(function (Skema $skema): void {
            if (Kontrak::query()->where('skema_id', $skema->id)->exists()) {
                throw new RuntimeException(
                    'Skema "'.$skema->code.'" sudah dipakai di kontrak (SPRS/SPR), tidak bisa dihapus. '
                    .'Ganti/batalkan skema di kontrak terkait dulu.'
                );
            }
            UnitPricelist::query()->where('skema_id', $skema->id)->delete();
            $skema->lines()->delete();
        });
    }

    /**
     * Skema berlaku untuk lokasi & tahap tertentu? lokasi_id/tahap_id NULL = umum
     * (berlaku semua). Dipakai agar harga skema hanya tampil di unit yang cocok
     * tahap-nya (mis. skema CASH tahap 001 tidak muncul di unit tahap 004).
     */
    public function appliesToScope(?int $lokasiId, ?int $tahapId): bool
    {
        return ($this->lokasi_id === null || (int) $this->lokasi_id === $lokasiId)
            && ($this->tahap_id === null || (int) $this->tahap_id === $tahapId);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SkemaLine::class, 'skema_id')->orderBy('urutan');
    }
}
