<?php

namespace App\Models\Bo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Grup Akses (role) milik aplikasi BO / Java Core System — tabel `roles` di
 * database `ipro_bo`. BUKAN role FO (itu Spatie\Role di DB jerp). Dikelola
 * lintas-DB dari panel System agar admin cukup satu tempat.
 *
 * Catatan: penulisan langsung ke tabel spatie BO ini melewati cache izin app BO.
 * Karena itu TTL cache tersebut diperpendek jadi 60 detik (config/permission.php
 * di ipro-fo) — J-ERP tidak bisa membatalkannya sendiri: kedua aplikasi hanya
 * berbagi MySQL, Redis-nya dua instance terpisah dan jaringannya tidak tersambung.
 */
class BoRole extends Model
{
    protected $connection = 'ipro_bo';

    protected $table = 'roles';

    protected $fillable = ['tenant_id', 'name', 'description', 'guard_name', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    /** Izin (permission) yang tergabung pada grup ini — pivot role_has_permissions. */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(BoPermission::class, 'role_has_permissions', 'role_id', 'permission_id');
    }

    /**
     * Pengguna BO yang memegang grup ini — pivot model_has_roles (polymorphic).
     * BO memakai morphMap alias 'user' untuk model_type.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(BoUser::class, 'model_has_roles', 'role_id', 'model_id')
            ->withPivotValue('model_type', 'user');
    }
}
