<?php

namespace App\Modules\BO\GeneralLedger\Contracts;

use App\Modules\BO\GeneralLedger\Models\JurnalHeader;

interface JurnalPostingServiceInterface
{
    /**
     * Build and persist a balanced journal from a posting source.
     * Throws UnbalancedJournalException if Σdebet != Σkredit.
     */
    public function post(PostsToGeneralLedger $source): JurnalHeader;

    /**
     * Create a manual journal entry from raw header data + line rows.
     *
     * @param  array<string,mixed>  $header
     * @param  array<int,array<string,mixed>>  $lines
     */
    public function postManual(array $header, array $lines): JurnalHeader;

    public function approve(JurnalHeader $jurnal): JurnalHeader;

    public function cancel(JurnalHeader $jurnal, ?string $reason = null): JurnalHeader;

    /** Create a reversing journal for an already-posted entry. */
    public function reverse(JurnalHeader $jurnal, ?string $reason = null): JurnalHeader;
}
