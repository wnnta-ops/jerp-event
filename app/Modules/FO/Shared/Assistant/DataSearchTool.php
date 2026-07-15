<?php

namespace App\Modules\FO\Shared\Assistant;

use App\Models\User;

/**
 * Tool generik "cari_data" — pencarian READ-ONLY lintas entitas FO.
 *
 * Model memilih `entitas` (dari registry) + filter generik. Gerbang izin dicek
 * per entitas sebelum query dijalankan. Tak ada kemampuan menulis apa pun.
 */
class DataSearchTool
{
    public const NAME = 'cari_data';

    public function __construct(private readonly SearchableRegistry $registry) {}

    public function definition(): array
    {
        $daftar = collect($this->registry->all())
            ->map(fn (SearchableEntity $e) => $e->key().' = '.$e->label().' ('.$e->hint().')')
            ->implode('; ');

        return [
            'name' => self::NAME,
            'description' => 'Cari & tampilkan data Front Office (READ-ONLY; tak bisa membuat/mengubah/menghapus). '
                .'Pilih `entitas` lalu beri filter. Entitas tersedia: '.$daftar.'. '
                .'Hasil ditampilkan sebagai tabel ke user & dapat diunduh Excel. Setelah tool memberi hasil, '
                .'ringkas jumlahnya dan ingatkan user bisa menekan tombol Download Excel.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'entitas' => ['type' => 'string', 'enum' => $this->registry->keys(), 'description' => 'Jenis data yang dicari.'],
                    'dari' => ['type' => 'string', 'description' => 'Tanggal awal periode (YYYY-MM-DD).'],
                    'sampai' => ['type' => 'string', 'description' => 'Tanggal akhir periode (YYYY-MM-DD).'],
                    'proyek' => ['type' => 'string', 'description' => 'Nama atau kode proyek.'],
                    'status' => ['type' => 'string', 'description' => 'Status; maknanya tergantung entitas (mis. BOOKING/SPR/BATAL, LUNAS/NUNGGAK).'],
                    'q' => ['type' => 'string', 'description' => 'Kata kunci bebas: nama, nomor, atau kode.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maksimum baris (default 100, maks 1000).'],
                ],
                'required' => ['entitas'],
            ],
        ];
    }

    /**
     * Jalankan pencarian. Kembalikan SearchResult (sukses) atau array error.
     *
     * @param  array<string,mixed>  $input
     * @return SearchResult|array<string,mixed>
     */
    public function run(array $input, ?User $user): SearchResult|array
    {
        $key = (string) ($input['entitas'] ?? '');
        $entity = $this->registry->get($key);

        if (! $entity) {
            return ['error' => 'entitas_tidak_dikenal', 'pesan' => 'Entitas tidak dikenal. Pilihan: '.implode(', ', $this->registry->keys())];
        }

        if (! AssistantAccess::allows($user, $entity->permission())) {
            return ['error' => 'akses_ditolak', 'pesan' => 'Pengguna tidak memiliki hak akses untuk membaca '.$entity->label().' ('.$entity->permission().').'];
        }

        return $entity->search($input);
    }
}
