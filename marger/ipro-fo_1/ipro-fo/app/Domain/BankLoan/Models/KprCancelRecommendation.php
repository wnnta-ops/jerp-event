<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Penanda "Memo Rekom Batal sudah dibuat untuk SPR ini".
 *
 * Dibaca tombol "Ajukan Pembatalan" pada Pembatalan Kontrak di aplikasi Front Office
 * lewat koneksi ipro_bo, dengan kunci spr_number = fo_kontrak.no_kontrak.
 *
 * PENULISNYA DUA APLIKASI, bukan satu. Modul KPR di sini menulis untuk SPR ber-KPR
 * (SUMBER_BI / SUMBER_KPR); layar "Rekom Batal Cash" di Front Office menulis untuk SPR
 * cash (SUMBER_CASH). Itulah sebabnya `source` dipakai sebagai penyaring, bukan sekadar
 * catatan: satu SPR hanya boleh punya satu penanda (unik tenant_id+spr_number), sedangkan
 * cara bayar sebuah kontrak MASIH BISA BERUBAH sesudah penandanya terbit — tanpa memeriksa
 * `source`, memo cash akan membuka kunci kontrak yang kini berjalan sebagai KPR.
 */
class KprCancelRecommendation extends Model
{
    use HasTenant;

    /** Dibuat dari layar Reject BI Checking (sumbernya inisiasi KPR). */
    public const SUMBER_BI = 'reject_bi';

    /** Dibuat dari layar Reject Proses KPR (sumbernya pengajuan KPR). */
    public const SUMBER_KPR = 'reject_kpr';

    /**
     * Dibuat dari layar Rekom Batal Cash di Front Office (sumbernya fo_kontrak).
     *
     * `source_id`-nya adalah id fo_kontrak, BUKAN id baris di database ini — satu-satunya
     * id yang dimiliki sisi cash. Muat di varchar(16).
     */
    public const SUMBER_CASH = 'cash_fo';

    /** Penanda yang membuka kunci pembatalan untuk kontrak ber-KPR. */
    public const SUMBER_KPR_SEMUA = [self::SUMBER_BI, self::SUMBER_KPR];

    protected $fillable = [
        'tenant_id', 'booking_id', 'spr_number', 'source', 'source_id', 'reason',
        'recommended_at', 'recommended_by', 'recommended_by_name',
    ];

    protected $casts = ['recommended_at' => 'datetime'];
}
