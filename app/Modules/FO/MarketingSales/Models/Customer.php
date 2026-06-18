<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SumberData;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends TenantAwareModel
{
    protected $table = 'fo_customers';

    protected static function booted(): void
    {
        parent::booted();
        // No. Customer otomatis (mis. saat daftar customer baru via Pengalihan Hak).
        static::creating(function (self $m) {
            if (empty($m->code)) {
                $m->code = app(NumeratorService::class)->next('CUST');
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'project_id',
        'full_name',
        'nik',
        'npwp',
        'birth_date',
        'birth_place',
        'gender',
        'marital_status',
        'religion',
        'phone',
        'mobile',
        'email',
        'bank_id',
        'no_rekening',
        'atas_nama_rekening',
        'address',
        'jalan',
        'rt_rw',
        'kelurahan',
        'kecamatan',
        'city',
        'province',
        'postal_code',
        'mail_same_as_ktp',
        'mail_address',
        'mail_city',
        'mail_kecamatan',
        'mail_kelurahan',
        'mail_province',
        'mail_postal_code',
        'spouse_full_name',
        'spouse_nik',
        'spouse_email',
        'spouse_phone',
        'spouse_birth_place',
        'spouse_birth_date',
        'spouse_occupation',
        'spouse_address_same_as_pemohon',
        'spouse_address',
        'ktp_file_path',
        'npwp_file_path',
        'ktp_pasangan_file_path',
        'sumber_data',
        'lead_status',
        'occupation',
        'company_name',
        'income_monthly',
        'is_active',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'spouse_birth_date' => 'date',
        'mail_same_as_ktp' => 'boolean',
        'spouse_address_same_as_pemohon' => 'boolean',
        'income_monthly' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Bank::class);
    }

    public function nupRegistrations(): HasMany
    {
        return $this->hasMany(NupRegistration::class);
    }

    public function reservasi(): HasMany
    {
        return $this->hasMany(Reservasi::class);
    }

    public function kontrak(): HasMany
    {
        return $this->hasMany(Kontrak::class);
    }

    /** Alamat ringkas untuk tampilan (Pilih Unit popup, kartu QR). */
    public function alamatRingkas(): string
    {
        $parts = array_filter([
            $this->jalan ?: $this->address,
            $this->rt_rw ? 'RT/RW '.$this->rt_rw : null,
            $this->kelurahan,
            $this->kecamatan,
            $this->city,
            $this->province,
        ]);

        return $parts ? implode(', ', $parts) : '—';
    }

    /**
     * Label Sumber Data dari master (mis. kode "INSTAGRAM" → "Instagram"). Dipakai di
     * printout SPRS. Fallback ke kode mentah bila tak ada di master; null bila kosong.
     */
    public function getSumberDataLabelAttribute(): ?string
    {
        if (blank($this->sumber_data)) {
            return null;
        }

        return SumberData::query()
            ->where('kode', $this->sumber_data)
            ->value('nama') ?? $this->sumber_data;
    }
}
