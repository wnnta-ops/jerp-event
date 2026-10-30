<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasAuditTrail, HasFactory, HasRoles, HasTenant, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'username', 'phone', 'password',
        'avatar_path', 'signature_path', 'signature_updated_at', 'employee_nip', 'is_active', 'is_blocked',
        'dark_mode', 'locale',
        'failed_login_count', 'blocked_until', 'last_login_at', 'last_login_ip',
        'password_changed_at', 'email_verified_at',
    ];

    // signature_path = lokasi file di disk privat; ke frontend cukup URL route users.signature.
    protected $hidden = ['password', 'remember_token', 'signature_path'];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password_changed_at'  => 'datetime',
            'last_login_at'        => 'datetime',
            'blocked_until'        => 'datetime',
            'signature_updated_at' => 'datetime',
            'is_active'            => 'boolean',
            'is_blocked'           => 'boolean',
            'password'             => 'hashed',
        ];
    }

    /** URL gambar TTD (route users.signature, berversi agar cache terganti saat diganti); null bila belum ada. */
    public function signatureUrl(): ?string
    {
        return $this->signature_path
            ? route('users.signature', ['user' => $this->id, 'v' => $this->signature_updated_at?->timestamp])
            : null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Foundation\Tenant\Models\Project::class, 'user_projects');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Foundation\Tenant\Models\Company::class, 'user_companies');
    }

    public function mfaSecret(): HasOne
    {
        return $this->hasOne(\App\Domain\Foundation\Tenant\Models\MfaSecret::class);
    }

    /**
     * Akses level-MODUL back-office: TRUE bila punya izin umum `businesssupport.view`
     * ATAU setidaknya satu permission granular pada modul tsb (mis. 'ap', 'contractor',
     * 'gl', 'cashbook', …). Dipakai menggantikan cek `businesssupport.view` (& transaksi)
     * di controller agar role granular bisa membuka & memakai modulnya.
     */
    /**
     * @deprecated Tidak lagi dipakai controller mana pun sejak setiap aksi dijaga
     * izinnya sendiri. DIPERTAHANKAN sengaja, TIDAK dihapus: 'businesssupport.view'
     * di dalamnya berlaku sebagai kunci induk yang membuka SELURUH modul back-office
     * sekaligus. Menghapus metodenya tanpa mencabut izin itu dari peran yang
     * memegangnya hanya memindahkan kejutannya ke tempat lain. Cabut izinnya dulu
     * dari Grup Akses BO, baru metode ini boleh hilang.
     */
    public function canModule(string $module): bool
    {
        if ($this->can('businesssupport.view')) {
            return true;
        }

        return $this->getAllPermissions()->contains(fn ($p) => $p->module === $module);
    }

    /** Akses landing/overview back-office generik: punya `businesssupport.view` ATAU izin granular modul back-office mana pun. */
    public function canBackoffice(): bool
    {
        if ($this->can('businesssupport.view')) {
            return true;
        }
        $mods = ['ap', 'ar', 'gl', 'cashbook', 'budget', 'contractor', 'cogs', 'tax', 'datacenter', 'bs'];

        return $this->getAllPermissions()->contains(fn ($p) => in_array($p->module, $mods, true));
    }
}
