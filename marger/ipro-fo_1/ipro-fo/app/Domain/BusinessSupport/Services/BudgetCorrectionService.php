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
                'months' => [$bulan => $nilai],
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
     * Ajukan koreksi dari layar Koreksi Budget: tambahan per bulan (bulan → nilai, boleh minus)
     * untuk satu master budget, lalu masuk Approval Inbox (rantai yang sama dengan koreksi dari
     * invoice: Accounting → GM). Diterapkan ke master budget saat disetujui.
     *
     * @param  array<int,float>  $tambahan
     */
    public function ajukanManual(BsBudget $budget, array $tambahan, string $tanggal, ?string $note, int $userId, ?array $sumber = null, ?int $costCenterId = null): BsBudgetCorrection
    {
        $tambahan = array_filter(array_map(fn ($v) => round((float) $v, 2), $tambahan), fn ($v) => $v != 0.0);
        ksort($tambahan);
        $total = round(array_sum($tambahan), 2);

        $c = BsBudgetCorrection::create([
            'tenant_id' => $budget->tenant_id,
            'doc_no' => BsBudgetCorrection::nextDocNo((int) $budget->year, $budget->project, $tanggal),
            'year' => $budget->year, 'project' => $budget->project, 'budget_id' => $budget->id,
            'cost_center_id' => $costCenterId ?? $budget->cost_center_id, 'account_id' => $budget->account_id,
            'corr_date' => $tanggal, 'period_from' => (int) array_key_first($tambahan), 'period_to' => (int) array_key_last($tambahan),
            'old_amount' => (float) $budget->amount, 'new_amount' => round((float) $budget->amount + $total, 2),
            'months' => $tambahan,
            'status' => 'baru', 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'created_by' => $userId,
            'source_type' => $sumber['type'] ?? null, 'source_id' => $sumber['id'] ?? null,
        ]);
        $approval = $this->submit->execute(BsBudgetCorrection::DOCUMENT_TYPE, $c, ['doc_no' => $c->doc_no, 'nilai' => $total]);
        $c->forceFill(['approval_id' => $approval->id])->save();

        return $c;
    }

    /**
     * Putuskan satu koreksi. Disetujui → tambahan per bulannya (kolom months; koreksi lama: selisih
     * nilai baru − lama dibagi rata ke periodenya) ditambahkan ke bulan-bulan itu dan anggaran
     * tahunan ikut naik; ditolak → hanya ditandai.
     *
     * Tambahannya milik KOREKSI ini, bukan dihitung ulang dari nilai budget saat ini — koreksi lain
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
                $budget->tambahBulan($c->tambahanBulan());
                $budget->save();
            }
        }
        $c->update(['status' => $setuju ? 'approved' : 'rejected', 'approved_at' => now(), 'approved_by' => $userId]);
    }
}
