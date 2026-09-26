<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Akun COA bertanda Komponen COGS yang DIAKUI milik satu periode COGS Baru.
 *
 * Tandanya melekat pada AKUN, sedangkan bagan akun di sini memisahkan cluster di tingkat akun
 * (CIP TANAH C3 KRIAN dan CIP TANAH C5 SUKODONO sama-sama bertanda Tanah). Tanpa pengakuan
 * per periode, mutasi cluster lain ikut dibebankan ke unit cluster ini — angkanya tetap keluar
 * dan tidak ada satu pun galat.
 */
class BsCogs2Akun extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs2_akun';

    protected $fillable = ['tenant_id', 'periode_id', 'account_id'];

    protected $casts = ['periode_id' => 'integer', 'account_id' => 'integer'];
}
