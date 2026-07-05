<?php

namespace App\Modules\FO\Shared\Assistant;

/**
 * Satu entitas data FO yang bisa dicari asisten (READ-ONLY).
 *
 * Menambah cakupan data = mendaftarkan implementasi baru di SearchableRegistry,
 * bukan menambah tool. Setiap entitas menyatakan izin bacanya sendiri; gerbang
 * akses dicek di DataSearchTool sebelum search() dipanggil.
 */
interface SearchableEntity
{
    /** Kunci unik dipakai model (mis. 'customer'). */
    public function key(): string;

    /** Nama manusiawi (mis. 'Customer'). */
    public function label(): string;

    /** Izin baca spatie yang wajib dimiliki (mis. 'fo.customer.index'). */
    public function permission(): string;

    /** Petunjuk singkat filter yang didukung — untuk membantu model. */
    public function hint(): string;

    /**
     * Jalankan pencarian READ-ONLY dengan filter generik.
     *
     * @param  array<string,mixed>  $filters  dari, sampai, proyek, status, q, limit
     */
    public function search(array $filters): SearchResult;
}
