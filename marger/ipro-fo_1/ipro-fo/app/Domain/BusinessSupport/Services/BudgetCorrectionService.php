<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use Illuminate\Support\Carbon;

/**
 * Koreksi Budget: diajukan dari invoice hutang (tambahan budget BULAN invoice) dan diterapkan ke
 * master budget saat disetujui — dari Approval Inbox maupun layar Approval Koreksi.
 */
final class BudgetCorrectionService
{
    public function __construct(private readonly SubmitForApproval $submit) {}

    /**
     * Ajukan koreksi untuk baris Struktur Jurnal invoice yang melewati sisa budget bulan itu.
     * Dipanggil DI DALAM transaksi yang sama dengan registrasi invoice.
     *
     * @param  list<array{budget_id:int, account_id:int, cost_center_id:?int, keterangan:?string, nilai:float|int|string}>  $koreksi
     * @return list<BsBudgetCorrection>
     */
    public function ajukanDariInvoice(BsApInvoice $invoice, array $koreksi, int $userId): array
    {
        $tgl = Carbon::parse($invoice->invoice_date);
        $bulan = (int) $tgl->month;
        $hasil = [];
        foreach ($koreksi as $k) {
            $budget = BsBudget::query()->lockForUpdate()->findOrFail((int) $k['budget_id']);
            $nilai = round((float) $k['nilai'], 2);
            $note = trim(($k['keterangan'] ?? '').' · '.$invoice->invoice_number, ' ·');

            $c = BsBudgetCorrection::create([
                'tenant_id' => $budget->tenant_id,
                'doc_no' => BsBudgetCorrection::nextDocNo((int) $budget->year, $budget->project, $tgl->toDateString()),
                'year' => $budget->year, 'project' => $budget->project, 'budget_id' => $budget->id,
                'cost_center_id' => $k['cost_center_id'] ?? $budget->cost_center_id, 'account_id' => $budget->account_id,
                'corr_date' => $tgl->toDateString(), 'period_from' => $bulan, 'period_to' => $bulan,
                // Nilai baru = anggaran tahunan + tambahan; saat disetujui tambahannya masuk ke bulan invoice.
                'old_amount' => (float) $budget->amount, 'new_amount' => round((float) $budget->amount + $nilai, 2),
                'status' => 'baru', 'note' => mb_substr($note, 0, 255), 'created_by' => $userId,
                'source_type' => BsBudgetCorrection::SOURCE_INVOICE, 'source_id' => $invoice->id,
            ]);
            $approval = $this->submit->execute(BsBudgetCorrection::DOCUMENT_TYPE, $c, [
                'doc_no' => $c->doc_no,
                'invoice_number' => $invoice->invoice_number,
                'nilai' => $nilai,
            ]);
            $c->forceFill(['approval_id' => $approval->id])->save();
            $hasil[] = $c;
        }

        return $hasil;
    }

    /**
     * Putuskan satu koreksi. Disetujui → selisih nilai baru − nilai lama ditambahkan ke bulan-bulan
     * periode koreksi (merata) dan anggaran tahunan ikut naik; ditolak → hanya ditandai.
     *
     * Selisihnya dihitung dari old_amount KOREKSI, bukan dari nilai budget saat ini — koreksi lain
     * yang lebih dulu disetujui tidak boleh ikut terhapus oleh koreksi ini.
     */
    public function putuskan(BsBudgetCorrection $c, bool $setuju, ?int $userId): void
    {
        if ($c->status !== 'baru') {
            return;
        }
        if ($setuju && $c->budget_id) {
            $budget = BsBudget::query()->lockForUpdate()->find($c->budget_id);
            if ($budget) {
                $delta = round((float) $c->new_amount - (float) $c->old_amount, 2);
                $from = max(1, (int) $c->period_from);
                $to = min(12, (int) $c->period_to);
                $per = round($delta / max(1, $to - $from + 1), 2);
                for ($m = $from; $m <= $to; $m++) {
                    $budget->{'m'.$m} = round((float) ($budget->{'m'.$m} ?? 0) + $per, 2);
                }
                $budget->amount = round((float) $budget->amount + $delta, 2);
                $budget->save();
            }
        }
        $c->update(['status' => $setuju ? 'approved' : 'rejected', 'approved_at' => now(), 'approved_by' => $userId]);
    }
}
