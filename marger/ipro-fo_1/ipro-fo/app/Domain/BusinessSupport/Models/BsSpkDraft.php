<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Draft/template default dokumen Cetak SPK (Set Up SPK). Satu baris per tenant.
 */
class BsSpkDraft extends Model
{
    use HasTenant;

    protected $table = 'bs_spk_drafts';

    /** Kolom kepala dokumen (proyek_line … pihak2_teks) mengikuti draft SPK asli MUP. */
    protected $fillable = [
        'tenant_id', 'jenis', 'judul', 'subjudul', 'proyek_line', 'judul_pekerjaan',
        'tempat', 'pembukaan', 'mukadimah', 'pihak1_teks', 'pihak2_teks', 'pasals', 'updated_by',
    ];

    /** Kolom isi template yang dipakai Cetak SPK (dipakai controller saat menyusun payload). */
    public const CONTENT_FIELDS = [
        'judul', 'subjudul', 'proyek_line', 'judul_pekerjaan', 'tempat',
        'pembukaan', 'mukadimah', 'pihak1_teks', 'pihak2_teks', 'pasals',
    ];

    protected $casts = [
        'pasals' => 'array',
    ];
}
