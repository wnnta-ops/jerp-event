<?php

namespace App\Modules\FO\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted by FO modules whenever a financial event occurs that must be
 * reflected in the BO General Ledger (replaces the legacy SP_FOtoStandAlone_*
 * stored procedures). The BO FOBO bridge listens and posts a balanced journal
 * idempotently.
 *
 * `jenis` ∈ KAS_MASUK | SALES | KOMISI | AKAD | BUYBACK | REFUND | MEMO
 * (see config/fobo.php for the account mapping).
 */
class FoFinancialEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly string $jenis,
        public readonly string $sourceModul,
        public readonly string $sourceNo,
        public readonly float $amount,
        public readonly ?int $companyId = null,
        public readonly ?string $tgl = null,
        public readonly ?string $akunDebet = null,
        public readonly ?string $akunKredit = null,
        public readonly ?string $keterangan = null,
        public readonly array $meta = [],
    ) {}
}
