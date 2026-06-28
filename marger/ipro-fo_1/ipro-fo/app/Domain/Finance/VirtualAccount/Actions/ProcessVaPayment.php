<?php

declare(strict_types=1);

namespace App\Domain\Finance\VirtualAccount\Actions;

use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Finance\VirtualAccount\Models\AnonymousPayment;
use App\Domain\Finance\VirtualAccount\Models\VaTransaction;
use App\Domain\Finance\VirtualAccount\Models\VirtualAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Handler webhook bank VA:
 *  1. Cari VA by (bank_code, va_number) → resolve customer.
 *  2. Cek booking aktif terkait customer.
 *  3. Buat TTS otomatis (transfer method) + auto-allocate FIFO.
 *  4. Jika tidak ada booking aktif → masuk anonymous_payments.
 */
final class ProcessVaPayment
{
    public function __construct(private readonly CreateTts $createTts) {}

    public function execute(string $bankCode, string $vaNumber, float $amount, string $externalRef, Carbon $receivedAt): void
    {
        DB::transaction(function () use ($bankCode, $vaNumber, $amount, $externalRef, $receivedAt): void {
            // Idempotency by external_reference
            $existing = VaTransaction::query()->where('external_reference', $externalRef)->first();
            if ($existing) {
                return;
            }

            $va = VirtualAccount::query()
                ->whereHas('bank', fn ($q) => $q->where('code', $bankCode))
                ->where('va_number', $vaNumber)
                ->first();

            if (! $va) {
                AnonymousPayment::create([
                    'bank_account_id' => 0, // unknown
                    'amount'          => $amount,
                    'received_at'     => $receivedAt,
                    'reference_no'    => $externalRef,
                    'description'     => "Bank {$bankCode} VA {$vaNumber} (tidak teregister)",
                    'status'          => 'unidentified',
                ]);
                return;
            }

            // Cari booking aktif customer ini
            $activeBooking = DB::table('bookings')
                ->where('customer_id', $va->customer_id)
                ->whereIn('status', ['active', 'ppjb'])
                ->orderByDesc('id')
                ->first();

            $tts = $this->createTts->execute(new TtsDto(
                customerId:    $va->customer_id,
                bookingId:     $activeBooking?->id,
                amount:        $amount,
                paymentMethod: PaymentMethod::Va,
                receivedDate:  $receivedAt->toDateString(),
                referenceNo:   $externalRef,
                notes:         "Pembayaran via VA Bank {$bankCode} {$vaNumber}",
            ), autoAllocate: true);

            VaTransaction::create([
                'virtual_account_id' => $va->id,
                'amount'             => $amount,
                'received_at'        => $receivedAt,
                'external_reference' => $externalRef,
                'status'             => $activeBooking ? 'allocated' : 'unallocated',
                'tts_receipt_id'     => $tts->id,
            ]);
        });
    }
}
