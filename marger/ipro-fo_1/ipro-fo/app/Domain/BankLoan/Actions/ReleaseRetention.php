<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BankLoan\Models\KprRetentionRelease;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Finance\Tts\Models\Tts;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class ReleaseRetention
{
    public function __construct(private readonly CreateTts $createTts) {}

    /**
     * Release retensi — boleh PARSIAL. Tiap panggilan melepas sebagian (atau sisa) dari nominal
     * yang ditahan; akumulasi disimpan di `released_amount`. Status: held → partial → released.
     *
     * @param  float|null   $amount      Nominal yang dilepas kali ini. null = lepas seluruh sisa.
     * @param  string|null  $releasedAt  #145 — tanggal Release DAJAM (wajib dari UI). Dipakai utk
     *                                    released_at retensi DAN receivedDate auto-TTS. Fallback ke hari ini.
     */
    public function execute(KprRetention $retention, ?float $amount = null, ?string $reason = null, ?string $releasedAt = null): void
    {
        $total     = round((float) $retention->amount, 2);
        $already   = round((float) $retention->released_amount, 2);
        $remaining = round($total - $already, 2);

        if ($retention->status === 'released' || $remaining <= 0.009) {
            throw new BusinessRuleException('Retensi sudah dilepas penuh.', errorCode: 'RETENTION_FULLY_RELEASED');
        }

        // null = lepas seluruh sisa (kompat lama). Selain itu, jumlah yg dilepas dibatasi sisa.
        $releaseAmt = $amount === null ? $remaining : round((float) $amount, 2);
        if ($releaseAmt <= 0) {
            throw new BusinessRuleException('Nominal release harus lebih dari nol.', errorCode: 'RELEASE_AMOUNT_INVALID');
        }
        if ($releaseAmt > $remaining + 0.01) {
            $rp = fn ($v) => 'Rp '.number_format((float) $v, 2, ',', '.');
            throw new BusinessRuleException(
                "Nominal release ({$rp($releaseAmt)}) melebihi sisa retensi yang bisa dilepas ({$rp($remaining)}).",
                errorCode: 'RELEASE_EXCEEDS_REMAINING',
            );
        }

        $releaseDate = $releasedAt ?: now()->toDateString();

        DB::transaction(function () use ($retention, $reason, $releaseDate, $releaseAmt, $already, $total): void {
            $newReleased = round($already + $releaseAmt, 2);
            $fully       = $newReleased + 0.01 >= $total;

            $retention->update([
                'status'          => $fully ? 'released' : 'partial',
                'released_amount' => $newReleased,
                'released_at'     => $releaseDate, // tanggal release terakhir
                'claim_reason'    => $reason ?: $retention->claim_reason,
            ]);

            // Otomatis buat TTS untuk customer — hanya untuk porsi yang dilepas kali ini.
            /** @var KprApplication|null $app */
            $realization = $retention->realization()->first();
            $app = $realization ? KprApplication::find($realization->kpr_application_id) : null;
            $releaseTts = null;
            if ($app && $app->booking_id) {
                // Ref unik per-tranche agar release bertahap tak bentrok.
                $seq = Tts::where('reference_no', 'like', "RET-RELEASE-{$retention->id}-%")->count() + 1;
                $releaseTts = $this->createTts->execute(new TtsDto(
                    customerId:    $app->customer_id,
                    bookingId:     $app->booking_id,
                    amount:        $releaseAmt,
                    paymentMethod: PaymentMethod::Transfer,
                    receivedDate:  $releaseDate, // #145 pakai tanggal Release yang diisi user
                    referenceNo:   "RET-RELEASE-{$retention->id}-{$seq}",
                    notes:         $fully ? 'Pencairan retensi KPR' : 'Pencairan retensi KPR (sebagian)',
                ), autoAllocate: true);
            }

            // Catat riwayat pelepasan (satu baris per event Release).
            KprRetentionRelease::create([
                'kpr_retention_id' => $retention->id,
                'amount'           => $releaseAmt,
                'released_at'      => $releaseDate,
                'reason'           => $reason,
                'tts_id'           => $releaseTts?->id,
            ]);
        });
    }
}
