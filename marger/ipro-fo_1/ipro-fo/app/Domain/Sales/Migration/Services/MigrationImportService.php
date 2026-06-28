<?php

declare(strict_types=1);

namespace App\Domain\Sales\Migration\Services;

use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Domain\Sales\Contract\Models\Contract;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Migrasi data legacy (eProperty SALES → Migrate): Upload Kontrak / Jadwal / Pembayaran.
 * Mencocokkan ke booking yang sudah ada via booking_number.
 */
class MigrationImportService
{
    public const COLUMNS = [
        'jadwal'     => ['booking_number', 'installment_no', 'label', 'due_date', 'amount', 'paid_amount', 'status'],
        'pembayaran' => ['booking_number', 'installment_no', 'amount', 'paid_at'],
        'kontrak'    => ['booking_number', 'contract_number', 'signed_at', 'status'],
    ];

    public function template(string $type): string
    {
        $cols = self::COLUMNS[$type] ?? [];
        $sample = match ($type) {
            'jadwal'     => 'BK/JRES/2606/00001,1,DP,2026-07-01,50000000,0,outstanding',
            'pembayaran' => 'BK/JRES/2606/00001,1,50000000,2026-07-05',
            'kontrak'    => 'BK/JRES/2606/00001,KTR/2026/00001,2026-07-10,signed',
            default      => '',
        };

        return implode(',', $cols)."\n".$sample."\n";
    }

    /**
     * @return array{ok:int, skipped:int, failed:array<int,array{row:int,ref:string,error:string}>}
     */
    public function import(string $type, string $csv, int $userId, bool $dryRun = false): array
    {
        abort_unless(isset(self::COLUMNS[$type]), 422, 'Tipe migrasi tidak dikenal.');
        $reader = Reader::createFromString($csv);
        $reader->setHeaderOffset(0);

        $ok = 0; $skipped = 0; $failed = [];
        $rowNum = 1;

        foreach ($reader->getRecords() as $record) {
            $rowNum++;
            $d = [];
            foreach ($record as $k => $val) {
                $d[trim((string) $k)] = trim((string) $val);
            }
            $ref = $d['booking_number'] ?? '';

            try {
                $booking = Booking::where('booking_number', $ref)->first();
                if (! $booking) {
                    $failed[] = ['row' => $rowNum, 'ref' => $ref, 'error' => 'Booking tidak ditemukan.'];
                    continue;
                }
                if ($dryRun) { $ok++; continue; }

                match ($type) {
                    'jadwal'     => $this->jadwal($booking, $d),
                    'pembayaran' => $this->pembayaran($booking, $d, $skipped, $failed, $rowNum, $ref),
                    'kontrak'    => $this->kontrak($booking, $d, $userId),
                };
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = ['row' => $rowNum, 'ref' => $ref, 'error' => $e->getMessage()];
            }
        }

        // pembayaran men-skip lewat referensi; sesuaikan ok.
        return ['ok' => $ok, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function jadwal(Booking $b, array $d): void
    {
        $amount = (float) ($d['amount'] ?? 0);
        $paid   = (float) ($d['paid_amount'] ?? 0);
        PaymentSchedule::updateOrCreate(
            ['booking_id' => $b->id, 'installment_no' => (int) $d['installment_no']],
            [
                'label'       => $d['label'] ?: ('Termin '.$d['installment_no']),
                'due_date'    => $d['due_date'],
                'amount'      => $amount,
                'paid_amount' => $paid,
                'status'      => $d['status'] ?: ($paid >= $amount && $amount > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'outstanding')),
            ],
        );
    }

    private function pembayaran(Booking $b, array $d, int &$skipped, array &$failed, int $rowNum, string $ref): void
    {
        $sch = $b->paymentSchedules()->where('installment_no', (int) $d['installment_no'])->first();
        if (! $sch) {
            $failed[] = ['row' => $rowNum, 'ref' => $ref, 'error' => 'Jadwal termin '.$d['installment_no'].' tidak ada.'];
            $skipped++;
            return;
        }
        $newPaid = round((float) $sch->paid_amount + (float) ($d['amount'] ?? 0), 2);
        $sch->update([
            'paid_amount' => $newPaid,
            'status'      => $newPaid >= (float) $sch->amount && (float) $sch->amount > 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'outstanding'),
            'paid_at'     => $d['paid_at'] ?: $sch->paid_at,
        ]);
    }

    private function kontrak(Booking $b, array $d, int $userId): void
    {
        Contract::updateOrCreate(
            ['booking_id' => $b->id],
            [
                'contract_number' => $d['contract_number'],
                'signed_at'       => $d['signed_at'] ?: null,
                'status'          => $d['status'] ?: 'signed',
                'created_by'      => $userId,
            ],
        );
    }
}
