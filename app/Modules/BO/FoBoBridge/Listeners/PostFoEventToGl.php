<?php

namespace App\Modules\BO\FoBoBridge\Listeners;

use App\Models\Company;
use App\Modules\BO\FoBoBridge\Models\FoboPosting;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Support\AdHocPosting;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Posts an FO financial event to the GL exactly once. Runs on the `sync` queue
 * so it never blocks the FO request. Idempotency is guaranteed by the unique
 * key on bo_fobo_postings — a replayed event returns the existing posting.
 */
class PostFoEventToGl implements ShouldQueue
{
    public string $queue = 'sync';

    public int $tries = 3;

    public function __construct(private readonly JurnalPostingServiceInterface $posting) {}

    public function handle(FoFinancialEvent $event): void
    {
        // Idempotency gate: claim (or find) the posting row first.
        $posting = FoboPosting::firstOrNew([
            'source_modul' => $event->sourceModul,
            'source_no' => $event->sourceNo,
            'jenis' => $event->jenis,
        ]);

        if ($posting->exists && $posting->isPosted()) {
            return; // already posted — replay is a no-op
        }

        $posting->fill([
            'amount' => $event->amount,
            'meta' => $event->meta,
            'status' => 'PENDING',
        ])->save();

        try {
            $companyId = $event->companyId ?? $this->defaultCompanyId();
            [$debet, $kredit] = $this->resolveAccounts($event);

            $jurnal = $this->posting->post(AdHocPosting::make(
                lines: [
                    JurnalLineDTO::debet($debet, $event->amount, $event->keterangan),
                    JurnalLineDTO::kredit($kredit, $event->amount, $event->keterangan),
                ],
                documentType: 'FOBO:'.$event->jenis,
                documentNo: $event->sourceNo,
                companyId: $companyId,
                date: $event->tgl,
                description: $event->keterangan ?? ($event->sourceModul.' '.$event->sourceNo),
            ));

            $posting->update([
                'jurnal_id' => $jurnal->id,
                'status' => 'POSTED',
                'posted_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $posting->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);
            Log::error('FOBO posting failed', [
                'source' => $event->sourceModul.'/'.$event->sourceNo,
                'jenis' => $event->jenis,
                'error' => $e->getMessage(),
            ]);
            throw $e; // let the queue retry
        }
    }

    /**
     * @return array{0:string,1:string} [debet, kredit]
     */
    private function resolveAccounts(FoFinancialEvent $event): array
    {
        if ($event->akunDebet && $event->akunKredit) {
            return [$event->akunDebet, $event->akunKredit];
        }

        $map = config('fobo.mapping.'.$event->jenis);
        if (! is_array($map)) {
            throw new RuntimeException("Tidak ada mapping akun FOBO untuk jenis [{$event->jenis}].");
        }

        return [$event->akunDebet ?? $map['debet'], $event->akunKredit ?? $map['kredit']];
    }

    private function defaultCompanyId(): int
    {
        $id = Company::query()->value('id');
        if ($id === null) {
            throw new RuntimeException('Tidak ada company untuk posting FOBO.');
        }

        return (int) $id;
    }
}
