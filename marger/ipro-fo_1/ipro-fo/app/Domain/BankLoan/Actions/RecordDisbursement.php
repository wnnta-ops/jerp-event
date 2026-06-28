<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\KprRetention;
use App\Exceptions\BusinessRuleException;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use Illuminate\Support\Facades\DB;

final class RecordDisbursement
{
    public function __construct(private readonly CreateTts $createTts) {}

    public function execute(KprApplication $app, array $data): KprRealization
    {
        // #66: pencairan bertahap — boleh dari status akad atau disbursed.
        if (! in_array($app->status->value, ['akad', 'disbursed'], true)) {
            $app->status->assertCanTransitionTo(KprStatus::Disbursed); // lempar bila status tak valid
        }

        // KUNCI: total pencairan tidak boleh melebihi plafon disetujui.
        $plafon          = (float) ($app->plafon_approved ?? 0);
        $alreadyDisbursed = (float) $app->realizations()->sum('disbursed_amount');
        $newAmount       = (float) ($data['disbursed_amount'] ?? 0);
        if ($plafon > 0 && ($alreadyDisbursed + $newAmount) > $plafon + 0.01) {
            $sisa = max(0, $plafon - $alreadyDisbursed);
            $rp = fn ($v) => 'Rp ' . number_format((float) $v, 2, ',', '.');
            throw new BusinessRuleException(
                "Total pencairan melebihi plafon disetujui ({$rp($plafon)}). "
                . "Sudah cair {$rp($alreadyDisbursed)}, sisa yang bisa dicairkan {$rp($sisa)}."
            );
        }

        return DB::transaction(function () use ($app, $data): KprRealization {
            // #146 DAJAM Pajak — nominal pembayaran SSP/BPHTB (boleh 0/null).
            $sspAmount = (float) ($data['ssp_bphtb_amount'] ?? 0);

            $realization = KprRealization::create([
                'kpr_application_id'    => $app->id,
                'disbursed_at'          => $data['disbursed_at'],
                'disbursed_amount'      => $data['disbursed_amount'],
                'retention_amount'      => $data['retention_amount'] ?? 0,
                'retention_note'        => $data['retention_note'] ?? null,
                'retention_category_id' => $data['retention_category_id'] ?? null,
                'reference_no'          => $data['reference_no']     ?? null,
                'bank_account_id'       => $data['bank_account_id']  ?? null,
                'ssp_bphtb_date'        => $data['ssp_bphtb_date']   ?? null, // #146
                'ssp_bphtb_amount'      => $sspAmount,                        // #146
            ]);

            if (($data['retention_amount'] ?? 0) > 0) {
                KprRetention::create([
                    'kpr_realization_id' => $realization->id,
                    'amount'             => $data['retention_amount'],
                    'status'             => 'held',
                ]);
            }

            if ($app->status->value === 'akad') {
                $app->update(['status' => KprStatus::Disbursed]);
            }

            // Auto-buat TTS untuk alokasi ke booking; simpan tautannya (#65 reversal aman).
            $netDisbursed = (float) $data['disbursed_amount'] - (float) ($data['retention_amount'] ?? 0);
            if ($netDisbursed > 0 && $app->booking_id) {
                $tts = $this->createTts->execute(new TtsDto(
                    customerId:     $app->customer_id,
                    bookingId:      $app->booking_id,
                    amount:         $netDisbursed,
                    paymentMethod:  PaymentMethod::Transfer,
                    receivedDate:   $data['disbursed_at'],
                    bankAccountId:  $data['bank_account_id'] ?? null,
                    referenceNo:    "KPR-DISB-{$app->application_number}",
                    notes:          "Pencairan KPR dari {$app->bank?->name}",
                ), autoAllocate: true);
                $realization->update(['tts_id' => $tts->id]);
            }

            // #146 DAJAM Pajak: pembayaran SSP/BPHTB mengurangi outstanding AR customer.
            // Pola paling aman & konsisten: buat TTS (auto-allocate FIFO ke jadwal angsuran terlama),
            // ditautkan via ssp_tts_id → reversal aman saat realisasi dihapus (lihat KprRealization::booted).
            if ($sspAmount > 0 && $app->booking_id) {
                $sspTts = $this->createTts->execute(new TtsDto(
                    customerId:     $app->customer_id,
                    bookingId:      $app->booking_id,
                    amount:         $sspAmount,
                    paymentMethod:  PaymentMethod::Transfer,
                    receivedDate:   $data['ssp_bphtb_date'] ?? $data['disbursed_at'],
                    bankAccountId:  $data['bank_account_id'] ?? null,
                    referenceNo:    "KPR-SSP-BPHTB-{$app->application_number}",
                    notes:          "Pembayaran SSP/BPHTB (DAJAM Pajak) KPR dari {$app->bank?->name}",
                ), autoAllocate: true);
                $realization->update(['ssp_tts_id' => $sspTts->id]);
            }

            // #64c/#66: aktifkan otomatis hanya bila pencairan sudah penuh.
            $totalDisbursed = (float) $app->realizations()->sum('disbursed_amount');
            if ((float) $app->plafon_approved > 0 && $totalDisbursed + 0.01 >= (float) $app->plafon_approved) {
                $app->update(['status' => KprStatus::Active]);
            }

            return $realization;
        });
    }
}
