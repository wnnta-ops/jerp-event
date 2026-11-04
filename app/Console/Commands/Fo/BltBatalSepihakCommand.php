<?php

namespace App\Console\Commands\Fo;

use App\Modules\FO\Komisi\Models\Blt;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\Komisi\Services\BltBatalKontrak;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\HitungPembatalan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak BATAL yang batalnya tidak lewat Approval (mis. sinkron eProperty): hapus BLT/Komisi
 * DRAFT-nya dan — kecuali --tanpa-blt — terbitkan BLT Batal Sepihak untuk sales-nya.
 * Sama dengan yang dikerjakan Approval pembatalan (BltBatalKontrak).
 */
class BltBatalSepihakCommand extends Command
{
    protected $signature = 'fo:blt-batal-sepihak
        {no_kontrak* : Nomor kontrak BATAL (boleh lebih dari satu)}
        {--tanpa-blt : Hanya hapus BLT/Komisi DRAFT, tanpa BLT Batal Sepihak (batal bukan sepihak)}
        {--jalankan : Benar-benar menulis ke database. Tanpa ini hanya menampilkan rencananya.}';

    protected $description = 'Kontrak BATAL: hapus BLT/Komisi DRAFT + terbitkan BLT Batal Sepihak';

    public function handle(BltBatalKontrak $svc, HitungPembatalan $hitung): int
    {
        $tulis = (bool) $this->option('jalankan');
        $sepihak = ! $this->option('tanpa-blt');
        $h = $hitung->bltBatalSepihak();

        foreach ((array) $this->argument('no_kontrak') as $no) {
            $k = Kontrak::query()->with(['agent', 'customer'])->where('no_kontrak', $no)->first();
            if (! $k) {
                $this->error("$no: tidak ditemukan.");

                continue;
            }
            if ($k->status !== 'BATAL') {
                $this->error("$no: status {$k->status}, bukan BATAL — dilewati.");

                continue;
            }

            $this->info("$no | {$k->customer?->full_name} | sales: ".($k->agent?->name ?? '-'));

            if (! $tulis) {
                $rencana = [];
                foreach (Blt::query()->where('kontrak_id', $k->id)->where('keterangan', 'not like', BltBatalKontrak::KETERANGAN_SEPIHAK.'%')->get() as $b) {
                    $rencana[] = ['BLT', $b->no_blt, number_format((float) $b->nilai_blt, 0, ',', '.'), $b->status, $b->status === 'DRAFT' ? 'HAPUS' : 'biarkan — urus manual'];
                }
                foreach (Komisi::query()->where('kontrak_id', $k->id)->get() as $m) {
                    $rencana[] = ['Komisi', $m->no_komisi, number_format((float) $m->amount, 0, ',', '.'), $m->status, $m->status === 'DRAFT' ? 'HAPUS' : 'biarkan — urus manual'];
                }
                if ($sepihak) {
                    $rencana[] = ['BLT', '(baru) Batal Sepihak', number_format($h['nilai'], 0, ',', '.').' / nett '.number_format($h['nett'], 0, ',', '.'), 'DRAFT',
                        ! $k->agent ? 'DILEWATI — tanpa sales' : ($svc->sudahAdaSepihak($k) ? 'DILEWATI — sudah ada' : 'BUAT')];
                }
                $this->table(['jenis', 'nomor', 'nilai', 'status', 'tindakan'], $rencana ?: [['-', '-', '-', '-', 'tidak ada']]);

                continue;
            }

            $pesan = DB::transaction(function () use ($svc, $k, $sepihak) {
                $s = BltBatalKontrak::pesan($svc->bersihkan($k));

                return $s.($sepihak ? $svc->terbitkanSepihak($k) : '');
            });
            $this->line('  '.(trim($pesan) ?: 'Tidak ada yang diubah.'));
        }

        if (! $tulis) {
            $this->warn('Belum ada yang ditulis. Jalankan ulang dengan --jalankan.');
        }

        return self::SUCCESS;
    }
}
