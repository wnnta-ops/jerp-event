<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Reschedule Jatuh Tempo Piutang (BO /AR/Reschedule1).
 * Menjadwal ulang sisa piutang (angsuran belum dibayar) tanpa mengubah nilai/jurnal —
 * hanya timing. Jadwal yang sudah ada pembayaran dikunci; sisanya diganti jadwal baru.
 */
final class RescheduleArInvoice
{
    /**
     * @param array{schedules: array<int, array{due_date:string, amount:float|int|string}>, note?:?string} $data
     */
    public function execute(BsArInvoice $invoice, array $data, ?int $userId = null): BsArInvoice
    {
        return DB::transaction(function () use ($invoice, $data): BsArInvoice {
            if ($invoice->status !== 'open') {
                throw new BusinessRuleException('Hanya tagihan berstatus terbuka yang dapat dijadwal ulang.', errorCode: 'AR_NOT_OPEN');
            }
            $outstanding = round((float) $invoice->outstanding, 2);
            if ($outstanding <= 0.009) {
                throw new BusinessRuleException('Tidak ada sisa piutang untuk dijadwal ulang.', errorCode: 'AR_NO_OUTSTANDING');
            }

            $rows = array_values($data['schedules'] ?? []);
            if (count($rows) < 1) {
                throw new BusinessRuleException('Minimal satu jadwal angsuran baru.', errorCode: 'AR_NO_SCHEDULE');
            }
            $sum = round(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), 2);
            if (abs($sum - $outstanding) > 0.01) {
                throw new BusinessRuleException(
                    'Total jadwal baru (Rp '.number_format($sum, 0, ',', '.').') harus sama dengan sisa piutang (Rp '.number_format($outstanding, 0, ',', '.').').',
                    errorCode: 'AR_SCHEDULE_MISMATCH'
                );
            }

            // Kunci jadwal yang sudah ada pembayaran; hapus jadwal yang belum dibayar.
            $locked = $invoice->schedules()->where('paid_amount', '>', 0)->orderBy('installment_no')->get();
            $invoice->schedules()->where(fn ($q) => $q->whereNull('paid_amount')->orWhere('paid_amount', '<=', 0))->delete();

            $no      = (int) ($locked->max('installment_no') ?? 0);
            $lastDue = null;
            foreach ($rows as $r) {
                $no++;
                $lastDue = $r['due_date'];
                $invoice->schedules()->create([
                    'installment_no' => $no,
                    'due_date'       => $r['due_date'],
                    'amount'         => round((float) $r['amount'], 2),
                    'paid_amount'    => 0,
                    'status'         => 'unpaid',
                ]);
            }

            // Selaraskan due_date header dengan angsuran terakhir agar aging mengikuti reschedule.
            if ($lastDue) {
                $invoice->update(['due_date' => $lastDue]);
            }

            return $invoice->refresh();
        });
    }
}
