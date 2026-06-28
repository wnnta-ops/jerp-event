<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Models;

use App\Domain\Master\Customer\Enums\Citizenship;
use App\Domain\Master\Customer\Enums\CustomerStatus;
use App\Domain\Master\Customer\Enums\EmergencyRelationship;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Customer\Enums\MaritalStatus;
use App\Domain\Master\Customer\Enums\Religion;
use App\Support\Casts\EncryptedString;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected $fillable = [
        // ── 1. Identitas
        'tenant_id', 'project_id', 'data_source_id', 'code', 'full_name', 'nationality',

        // ── 2. Kartu Identitas
        'id_type', 'id_number', 'id_expires_at', 'id_lifetime',
        'address_line', 'address_rt_rw', 'kelurahan', 'kecamatan',
        'city', 'province', 'postal_code', 'country',

        // ── 3. Data Pribadi
        'dob', 'birth_place', 'gender', 'religion', 'marital_status',

        // ── 4. NPWP
        'npwp', 'npwp_name', 'npwp_same_as_ktp',
        'npwp_address', 'npwp_rt_rw', 'npwp_kelurahan', 'npwp_kecamatan', 'npwp_kota', 'npwp_postal_code',

        // ── 5. Kontak
        'phone', 'phone_alt', 'home_phone', 'fax', 'email',

        // ── 6. Surat Menyurat
        'mailing_same_as_ktp',
        'mailing_address', 'mailing_rt_rw', 'mailing_kelurahan', 'mailing_kecamatan', 'mailing_kota', 'mailing_postal_code',

        // ── 7. Pekerjaan
        'occupation', 'employer_name', 'job_title', 'monthly_income',
        'office_phone', 'office_address', 'office_rt_rw', 'office_kelurahan', 'office_kecamatan', 'office_kota', 'office_postal_code',

        // ── 8. Emergency Contact
        'emergency_name', 'emergency_relationship', 'emergency_phone', 'emergency_email',

        // Misc
        'channel', 'referrer_agent_id', 'status', 'metadata',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        // Enums
        'id_type'                => IdType::class,
        'status'                 => CustomerStatus::class,
        'nationality'            => Citizenship::class,
        'religion'               => Religion::class,
        'marital_status'         => MaritalStatus::class,
        'emergency_relationship' => EmergencyRelationship::class,

        // Encrypted PII
        'id_number'              => EncryptedString::class,
        'npwp'                   => EncryptedString::class,

        // Dates / booleans / decimals
        'dob'                    => 'date',
        'id_expires_at'          => 'date',
        'id_lifetime'            => 'boolean',
        'npwp_same_as_ktp'       => 'boolean',
        'mailing_same_as_ktp'    => 'boolean',
        'monthly_income'         => 'decimal:2',
        'metadata'               => 'array',
    ];

    /**
     * @inheritDoc
     */
    public function auditExcluded(): array
    {
        return ['password', 'remember_token', 'id_number', 'npwp', 'mfa_secret', 'token', 'metadata'];
    }

    // ── Relationships ────────────────────────────────────

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(\App\Domain\Sales\Booking\Models\Booking::class);
    }

    public function referrerAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'referrer_agent_id');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Tenant\Models\Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    // ── Accessors ────────────────────────────────────────

    /**
     * Build effective alamat surat menyurat string (copy KTP if same).
     */
    public function getEffectiveMailingAddressAttribute(): string
    {
        if ($this->mailing_same_as_ktp) {
            return $this->compact_ktp_address;
        }

        return $this->buildAddress(
            $this->mailing_address,
            $this->mailing_rt_rw,
            $this->mailing_kelurahan,
            $this->mailing_kecamatan,
            $this->mailing_kota,
            $this->mailing_postal_code,
        );
    }

    public function getEffectiveNpwpAddressAttribute(): string
    {
        if ($this->npwp_same_as_ktp) {
            return $this->compact_ktp_address;
        }

        return $this->buildAddress(
            $this->npwp_address,
            $this->npwp_rt_rw,
            $this->npwp_kelurahan,
            $this->npwp_kecamatan,
            $this->npwp_kota,
            $this->npwp_postal_code,
        );
    }

    public function getCompactKtpAddressAttribute(): string
    {
        return $this->buildAddress(
            $this->address_line,
            $this->address_rt_rw,
            $this->kelurahan,
            $this->kecamatan,
            $this->city,
            $this->postal_code,
        );
    }

    private function buildAddress(?string $line, ?string $rtRw, ?string $kel, ?string $kec, ?string $kota, ?string $pos): string
    {
        return collect([
            $line,
            $rtRw ? "RT/RW {$rtRw}" : null,
            $kel ? "Kel. {$kel}" : null,
            $kec ? "Kec. {$kec}" : null,
            $kota,
            $pos,
        ])->filter()->implode(', ');
    }
}
