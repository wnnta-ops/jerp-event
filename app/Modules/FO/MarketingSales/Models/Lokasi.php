<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Setup Unit — master Lokasi (per Project) = "cluster". Kode dipakai sebagai
 * segmen pertama No. Unit kanonik (Lokasi-Tahap-Blok-Nomer). Tiap Lokasi bisa
 * punya siteplan sendiri; kosong → fallback ke siteplan project.
 */
class Lokasi extends TenantAwareModel
{
    protected $table = 'fo_lokasi';

    protected $fillable = [
        'tenant_id', 'project_id', 'kode', 'nama', 'is_active',
        'siteplan_image_path', 'siteplan_width', 'siteplan_height',
        'siteplan_show_info', 'siteplan_show_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'siteplan_width' => 'integer',
        'siteplan_height' => 'integer',
        'siteplan_show_info' => 'boolean',
        'siteplan_show_status' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** True bila Lokasi ini punya gambar siteplan sendiri (bukan fallback). */
    public function hasOwnSiteplan(): bool
    {
        return ! empty($this->siteplan_image_path);
    }

    /**
     * Siteplan efektif: gambar Lokasi kalau ada, else fallback ke project.
     *
     * @return array{url:string,width:int,height:int}|null
     */
    public function resolvedSiteplan(): ?array
    {
        $src = $this->hasOwnSiteplan() ? $this : $this->project;
        if (! $src || ! $src->siteplan_image_path) {
            return null;
        }

        return [
            'url' => Storage::disk('public')->url($src->siteplan_image_path),
            ...self::siteplanDims($src->siteplan_image_path, (int) $src->siteplan_width, (int) $src->siteplan_height),
        ];
    }

    /**
     * Dimensi kerangka siteplan. Kolom width/height kosong/0 dulu ditebak 2000×1200 —
     * hampir pasti beda dari kerangka digitasi koordinat → polygon/stempel tersebar
     * keluar gambar (bug Pilih Unit). Kini self-heal: baca dimensi ASLI file gambar
     * (kerangka digitasi = ukuran natural gambar). Kolom terisi tetap dihormati.
     *
     * @return array{width:int,height:int}
     */
    public static function siteplanDims(string $imagePath, int $width, int $height): array
    {
        if ($width < 1 || $height < 1) {
            try {
                $file = Storage::disk('public')->path($imagePath);
                if (is_file($file) && ($size = @getimagesize($file)) && $size[0] > 0 && $size[1] > 0) {
                    return ['width' => $size[0], 'height' => $size[1]];
                }
            } catch (\Throwable) {
                // fallback di bawah
            }
        }

        return ['width' => $width ?: 2000, 'height' => $height ?: 1200];
    }

    /**
     * Flag tampilan SELALU per-Lokasi (default true), terlepas dari apakah cluster
     * punya gambar sendiri atau fallback. Tiap cluster atur tampilannya sendiri.
     */
    public function resolvedShowInfo(): bool
    {
        return (bool) $this->siteplan_show_info;
    }

    public function resolvedShowStatus(): bool
    {
        return (bool) $this->siteplan_show_status;
    }

    public function tahap(): HasMany
    {
        return $this->hasMany(Tahap::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
