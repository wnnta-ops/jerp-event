<?php

namespace App\Models;

use App\Support\Akses;
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
        // Data awal karyawan — dipakai IproMasterSync saat MEMBUAT baris bs_employees di BO.
        // Setelah baris itu ada, HR yang memegang datanya dan Accounting cost center-nya;
        // nilai di sini tidak pernah menimpa yang di BO.
        'nik',
        'npwp',
        'bank_id',
        'rekening_number',
        'bo_cost_center_id',
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
    /**
     * Gerbang panel.
     *
     * Sebelumnya hanya memeriksa is_active — artinya SETIAP pengguna aktif bisa
     * membuka semua panel, termasuk System (kelola pengguna & peran) dan Backup
     * (restore database). 61 akun, 39 peran, satu pintu.
     *
     * Izinnya diperiksa lewat Akses agar sakelar MODE LAPOR ikut berlaku di sini:
     * selama pemetaan peran belum ditinjau, yang seharusnya ditolak hanya dicatat,
     * tidak benar-benar mengunci orang.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $izin = config('akses.panel.'.$panel->getId());

        return Akses::boleh($izin, $this);
    }
}
