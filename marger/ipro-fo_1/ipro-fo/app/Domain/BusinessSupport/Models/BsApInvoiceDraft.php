<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Isian wizard Registrasi Invoice Hutang yang disimpan sementara (satu per user per jenis).
 *
 * @property int $user_id
 * @property string $jenis
 * @property int|null $berkas_upload_id
 * @property int $step
 * @property array<string,mixed> $data
 */
class BsApInvoiceDraft extends Model
{
    use HasTenant;

    protected $fillable = ['user_id', 'jenis', 'berkas_upload_id', 'step', 'data'];

    protected $casts = ['data' => 'array', 'step' => 'integer'];
}
