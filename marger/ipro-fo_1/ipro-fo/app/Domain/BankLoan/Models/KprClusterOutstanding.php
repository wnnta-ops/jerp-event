<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris "Outstanding (O/S) KPR per Cluster & Tahap" — diisi lewat import Exsum.
 *
 * Keempat kolom nilainya TEKS, bukan angka: isinya "Rp 1,2 M (2 unit RUHA)" atau "Clear".
 * Angkanya selalu datang bersama keterangan unitnya, dan memaksanya menjadi desimal akan
 * membuang justru bagian yang membuatnya bisa dibaca.
 */
class KprClusterOutstanding extends Model
{
    use HasTenant;

    protected $table = 'kpr_cluster_outstanding';

    protected $fillable = [
        'tenant_id', 'urutan', 'label', 'retensi', 'proses', 'open_stok',
        'lain_lain', 'catatan', 'created_by',
    ];

    protected $casts = ['urutan' => 'integer'];
}
