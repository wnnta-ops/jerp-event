<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu file di dalam Upload Berkas; jenisnya (Invoice, Faktur Pajak, …) dari master bs_ap_berkas. */
class BsApBerkasUploadFile extends Model
{
    protected $table = 'bs_ap_berkas_upload_files';

    protected $fillable = ['berkas_upload_id', 'berkas_id', 'original_name', 'path', 'mime', 'size'];

    protected $hidden = ['path'];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(BsApBerkasUpload::class, 'berkas_upload_id');
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(BsApBerkas::class, 'berkas_id');
    }
}
