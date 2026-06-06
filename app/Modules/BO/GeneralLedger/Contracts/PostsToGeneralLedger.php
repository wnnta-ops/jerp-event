<?php

namespace App\Modules\BO\GeneralLedger\Contracts;

use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use DateTimeInterface;

/**
 * Implemented by any document (CB voucher, AP/AR invoice, FOBO event payload…)
 * that can be posted to the General Ledger. The JurnalPostingService consumes
 * these to build a balanced `bo_jurnal_header` + `bo_jurnal_detil`.
 */
interface PostsToGeneralLedger
{
    /**
     * The debit/credit lines of the resulting journal.
     *
     * @return array<int,JurnalLineDTO>
     */
    public function glLines(): array;

    /** Document type recorded on the journal header, e.g. 'BKM', 'INV-AP', 'FOBO'. */
    public function glDocumentType(): string;

    /** The source document number (for traceability back from the journal). */
    public function glDocumentNo(): string;

    public function glCompanyId(): int;

    public function glDate(): DateTimeInterface;

    public function glDescription(): ?string;
}
