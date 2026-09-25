<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu pos biaya pada Set Up COGS (LAC Tanah, Infrastruktur, Undertable, License Permit, …).
 *
 * `dasar` menentukan pembaginya: 'tanah' dibagi luas tanah, 'bangunan' dibagi luas bangunan.
 * Inilah satu-satunya alasan modul ini bisa mengakomodir kedua luas sekaligus.
 *
 * Pos biaya di sini SENGAJA tidak dipetakan ke kosakata komponen HPP (tanah|bangunan|sarana|
 * permit|…). Dua alasannya: (1) lampiran memuat "Undertable" yang tidak punya padanan, dan
 * satu-satunya slot netral 'lain' justru DIPAKSA menjadi 'bangunan' oleh CogsService, sehingga
 * pemetaan itu akan memindahkan miliaran rupiah ke komponen yang salah tanpa galat; (2) kolom
 * pemetaan yang tidak pernah dibaca kode mana pun adalah jebakan yang sudah ada contohnya di
 * modul ini — bs_cogs_provisions.basis menyediakan pilihan "Luas" yang tersimpan rapi dan tidak
 * berpengaruh apa pun.
 */
class BsCogsSetupComponent extends Model
{
    use HasTenant;

    public const DASAR = ['tanah' => 'Luas Tanah', 'bangunan' => 'Luas Bangunan'];

    protected $table = 'bs_cogs_setup_components';

    protected $fillable = ['tenant_id', 'setup_id', 'nama', 'dasar', 'nilai', 'urut'];

    protected $casts = ['setup_id' => 'integer', 'nilai' => 'decimal:2', 'urut' => 'integer'];
}
