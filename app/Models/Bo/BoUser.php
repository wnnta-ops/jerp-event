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
}
