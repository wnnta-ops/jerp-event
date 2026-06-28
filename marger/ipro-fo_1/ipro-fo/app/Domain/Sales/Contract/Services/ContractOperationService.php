<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Services;

use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Domain\Sales\Contract\Models\Contract;
use App\Domain\Sales\Contract\Models\ContractOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operasi Kontrak — logika bisnis Adjustment, PPN, Tagihan Custom,
 * Pengalihan Hak, Pindah Unit, Pembatalan. Semua perubahan dicatat ke
 * contract_operations + diterapkan ke booking/kontrak/jadwal.
 */
class ContractOperationService
{
    /** Cari kontrak berdasarkan nomor (eager-load booking + relasi). */
    public function findByNumber(string $number): ?Contract
    {
        return Contract::query()
            ->where('contract_number', trim($number))
            ->orWhere('ppjb_system_number', trim($number))
            ->orWhere('ppjb_manual_number', trim($number))
            ->with(['booking.customer:id,code,full_name', 'booking.unit:id,unit_code,current_price,status', 'booking.paymentSchedules'])
            ->first();
    }

    /** Ringkasan kontrak untuk ditampilkan di form operasi. */
    public function summary(Contract $contract): array
    {
        $b = $contract->booking;

        return [
            'contract_id'     => $contract->id,
            'contract_number' => $contract->contract_number,
            'status'          => $contract->status,
            'customer'        => $b?->customer ? ['id' => $b->customer->id, 'code' => $b->customer->code, 'name' => $b->customer->full_name] : null,
            'unit'            => $b?->unit ? ['id' => $b->unit->id, 'code' => $b->unit->unit_code, 'price' => (float) $b->unit->current_price] : null,
            'base_price'      => (float) ($b?->base_price ?? 0),
            'discount_amount' => (float) ($b?->discount_amount ?? 0),
            'ppn_pct'         => (float) ($b?->ppn_pct ?? 0),
            'ppn_amount'      => (float) ($b?->ppn_amount ?? 0),
            'total_price'     => (float) ($b?->total_price ?? 0),
            'total_tagihan'   => (float) ($b?->total_tagihan ?? 0),
            'total_terbayar'  => (float) ($b?->total_terbayar ?? 0),
            'sisa_tagihan'    => (float) ($b?->sisa_tagihan ?? 0),
        ];
    }

    // ── Adjustment Kontrak ──
    public function adjust(Contract $contract, array $data, int $userId): ContractOperation
    {
        return DB::transaction(function () use ($contract, $data, $userId) {
            $b   = $contract->booking;
            $old = $this->financialSnapshot($b);

            $b->base_price      = (float) $data['base_price'];
            $b->discount_amount = (float) ($data['discount_amount'] ?? 0);
            $this->recompute($b);
            $b->save();
            $this->syncScheduleToTotal($b);

            return $this->log($contract, 'adjustment', $data['reason'] ?? null, $old, $this->financialSnapshot($b),
                round((float) $b->total_price - (float) ($old['total_price'] ?? 0), 2), $userId);
        });
    }

    // ── Adjustment PPN (bulk) ──
    /** Preview perhitungan transisi tarif PPN untuk kontrak aktif suatu project. */
    public function ppnPreview(int $projectId, float $oldRate, float $newRate): array
    {
        $contracts = Contract::query()
            ->where('status', 'signed')
            ->whereHas('booking', fn ($q) => $q->where('project_id', $projectId))
            ->with(['booking.unit:id,unit_code', 'booking.customer:id,full_name', 'booking.paymentSchedules:id,booking_id,amount,paid_amount'])
            ->get();

        $oldF = 1 + $oldRate / 100;
        $rows = [];
        foreach ($contracts as $c) {
            $b = $c->booking;
            if (! $b) {
                continue;
            }
            $A = (float) $b->base_price - (float) $b->discount_amount; // DPP
            $B = (float) $b->ppn_amount;                              // PPN lama
            $C = $A + $B;                                             // Nilai kontrak
            $F = (float) $b->total_terbayar;                          // terbayar
            $D = $oldF > 0 ? $F / $oldF : 0;                          // DPP terbayar
            $E = $F - $D;                                             // PPN terbayar
            $G = max(0, $C - $F);                                     // sisa tagihan
            $H = $oldF > 0 ? $G / $oldF : 0;                          // DPP sisa
            $I = $H * $newRate / 100;                                 // PPN baru
            $J = $H + $I;                                             // sisa tagihan baru
            $K = $J + $F;                                             // nilai kontrak baru
            $L = $D + $H;                                             // DPP kontrak baru
            $M = $E + $I;                                             // PPN kontrak baru
            $N = $M - $B;                                             // kenaikan PPN

            $rows[] = [
                'contract_id' => $c->id, 'contract_number' => $c->contract_number,
                'unit' => $b->unit?->unit_code, 'customer' => $b->customer?->full_name,
                'dpp' => round($A, 2), 'ppn_old' => round($B, 2), 'nilai_kontrak' => round($C, 2),
                'paid' => round($F, 2), 'sisa' => round($G, 2),
                'dpp_sisa' => round($H, 2), 'ppn_new' => round($I, 2), 'sisa_new' => round($J, 2),
                'nilai_kontrak_new' => round($K, 2), 'dpp_kontrak_new' => round($L, 2),
                'ppn_kontrak_new' => round($M, 2), 'kenaikan_ppn' => round($N, 2),
            ];
        }

        return $rows;
    }

    /** Terapkan tarif PPN baru ke kontrak terpilih. */
    public function applyPpn(array $contractIds, float $oldRate, float $newRate, int $userId): int
    {
        $count = 0;
        $oldF = 1 + $oldRate / 100;

        DB::transaction(function () use ($contractIds, $oldRate, $newRate, $userId, &$count, $oldF) {
            $contracts = Contract::whereIn('id', $contractIds)->with('booking.paymentSchedules')->get();
            foreach ($contracts as $c) {
                $b = $c->booking;
                if (! $b) {
                    continue;
                }
                $old = $this->financialSnapshot($b);
                $A = (float) $b->base_price - (float) $b->discount_amount;
                $B = (float) $b->ppn_amount;
                $F = (float) $b->total_terbayar;
                $G = max(0, ($A + $B) - $F);
                $H = $oldF > 0 ? $G / $oldF : 0;
                $I = $H * $newRate / 100;
                $J = $H + $I;
                $M = ($F - ($oldF > 0 ? $F / $oldF : 0)) + $I; // PPN kontrak baru

                // Skala sisa tagihan tiap jadwal yang belum lunas dengan faktor J/G.
                if ($G > 0) {
                    $factor = $J / $G;
                    foreach ($b->paymentSchedules as $s) {
                        $outstanding = (float) $s->amount - (float) $s->paid_amount;
                        if ($outstanding > 0.009) {
                            $s->amount = round((float) $s->paid_amount + $outstanding * $factor, 2);
                            $s->save();
                        }
                    }
                }

                $b->ppn_pct    = $newRate;
                $b->ppn_amount = round($M, 2);
                $b->total_price = round($A + $M, 2);
                $b->save();

                $this->log($c, 'ppn', "Transisi PPN {$oldRate}% → {$newRate}%", $old, $this->financialSnapshot($b),
                    round($M - $B, 2), $userId);
                $count++;
            }
        });

        return $count;
    }

    // ── Tagihan Custom ──
    public function tagihanCustom(Contract $contract, array $data, int $userId): ContractOperation
    {
        return DB::transaction(function () use ($contract, $data, $userId) {
            $b   = $contract->booking;
            $max = (int) $b->paymentSchedules()->max('installment_no');
            PaymentSchedule::create([
                'booking_id'     => $b->id,
                'payment_type'   => $data['payment_type'] ?? 'ADM',
                'installment_no' => $max + 1,
                'label'          => $data['label'],
                'due_date'       => $data['due_date'],
                'amount'         => (float) $data['amount'],
                'paid_amount'    => 0,
                'status'         => 'outstanding',
            ]);

            return $this->log($contract, 'tagihan_custom', $data['label'], null,
                ['label' => $data['label'], 'due_date' => $data['due_date'], 'amount' => (float) $data['amount']],
                (float) $data['amount'], $userId);
        });
    }

    // ── Pengalihan Hak / Ganti Nama ──
    public function gantiNama(Contract $contract, int $newCustomerId, ?string $reason, int $userId): ContractOperation
    {
        return DB::transaction(function () use ($contract, $newCustomerId, $reason, $userId) {
            $b   = $contract->booking;
            $old = ['customer_id' => $b->customer_id, 'customer' => $b->customer?->full_name];
            $b->customer_id = $newCustomerId;
            $b->save();
            $b->load('customer:id,full_name');

            return $this->log($contract, 'ganti_nama', $reason,
                $old, ['customer_id' => $newCustomerId, 'customer' => $b->customer?->full_name], 0, $userId);
        });
    }

    // ── Pindah Unit ──
    public function pindahUnit(Contract $contract, int $newUnitId, ?string $reason, int $userId): ContractOperation
    {
        return DB::transaction(function () use ($contract, $newUnitId, $reason, $userId) {
            $b        = $contract->booking;
            $newUnit  = Unit::findOrFail($newUnitId);
            $oldUnitId = $b->unit_id;
            $old      = $this->financialSnapshot($b) + ['unit_id' => $oldUnitId, 'unit' => $b->unit?->unit_code];

            // Update status unit via query builder agar tidak menyentuh model unit
            // yang ter-load parsial (strict-mode: kolom tenant_id mungkin tidak di-select).
            if ($oldUnitId) {
                Unit::whereKey($oldUnitId)->update(['status' => 'available']);
            }
            $b->unit_id    = $newUnit->id;
            $b->base_price = (float) $newUnit->current_price;
            $this->recompute($b);
            $b->save();
            $this->syncScheduleToTotal($b);
            Unit::whereKey($newUnit->id)->update(['status' => 'sold']);

            return $this->log($contract, 'pindah_unit', $reason,
                $old, $this->financialSnapshot($b) + ['unit_id' => $newUnit->id, 'unit' => $newUnit->unit_code],
                round((float) $b->total_price - (float) ($old['total_price'] ?? 0), 2), $userId);
        });
    }

    // ── Pembatalan Kontrak ──
    public function batal(Contract $contract, string $reason, float $penalty, float $refund, int $userId): ContractOperation
    {
        return DB::transaction(function () use ($contract, $reason, $penalty, $refund, $userId) {
            $b = $contract->booking;
            $contract->update(['status' => 'cancelled', 'cancelled_at' => Carbon::now()->toDateString()]);
            $b?->update(['status' => 'cancelled']);
            if ($b?->unit_id) {
                Unit::whereKey($b->unit_id)->update(['status' => 'available']);
            }

            return $this->log($contract, 'batal', $reason, ['status' => 'signed'],
                ['status' => 'cancelled', 'penalty' => $penalty, 'refund' => $refund], $penalty, $userId);
        });
    }

    // ── Helpers ──
    private function recompute(Booking $b): void
    {
        $dpp = (float) $b->base_price - (float) $b->discount_amount;
        $b->ppn_amount  = round($dpp * (float) $b->ppn_pct / 100, 2);
        $b->total_price = round($dpp + (float) $b->ppn_amount, 2);
    }

    /** Sesuaikan jadwal terakhir yang belum lunas agar Σ jadwal == total_price. */
    private function syncScheduleToTotal(Booking $b): void
    {
        $target = (float) $b->total_price;
        $sum    = (float) $b->paymentSchedules()->sum('amount');
        $delta  = round($target - $sum, 2);
        if (abs($delta) < 0.01) {
            return;
        }
        $last = $b->paymentSchedules()->whereColumn('paid_amount', '<', 'amount')->orderByDesc('installment_no')->first();
        if ($last) {
            $last->amount = round((float) $last->amount + $delta, 2);
            $last->save();
        } elseif ($delta > 0) {
            $max = (int) $b->paymentSchedules()->max('installment_no');
            PaymentSchedule::create([
                'booking_id' => $b->id, 'payment_type' => 'ADM', 'installment_no' => $max + 1,
                'label' => 'Penyesuaian', 'due_date' => Carbon::now()->toDateString(),
                'amount' => $delta, 'paid_amount' => 0, 'status' => 'outstanding',
            ]);
        }
    }

    private function financialSnapshot(?Booking $b): array
    {
        return [
            'base_price'      => (float) ($b?->base_price ?? 0),
            'discount_amount' => (float) ($b?->discount_amount ?? 0),
            'ppn_pct'         => (float) ($b?->ppn_pct ?? 0),
            'ppn_amount'      => (float) ($b?->ppn_amount ?? 0),
            'total_price'     => (float) ($b?->total_price ?? 0),
        ];
    }

    private function log(Contract $c, string $type, ?string $reason, ?array $old, ?array $new, float $amount, int $userId): ContractOperation
    {
        return ContractOperation::create([
            'contract_id' => $c->id, 'booking_id' => $c->booking_id, 'operation_no' => $this->nextNo(),
            'type' => $type, 'reason' => $reason, 'old_values' => $old, 'new_values' => $new,
            'amount' => $amount, 'status' => 'applied', 'processed_by' => $userId, 'processed_at' => Carbon::now(),
        ]);
    }

    private function nextNo(): string
    {
        $d   = Carbon::now();
        $seq = ContractOperation::withTrashed()->whereYear('created_at', $d->year)->count() + 1;
        do {
            $no = sprintf('OPS/%s/%05d', $d->format('y'), $seq);
            $seq++;
        } while (ContractOperation::withTrashed()->where('operation_no', $no)->exists());

        return $no;
    }
}
