<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'full_name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Audit trail untuk perubahan akun (buat/ubah/hapus user, ganti role lewat
     * field). Password & metadata login terakhir TIDAK dicatat di sini — login
     * & logout direkam terpisah oleh LogAuthenticationActivity.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['password', 'tenant_id', 'last_login_at', 'last_login_ip'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user')
            ->setDescriptionForEvent(fn (string $event): string => 'User '.match ($event) {
                'created' => 'dibuat',
                'updated' => 'diubah',
                'deleted' => 'dihapus',
                default => $event,
            });
    }

    /**
     * Filament gate — every authenticated user may enter any panel for now.
     * Tighten later via role checks (e.g. only `it_admin` can enter System).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active;
    }
}
