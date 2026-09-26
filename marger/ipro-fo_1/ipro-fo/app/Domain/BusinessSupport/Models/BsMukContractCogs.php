<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tautan Kontrak SPK (MUK) → Komponen COGS.
 *
 * Berdiri sebagai tabel sendiri, BUKAN kolom pada bs_muk_contracts: tabel itu salinan dari MUK
 * ERP yang barisnya ditimpa tiap penarikan ulang, sehingga kolom milik kita yang menumpang di
 * sana akan lenyap diam-diam bersama seluruh penautan yang sudah dikerjakan operator.
 *
 * Kuncinya muk_id — identitas kontrak di MUK ERP, satu-satunya yang stabil melintasi penarikan
 * ulang.
 */
class BsMukContractCogs extends Model
{
    use HasTenant;

    protected $table = 'bs_muk_contract_cogs';

    protected $fillable = ['tenant_id', 'muk_id', 'component', 'kategori', 'no_kontrak', 'linked_by', 'linked_by_name'];

    protected $casts = ['muk_id' => 'integer'];
}
