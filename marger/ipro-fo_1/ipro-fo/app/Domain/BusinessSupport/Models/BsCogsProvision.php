<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** COGS Setup → Ketentuan (akun & dasar generate HPP per project). */
class BsCogsProvision extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_provisions';
    protected $fillable = [
        'tenant_id', 'project_id', 'acc_uang_muka_id', 'acc_pendapatan_id', 'acc_piutang_id',
        'acc_cip_id', 'acc_persediaan_unit_id', 'acc_hpp_id', 'basis', 'notes',
    ];
}
