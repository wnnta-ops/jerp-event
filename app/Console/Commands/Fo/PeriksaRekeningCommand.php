<?php

namespace App\Console\Commands\Fo;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periksa (dan kalau diminta, betulkan) kolom bank & nomor rekening yang terbalik.
 *
 * Daftar agen dimasukkan dari Excel dengan kedua kolomnya tertukar. Model Agent
 * sudah punya penjaga di saving(), tapi penjaga itu TIDAK kena kalau data masuk
 * lewat SQL mentah / restore dump / seeder yang menulis langsung ke tabel —
 * dan justru begitulah data di server test dulu masuk.
 *
 * fo_blt & fo_komisi ikut diperiksa karena keduanya MENYALIN rekening dari agen
 * saat Generate/Pengajuan, dan cetakannya mendahulukan salinan itu:
 *   $rek = $k->rek_number ?: $a?->rekening_number
 * Jadi membetulkan fo_agents saja tidak membetulkan cetakan baris lama.
 *
 * HANYA menukar yang tidak ambigu: kolom bank angka murni SEKALIGUS kolom
 * rekening bukan angka murni. Yang dua-duanya angka, dua-duanya huruf, atau
 * ada yang kosong, dilaporkan sebagai "campur" dan tidak disentuh — menebak di
 * situ berarti merusak baris yang sebetulnya sudah benar.
 *
 * Aman dijalankan berulang: sesudah dibetulkan, barisnya tidak lagi memenuhi
 * syarat, jadi jalan kedua tidak mengubah apa-apa.
 */
class PeriksaRekeningCommand extends Command
{
    protected $signature = 'fo:rekening-periksa
        {--perbaiki : Benar-benar menukar. Tanpa ini hanya menampilkan hitungannya.}';

    protected $description = 'Periksa kolom bank & no. rekening yang terbalik di fo_agents, fo_blt, fo_komisi';

    /** @var array<string,array{0:string,1:string}> tabel => [kolom bank, kolom nomor] */
    private const TABEL = [
        'fo_agents' => ['rekening_bank', 'rekening_number'],
        'fo_blt' => ['rek_bank', 'rek_number'],
        'fo_komisi' => ['rek_bank', 'rek_number'],
    ];

    public function handle(): int
    {
        $tulis = (bool) $this->option('perbaiki');
        $baris = [];
        $totalTukar = 0;

        foreach (self::TABEL as $tabel => [$kolomBank, $kolomRek]) {
            $tertukar = [];
            $campur = 0;
            $benar = 0;

            DB::table($tabel)->select('id', $kolomBank, $kolomRek)
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$tertukar, &$campur, &$benar, $kolomBank, $kolomRek) {
                    foreach ($rows as $r) {
                        $bank = trim((string) $r->$kolomBank);
                        $rek = trim((string) $r->$kolomRek);

                        if ($bank === '' && $rek === '') {
                            continue;
                        }

                        if ($bank !== '' && $rek !== '' && ctype_digit($bank) && ! ctype_digit($rek)) {
                            $tertukar[$r->id] = [$rek, $bank];   // [bank benar, nomor benar]
                        } elseif ($bank !== '' && $rek !== '' && ! ctype_digit($bank) && ctype_digit($rek)) {
                            $benar++;
                        } else {
                            $campur++;
                        }
                    }
                });

            $totalTukar += count($tertukar);
            $baris[] = [$tabel, count($tertukar), $benar, $campur];

            if ($tulis && $tertukar !== []) {
                DB::transaction(function () use ($tabel, $kolomBank, $kolomRek, $tertukar) {
                    foreach ($tertukar as $id => [$bankBenar, $rekBenar]) {
                        DB::table($tabel)->where('id', $id)
                            ->update([$kolomBank => $bankBenar, $kolomRek => $rekBenar]);
                    }
                });
            }
        }

        $this->newLine();
        $this->table(['Tabel', 'Tertukar', 'Sudah benar', 'Campur (dilewati)'], $baris);

        if ($totalTukar === 0) {
            $this->info('  Tidak ada yang terbalik.');

            return self::SUCCESS;
        }

        if (! $tulis) {
            $this->newLine();
            $this->warn('  HITUNGAN saja — tidak ada yang ditulis. Tambahkan --perbaiki untuk menerapkan.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("  {$totalTukar} baris ditukar.");
        $this->line('  Kolom "campur" perlu dilihat manual — sebagian nomornya rusak notasi ilmiah Excel');
        $this->line('  dan digitnya sudah hilang; itu harus ditanyakan ulang ke agennya, jangan ditebak.');

        return self::SUCCESS;
    }
}
