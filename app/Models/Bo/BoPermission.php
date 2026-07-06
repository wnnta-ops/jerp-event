<?php

namespace App\Models\Bo;

use Illuminate\Database\Eloquent\Model;

/**
 * Izin (permission) milik aplikasi BO / Java Core System — tabel `permissions`
 * di database `ipro_bo`. Punya kolom `module` (pengelompokan) & `label`
 * (nama manusiawi), berbeda dari permission FO yang dot-notation `fo.*`.
 */
class BoPermission extends Model
{
    protected $connection = 'ipro_bo';

    protected $table = 'permissions';

    protected $fillable = ['name', 'module', 'label', 'guard_name'];
}
