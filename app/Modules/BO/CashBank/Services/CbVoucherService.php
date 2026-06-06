<?php

namespace App\Modules\BO\CashBank\Services;

use App\Modules\BO\CashBank\Models\CbVoucher;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CbVoucherService
{
    public function __construct(
        private readonly JurnalPostingServiceInterface $posting,
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * Assign an atomic voucher number for the given jenis.
     */
    public function nextNumber(string $jenis, int $companyId): string
    {
        return $this->numerator->next($jenis, $companyId);
    }

    /**
     * Post a DRAFT voucher to the GL and flip it to POSTED.
     */
    public function post(CbVoucher $voucher): CbVoucher
    {
        if ($voucher->status !== 'DRAFT') {
            throw new RuntimeException("Voucher {$voucher->no_voucher} sudah {$voucher->status}; tidak bisa diposting ulang.");
        }

        return DB::transaction(function () use ($voucher) {
            $jurnal = $this->posting->post($voucher);

            $voucher->update([
                'status' => 'POSTED',
                'jurnal_id' => $jurnal->id,
            ]);

            return $voucher->refresh();
        });
    }

    /**
     * Void a posted voucher: reverse its journal and mark BATAL.
     */
    public function void(CbVoucher $voucher, ?string $reason = null): CbVoucher
    {
        if ($voucher->status === 'BATAL') {
            throw new RuntimeException("Voucher {$voucher->no_voucher} sudah dibatalkan.");
        }

        return DB::transaction(function () use ($voucher, $reason) {
            $jurnal = $voucher->jurnal;
            if ($voucher->status === 'POSTED' && $jurnal instanceof JurnalHeader) {
                $this->posting->reverse($jurnal, $reason ?? 'Void voucher '.$voucher->no_voucher);
            }

            $voucher->update(['status' => 'BATAL']);

            return $voucher->refresh();
        });
    }
}
