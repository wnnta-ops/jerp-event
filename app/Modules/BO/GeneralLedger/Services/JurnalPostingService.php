<?php

namespace App\Modules\BO\GeneralLedger\Services;

use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Contracts\PostsToGeneralLedger;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Exceptions\InvalidJournalStateException;
use App\Modules\BO\GeneralLedger\Exceptions\UnbalancedJournalException;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JurnalPostingService implements JurnalPostingServiceInterface
{
    public function __construct(
        private readonly NumeratorServiceInterface $numerator,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * Post a balanced journal directly from a source document.
     * Document-driven postings are created straight to POSTED status.
     */
    public function post(PostsToGeneralLedger $source): JurnalHeader
    {
        $lines = $source->glLines();
        $date = Carbon::instance(Carbon::parse($source->glDate()));
        $companyId = $source->glCompanyId();
        $periode = $date->format('Y-m');

        $this->periods->assertOpen($companyId, $periode);
        [$totalDebet, $totalKredit] = $this->assertBalanced($lines);

        return DB::transaction(function () use ($source, $lines, $date, $companyId, $periode, $totalDebet, $totalKredit) {
            $jurnal = JurnalHeader::create([
                'no_jurnal' => $this->numerator->next('JU', $companyId),
                'tgl_jurnal' => $date->toDateString(),
                'periode' => $periode,
                'company_id' => $companyId,
                'jenis_dokumen' => $source->glDocumentType(),
                'no_dokumen' => $source->glDocumentNo(),
                'keterangan' => $source->glDescription(),
                'status' => 'POSTED',
                'posted' => true,
                'total_debet' => $totalDebet,
                'total_kredit' => $totalKredit,
                'source_type' => $source instanceof Model ? $source->getMorphClass() : null,
                'source_id' => $source instanceof Model ? $source->getKey() : null,
                'posted_at' => now(),
            ]);

            $this->writeLines($jurnal, $lines);

            return $jurnal->load('detil');
        });
    }

    /**
     * @param  array<string,mixed>  $header
     * @param  array<int,array<string,mixed>>  $lines
     */
    public function postManual(array $header, array $lines): JurnalHeader
    {
        $dtos = array_map(
            fn (array $row) => JurnalLineDTO::fromArray($row),
            array_values($lines),
        );

        $date = Carbon::parse($header['tgl_jurnal'] ?? now());
        $companyId = (int) $header['company_id'];
        $periode = $date->format('Y-m');

        $this->periods->assertOpen($companyId, $periode);
        [$totalDebet, $totalKredit] = $this->assertBalanced($dtos);

        return DB::transaction(function () use ($header, $dtos, $date, $companyId, $periode, $totalDebet, $totalKredit) {
            $jurnal = JurnalHeader::create([
                'no_jurnal' => $header['no_jurnal'] ?? $this->numerator->next('JU', $companyId),
                'tgl_jurnal' => $date->toDateString(),
                'periode' => $periode,
                'company_id' => $companyId,
                'jenis_dokumen' => $header['jenis_dokumen'] ?? 'JU',
                'no_dokumen' => $header['no_dokumen'] ?? null,
                'keterangan' => $header['keterangan'] ?? null,
                'status' => 'DRAFT',
                'posted' => false,
                'total_debet' => $totalDebet,
                'total_kredit' => $totalKredit,
            ]);

            $this->writeLines($jurnal, $dtos);

            return $jurnal->load('detil');
        });
    }

    public function approve(JurnalHeader $jurnal): JurnalHeader
    {
        if ($jurnal->status !== 'DRAFT') {
            throw InvalidJournalStateException::transition($jurnal->status, 'approve');
        }

        $this->periods->assertOpen($jurnal->company_id, $jurnal->periode);

        $jurnal->update([
            'status' => 'APPROVED',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return $jurnal;
    }

    public function cancel(JurnalHeader $jurnal, ?string $reason = null): JurnalHeader
    {
        if ($jurnal->status === 'CANCELLED') {
            throw InvalidJournalStateException::transition($jurnal->status, 'cancel');
        }

        // A posted journal cannot just be cancelled — it must be reversed first.
        if ($jurnal->status === 'POSTED') {
            throw InvalidJournalStateException::transition($jurnal->status, 'cancel (gunakan reverse)');
        }

        $this->periods->assertOpen($jurnal->company_id, $jurnal->periode);

        $jurnal->update([
            'status' => 'CANCELLED',
            'keterangan' => trim(($jurnal->keterangan ? $jurnal->keterangan.' | ' : '').'BATAL: '.($reason ?? '-')),
        ]);

        return $jurnal;
    }

    public function reverse(JurnalHeader $jurnal, ?string $reason = null): JurnalHeader
    {
        if ($jurnal->status !== 'POSTED') {
            throw InvalidJournalStateException::transition($jurnal->status, 'reverse');
        }

        $date = now();
        $periode = $date->format('Y-m');
        $this->periods->assertOpen($jurnal->company_id, $periode);

        return DB::transaction(function () use ($jurnal, $date, $periode, $reason) {
            $reversal = JurnalHeader::create([
                'no_jurnal' => $this->numerator->next('JU', $jurnal->company_id),
                'tgl_jurnal' => $date->toDateString(),
                'periode' => $periode,
                'company_id' => $jurnal->company_id,
                'jenis_dokumen' => $jurnal->jenis_dokumen,
                'no_dokumen' => $jurnal->no_dokumen,
                'keterangan' => 'Pembalik '.$jurnal->no_jurnal.($reason ? ' — '.$reason : ''),
                'status' => 'POSTED',
                'posted' => true,
                'total_debet' => $jurnal->total_kredit,
                'total_kredit' => $jurnal->total_debet,
                'reversal_of_id' => $jurnal->id,
                'posted_at' => now(),
            ]);

            // Swap debet/kredit on each line.
            foreach ($jurnal->detil as $line) {
                $reversal->detil()->create([
                    'urutan' => $line->urutan,
                    'kode_account' => $line->kode_account,
                    'cost_center_id' => $line->cost_center_id,
                    'debet' => $line->kredit,
                    'kredit' => $line->debet,
                    'keterangan' => 'Pembalik: '.($line->keterangan ?? ''),
                ]);
            }

            return $reversal->load('detil');
        });
    }

    /**
     * @param  array<int,JurnalLineDTO>  $lines
     * @return array{0: float, 1: float}
     */
    private function assertBalanced(array $lines): array
    {
        if (count($lines) < 2) {
            throw new UnbalancedJournalException('Jurnal minimal harus punya 2 baris (1 debet, 1 kredit).');
        }

        $totalDebet = 0.0;
        $totalKredit = 0.0;
        foreach ($lines as $line) {
            $totalDebet += $line->debet;
            $totalKredit += $line->kredit;
        }

        if (bccomp((string) round($totalDebet, 2), (string) round($totalKredit, 2), 2) !== 0) {
            throw UnbalancedJournalException::make($totalDebet, $totalKredit);
        }

        if (bccomp((string) round($totalDebet, 2), '0', 2) <= 0) {
            throw new UnbalancedJournalException('Total jurnal harus lebih dari nol.');
        }

        return [round($totalDebet, 2), round($totalKredit, 2)];
    }

    /**
     * @param  array<int,JurnalLineDTO>  $lines
     */
    private function writeLines(JurnalHeader $jurnal, array $lines): void
    {
        $urutan = 1;
        foreach ($lines as $line) {
            $jurnal->detil()->create([
                'urutan' => $urutan++,
                'kode_account' => $line->kodeAccount,
                'cost_center_id' => $line->costCenterId,
                'debet' => round($line->debet, 2),
                'kredit' => round($line->kredit, 2),
                'keterangan' => $line->keterangan,
            ]);
        }
    }
}
