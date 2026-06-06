<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends TenantAwareModel
{
    protected $table = 'fo_units';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'lokasi_id',
        'tahap_id',
        'unit_type_id',
        'tahap',
        'blok',
        'no_unit',
        'kode_unit',
        'cluster',
        'alamat',
        'luas_tanah',
        'luas_lebih_tanah',
        'luas_bangunan',
        'lebar_jalan',
        'panjang',
        'lebar',
        'tinggi',
        'zoning',
        'arah_hadap',
        'panorama',
        'tipe_properti',
        'kategori_unit',
        'harga_jual',
        'price_list_minimum',
        'price_list_kavling',
        'siteplan_coords',
        'status',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'luas_tanah' => 'decimal:2',
        'luas_lebih_tanah' => 'decimal:2',
        'luas_bangunan' => 'decimal:2',
        'lebar_jalan' => 'decimal:2',
        'panjang' => 'decimal:2',
        'lebar' => 'decimal:2',
        'tinggi' => 'decimal:2',
        'harga_jual' => 'decimal:2',
        'price_list_minimum' => 'decimal:2',
        'price_list_kavling' => 'decimal:2',
        'siteplan_coords' => 'array',
        'is_active' => 'boolean',
    ];

    /** @var array<int,?string> cache kode project per process (fallback Lokasi) */
    private static array $projectCodeCache = [];

    /** @var array<int,?array{kode:string,project_id:int}> cache master Lokasi */
    private static array $lokasiCache = [];

    /** @var array<int,?string> cache kode master Tahap */
    private static array $tahapKodeCache = [];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $unit) {
            // Unit mengikuti master: project & kode tahap diturunkan dari Lokasi/Tahap.
            if ($unit->lokasi_id && ($lokasi = self::lokasiFor($unit->lokasi_id))) {
                $unit->project_id = $lokasi['project_id'];
            }
            if ($unit->tahap_id && ($tahapKode = self::tahapKodeFor($unit->tahap_id)) !== null) {
                $unit->tahap = $tahapKode; // denormalisasi kode tahap
            }

            // No. Unit kanonik dibangun ulang dari komponennya — konsisten lintas modul.
            $kode = self::composeKodeUnit($unit);
            if ($kode !== null) {
                $unit->kode_unit = $kode;
            }
        });
    }

    /**
     * Bangun No. Unit format {Lokasi}-{Tahap}-{Blok}-{Nomer}.
     * Lokasi & Tahap diambil dari master (fallback: kode project & string tahap
     * untuk data lama). Bagian kosong di-skip. Null bila nomer belum terisi.
     */
    public static function composeKodeUnit(self $unit): ?string
    {
        if ($unit->no_unit === null || trim((string) $unit->no_unit) === '') {
            return null;
        }

        $lokasiKode = ($unit->lokasi_id ? (self::lokasiFor($unit->lokasi_id)['kode'] ?? null) : null)
            ?? self::projectCodeFor($unit->project_id);

        $tahapKode = ($unit->tahap_id ? self::tahapKodeFor($unit->tahap_id) : null)
            ?? (trim((string) $unit->tahap) !== '' ? $unit->tahap : null);

        $parts = array_filter(
            [$lokasiKode, $tahapKode, $unit->blok, $unit->no_unit],
            fn ($v) => $v !== null && trim((string) $v) !== '',
        );

        $kode = implode('-', array_map(fn ($v) => trim((string) $v), $parts));

        return $kode !== '' ? $kode : null;
    }

    private static function projectCodeFor(?int $projectId): ?string
    {
        if (! $projectId) {
            return null;
        }
        if (! array_key_exists($projectId, self::$projectCodeCache)) {
            self::$projectCodeCache[$projectId] = Project::query()
                ->withoutGlobalScopes()->whereKey($projectId)->value('code');
        }

        return self::$projectCodeCache[$projectId];
    }

    /** @return array{kode:string,project_id:int}|null */
    private static function lokasiFor(?int $lokasiId): ?array
    {
        if (! $lokasiId) {
            return null;
        }
        if (! array_key_exists($lokasiId, self::$lokasiCache)) {
            $row = Lokasi::query()->withoutGlobalScopes()->whereKey($lokasiId)->first(['kode', 'project_id']);
            self::$lokasiCache[$lokasiId] = $row ? ['kode' => $row->kode, 'project_id' => (int) $row->project_id] : null;
        }

        return self::$lokasiCache[$lokasiId];
    }

    private static function tahapKodeFor(?int $tahapId): ?string
    {
        if (! $tahapId) {
            return null;
        }
        if (! array_key_exists($tahapId, self::$tahapKodeCache)) {
            self::$tahapKodeCache[$tahapId] = Tahap::query()->withoutGlobalScopes()->whereKey($tahapId)->value('kode');
        }

        return self::$tahapKodeCache[$tahapId];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function tahapMaster(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function reservasi(): HasMany
    {
        return $this->hasMany(Reservasi::class);
    }

    public function kontrak(): HasMany
    {
        return $this->hasMany(Kontrak::class);
    }

    /** @return HasMany<UnitPricelist, $this> */
    public function pricelists(): HasMany
    {
        return $this->hasMany(UnitPricelist::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->kode_unit
            ?: trim(($this->blok ? $this->blok.' / ' : '').$this->no_unit);
    }
}
