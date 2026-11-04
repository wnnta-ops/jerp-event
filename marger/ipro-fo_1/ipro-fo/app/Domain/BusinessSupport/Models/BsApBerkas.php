<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class BsApBerkas extends Model
{
    /** Jenis dokumen tetap di halaman unggah supplier (urut tampil). LAINNYA wajib diberi keterangan. */
    public const KODE_SUPPLIER = ['INVOICE', 'KUITANSI', 'FAKTUR-PAJAK', 'LAINNYA'];

    public const KODE_LAINNYA = 'LAINNYA';

    /** Jenis dokumen tetap di drop box unggah (supplier & Upload File invoice): Invoice, Kuitansi, Faktur Pajak, Lainnya. */
    public static function jenisTetap(): \Illuminate\Support\Collection
    {
        return static::query()->where('is_active', true)->whereIn('code', self::KODE_SUPPLIER)
            ->get(['id', 'code', 'name', 'is_mandatory'])
            ->sortBy(fn ($j) => array_search($j->code, self::KODE_SUPPLIER, true))->values();
    }

    /**
     * Rapikan kiriman drop box: file berjenis "Lainnya" WAJIB diberi keterangan (isi dokumennya);
     * jenis lain keterangannya dibuang.
     *
     * @param  list<array{file:\Illuminate\Http\UploadedFile, berkas_id:int|string, keterangan?:?string}>  $files
     * @return list<array{file:\Illuminate\Http\UploadedFile, berkas_id:int, keterangan:?string}>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function rapikanUnggahan(array $files): array
    {
        $lainnya = (int) static::query()->where('code', self::KODE_LAINNYA)->value('id');
        $out = [];
        foreach (array_values($files) as $i => $f) {
            $ket = trim((string) ($f['keterangan'] ?? ''));
            if ((int) $f['berkas_id'] === $lainnya && $ket === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(["files.{$i}.keterangan" => 'Tulis isi dokumen untuk file "Lainnya" ('.$f['file']->getClientOriginalName().').']);
            }
            $out[] = ['file' => $f['file'], 'berkas_id' => (int) $f['berkas_id'], 'keterangan' => (int) $f['berkas_id'] === $lainnya ? $ket : null];
        }

        return $out;
    }

    use HasTenant;

    protected $table = 'bs_ap_berkas';
    protected $fillable = ['tenant_id', 'code', 'name', 'is_mandatory', 'is_active'];
    protected $casts = ['is_mandatory' => 'boolean', 'is_active' => 'boolean'];
}
