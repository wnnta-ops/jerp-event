<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan cetak SPR Bank. Satu baris per pendaftaran — lihat alasannya di
 * migrasi create_fo_spr_bank_prints_table.
 */
class SprBankPrint extends TenantAwareModel
{
    protected $table = 'fo_spr_bank_prints';

    protected $fillable = [
        'tenant_id', 'nup_registration_id', 'no_surat',
        'jumlah_cetak', 'isi_html', 'pertama_cetak_at', 'terakhir_cetak_at', 'terakhir_cetak_by',
    ];

    protected $casts = [
        'pertama_cetak_at' => 'datetime',
        'terakhir_cetak_at' => 'datetime',
        'jumlah_cetak' => 'integer',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(NupRegistration::class, 'nup_registration_id');
    }

    public function pencetak(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terakhir_cetak_by');
    }
}
