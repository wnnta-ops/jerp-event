<?php

namespace App\Modules\FO\Shared\Assistant;

/**
 * Hasil eksekusi sebuah tool pencarian.
 *
 * PENTING (privasi): $baris (berisi PII: nama, no HP) DITAHAN di server untuk
 * ditampilkan sebagai tabel & diekspor ke Excel secara lokal. Yang dikirim balik
 * ke model hanyalah JUMLAH + filter + nama kolom (toToolResultJson) — bukan baris
 * datanya — sehingga PII customer tidak ikut keluar ke API AI.
 */
final class SearchResult
{
    /**
     * @param  array<int,string>  $kolom  Judul kolom.
     * @param  array<int,array<string,mixed>>  $baris  Baris data (lengkap, untuk tabel/Excel).
     * @param  array<string,mixed>  $filter  Filter yang benar-benar diterapkan (untuk ditampilkan).
     */
    public function __construct(
        public readonly array $kolom,
        public readonly array $baris,
        public readonly array $filter,
    ) {}

    public function jumlah(): int
    {
        return count($this->baris);
    }

    /** Ringkasan aman-PII untuk dikirim balik ke model (tanpa baris data). */
    public function toToolResultJson(): string
    {
        return (string) json_encode([
            'jumlah' => $this->jumlah(),
            'filter_terpakai' => $this->filter,
            'kolom' => $this->kolom,
            'catatan' => 'Baris detail ditampilkan langsung ke user sebagai tabel + tombol Download Excel; tidak dikirim ke model demi privasi.',
        ], JSON_UNESCAPED_UNICODE);
    }
}
