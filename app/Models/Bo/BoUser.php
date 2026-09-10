<?php

namespace App\Models\Bo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengguna aplikasi BO / Java Core System — tabel `users` di database
 * `ipro_bo`. Di sini hanya untuk MELIHAT pengguna & MENGATUR grup aksesnya
 * (read-only atas akun; pembuatan/penghapusan akun tetap di app BO).
 */
class BoUser extends Model
{
    use SoftDeletes;

    protected $connection = 'ipro_bo';

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    /** Grup akses (role) BO yang dimiliki — pivot model_has_roles, morph 'user'. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(BoRole::class, 'model_has_roles', 'model_id', 'role_id')
            ->withPivotValue('model_type', 'user');
    }

    /**
     * Izin KHUSUS pengguna ini di BO — di luar grup aksesnya.
     *
     * Padanan "Hak Akses Khusus" milik FO: untuk orang yang butuh satu-dua menu
     * di luar grupnya tanpa harus dibuatkan grup baru. Akses efektif di BO =
     * gabungan izin dari grup + izin di sini.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(BoPermission::class, 'model_has_permissions', 'model_id', 'permission_id')
            ->withPivotValue('model_type', 'user');
    }
}
