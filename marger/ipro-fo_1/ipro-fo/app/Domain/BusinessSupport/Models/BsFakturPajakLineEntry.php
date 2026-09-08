<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Nomor Faktur Pajak & NPWP untuk satu baris jurnal berakun PPN (Masukan atau Keluaran).
 *
 * Barisnya sendiri BUKAN milik tabel ini — ia milik buku besar. Yang disimpan di sini hanya dua
 * keterangan yang tidak ada di jurnal: nomor faktur pajaknya dan NPWP yang tertera pada faktur
 * itu. Karena itu menghapus entri ini tidak menghapus baris jurnalnya; yang hilang hanya kedua
 * keterangan tersebut, dan barisnya kembali kosong siap diisi ulang.
 */
class BsFakturPajakLineEntry extends Model
{
    use HasTenant;

    public const MASUKAN = 'masukan';

    public const KELUARAN = 'keluaran';

    protected $table = 'bs_faktur_pajak_line_entries';

    protected $fillable = ['tenant_id', 'jenis', 'journal_line_id', 'journal_id', 'fp_number', 'npwp', 'created_by'];
}
