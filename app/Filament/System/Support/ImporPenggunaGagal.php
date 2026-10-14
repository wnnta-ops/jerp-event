<?php

namespace App\Filament\System\Support;

use RuntimeException;

/**
 * Import Excel Pengguna ditolak. Membawa daftar galat TERSTRUKTUR — baris, kolom, pesan —
 * supaya layar bisa menunjukkan persis sel mana yang harus dibetulkan, bukan satu kalimat
 * panjang yang harus diurai sendiri oleh admin.
 */
class ImporPenggunaGagal extends RuntimeException
{
    /** @param list<array{baris:string,kolom:string,pesan:string}> $galat */
    public function __construct(public readonly array $galat, string $ringkas = 'Import dibatalkan, tidak ada data yang diubah.')
    {
        parent::__construct($ringkas);
    }

    /** Galat tunggal yang tidak terikat ke satu baris (berkas rusak, judul tak dikenal, …). */
    public static function berkas(string $pesan): self
    {
        return new self([['baris' => '—', 'kolom' => '—', 'pesan' => $pesan]], $pesan);
    }
}
