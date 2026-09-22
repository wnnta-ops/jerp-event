<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Penanda "Memo Rekom Batal sudah dibuat untuk SPR ini".
 *
 * Satu-satunya pembacanya di luar modul KPR adalah tombol "Ajukan Pembatalan" pada
 * Pembatalan Kontrak di aplikasi Front Office, yang membacanya lewat koneksi ipro_bo
 * dengan kunci spr_number = fo_kontrak.no_kontrak.
 */
class KprCancelRecommendation extends Model
{
    use HasTenant;

    /** Dibuat dari layar Reject BI Checking (sumbernya inisiasi KPR). */
    public const SUMBER_BI = 'reject_bi';

    /** Dibuat dari layar Reject Proses KPR (sumbernya pengajuan KPR). */
    public const SUMBER_KPR = 'reject_kpr';

    protected $fillable = [
        'tenant_id', 'booking_id', 'spr_number', 'source', 'source_id', 'reason',
        'recommended_at', 'recommended_by', 'recommended_by_name',
    ];

    protected $casts = ['recommended_at' => 'datetime'];
}
