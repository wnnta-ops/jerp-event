<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCogsBudget;
use App\Domain\BusinessSupport\Models\BsCogsHpp;
use App\Domain\BusinessSupport\Models\BsCogsProvision;
use App\Domain\BusinessSupport\Models\BsCogsRecognition;
use App\Domain\Sales\Booking\Models\Booking;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cost of Good Sold — pengakuan HPP unit terjual.
 *   Dr Beban Pokok Penjualan / Cr Persediaan/CIP
 */
final class CogsService
{
    public function __construct(private readonly JournalService $journal) {}

    /** @param array{recognition_date:string, reference?:?string, description:string, inventory_account_id:int, cost_center_id?:?int, amount:float|int|string} $data */
    public function recognize(array $data, ?int $userId = null): BsCogsRecognition
    {
        return DB::transaction(function () use ($data, $userId): BsCogsRecognition {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new BusinessRuleException('Nilai HPP harus lebih dari 0.', errorCode: 'COGS_ZERO');
            }
            $cogsAccount = BsAccount::where('code', config('business_support.gl_accounts.cogs'))->value('id');
            if (! $cogsAccount) {
                throw new BusinessRuleException('Akun Beban Pokok Penjualan belum diset.', errorCode: 'COGS_NO_ACCOUNT');
            }

            $rec = BsCogsRecognition::create([
                'cogs_number'          => $this->nextNumber($data['recognition_date']),
                'recognition_date'     => $data['recognition_date'],
                'reference'            => $data['reference'] ?? null,
                'description'          => $data['description'],
                'inventory_account_id' => $data['inventory_account_id'],
                'cogs_account_id'      => $cogsAccount,
                'cost_center_id'       => $data['cost_center_id'] ?? null,
                'amount'               => $amount,
                'created_by'           => $userId,
            ]);

            $journal = $this->journal->post([
                'journal_date'   => $rec->recognition_date->toDateString(),
                'source'         => 'memorial',
                'reference'      => $rec->cogs_number,
                'description'    => 'HPP — '.$rec->description,
                'cost_center_id' => $rec->cost_center_id,
                'created_by'     => $userId,
            ], [
                ['account_id' => $cogsAccount, 'debit' => $amount, 'credit' => 0, 'description' => 'Beban pokok penjualan'],
                ['account_id' => $rec->inventory_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Pengurangan persediaan/CIP'],
            ]);

            $rec->update(['journal_id' => $journal->id]);

            return $rec;
        });
    }

    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('HPP', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsCogsRecognition::withTrashed()->whereYear('recognition_date', $d->year)->count() + 1;

        return sprintf('HPP/%s/%04d', $d->format('y').$d->format('m'), $count);
    }

    private const HPP_COMPONENTS = ['tanah', 'bangunan', 'sarana', 'permit', 'tools', 'kpr', 'bphtb'];

    /**
     * Generate HPP RealCost (POC) untuk unit terjual pada project/cluster/periode.
     * Total HPP per unit dialokasikan dari Budget proyek per komponen; pengakuan = Total HPP × progress%.
     *
     * @param array{project_id:int, location_id?:?int, year:int, month:int, default_progress?:float, progress?:array<int,float>} $f
     * @return array{generated:int}
     */
    public function generateHpp(array $f, ?int $userId = null): array
    {
        return DB::transaction(function () use ($f, $userId): array {
            $projectId = (int) $f['project_id'];
            $locId     = $f['location_id'] ?? null;
            $year      = (int) $f['year'];
            $month     = (int) $f['month'];
            $progress  = $f['progress'] ?? [];
            $defProg   = (float) ($f['default_progress'] ?? 100);

            // Pool budget per komponen (sum alokasi per category.component).
            $pool = array_fill_keys(self::HPP_COMPONENTS, 0.0);
            $budgets = BsCogsBudget::with('category:id,component')
                ->where('project_id', $projectId)->where('year', $year)
                ->when($locId, fn ($q, $id) => $q->where('location_id', $id))->get();
            foreach ($budgets as $b) {
                $comp = $b->category?->component ?: 'bangunan';
                if (! in_array($comp, self::HPP_COMPONENTS, true)) {
                    $comp = 'bangunan'; // 'lain' & lainnya → bangunan
                }
                $pool[$comp] += (float) $b->amount;
            }

            // Unit terjual (punya booking) di project (+cluster).
            $bookings = Booking::with('unit:id,unit_code,current_price,base_price,cluster_id')
                ->where('project_id', $projectId)
                ->whereHas('unit', fn ($q) => $locId ? $q->where('cluster_id', $locId) : $q)
                ->get();
            $bookings = $bookings->filter(fn ($bk) => $bk->unit !== null)->values();

            $totalBasis = (float) $bookings->sum(fn ($bk) => (float) ($bk->unit->current_price ?: $bk->unit->base_price ?: 1));
            if ($totalBasis <= 0) {
                $totalBasis = max(1, $bookings->count());
            }

            $generated = 0;
            foreach ($bookings as $bk) {
                $unit = $bk->unit;
                // Lewati bila sudah di-generate untuk periode ini.
                $exists = BsCogsHpp::where('unit_id', $unit->id)->where('period_year', $year)->where('period_month', $month)->exists();
                if ($exists) {
                    continue;
                }
                $basis = (float) ($unit->current_price ?: $unit->base_price ?: 1);
                $share = $basis / $totalBasis;

                $comp = [];
                $totalHpp = 0.0;
                foreach (self::HPP_COMPONENTS as $c) {
                    $comp[$c] = round($pool[$c] * $share, 2);
                    $totalHpp += $comp[$c];
                }
                $totalHpp = round($totalHpp, 2);

                $prog = round((float) ($progress[$unit->id] ?? $defProg), 2);
                $nilaiKontrak = (float) ($bk->total_price ?? 0);
                $dpp = (float) ($bk->base_price ?? 0);

                // Akumulasi pengakuan dari periode sebelumnya.
                $priorHpp = (float) BsCogsHpp::where('unit_id', $unit->id)
                    ->where(fn ($q) => $q->where('period_year', '<', $year)->orWhere(fn ($qq) => $qq->where('period_year', $year)->where('period_month', '<', $month)))
                    ->sum('hpp_recognized');
                $priorRev = (float) BsCogsHpp::where('unit_id', $unit->id)
                    ->where(fn ($q) => $q->where('period_year', '<', $year)->orWhere(fn ($qq) => $qq->where('period_year', $year)->where('period_month', '<', $month)))
                    ->sum('revenue_recognized');

                $targetHpp = round($totalHpp * $prog / 100, 2);
                $targetRev = round($nilaiKontrak * $prog / 100, 2);
                $hppRec = round(max(0, $targetHpp - $priorHpp), 2);
                $revRec = round(max(0, $targetRev - $priorRev), 2);

                BsCogsHpp::create(array_merge([
                    'cogs_id'        => $this->nextCogsId($year, $month),
                    'period_year'    => $year, 'period_month' => $month,
                    'project_id'     => $projectId, 'location_id' => $locId,
                    'unit_id'        => $unit->id, 'booking_id' => $bk->id,
                    'unit_code'      => $unit->unit_code, 'customer' => null, 'no_kontrak' => $bk->booking_number ?? null,
                    'dpp'            => $dpp, 'nilai_kontrak' => $nilaiKontrak,
                    'jumlah_pembayaran' => 0, 'persen_pelunasan' => 0, 'promo' => 0, 'blt' => 0, 'komisi' => 0,
                    'total_hpp'      => $totalHpp, 'progress_pct' => $prog,
                    'revenue_recognized' => $revRec, 'hpp_recognized' => $hppRec,
                    'revenue_accum'  => round($priorRev + $revRec, 2), 'hpp_accum' => round($priorHpp + $hppRec, 2),
                    'status'         => 'generated', 'created_by' => $userId,
                ], [
                    'hpp_tanah' => $comp['tanah'], 'hpp_bangunan' => $comp['bangunan'], 'hpp_sarana' => $comp['sarana'],
                    'hpp_permit' => $comp['permit'], 'hpp_tools' => $comp['tools'], 'hpp_kpr' => $comp['kpr'], 'hpp_bphtb' => $comp['bphtb'],
                ]));
                $generated++;
            }

            return ['generated' => $generated];
        });
    }

    /** Posting Jurnal HPP Persediaan — kapitalisasi Total HPP unit: Dr Persediaan Unit / Cr CIP. */
    public function postPersediaan(BsCogsHpp $h, string $date, ?int $userId = null): void
    {
        DB::transaction(function () use ($h, $date, $userId): void {
            if ($h->status !== 'generated') {
                throw new BusinessRuleException('HPP sudah diposting persediaan.', errorCode: 'COGS_POSTED');
            }
            $prov = BsCogsProvision::where('project_id', $h->project_id)->first();
            $persediaan = $prov?->acc_persediaan_unit_id ?: $this->acc('inventory');
            $cip        = $prov?->acc_cip_id ?: $this->acc('inventory');
            if (! $persediaan || ! $cip) {
                throw new BusinessRuleException('Akun Persediaan Unit / CIP belum diset di Ketentuan.', errorCode: 'COGS_NO_ACC');
            }
            $amt = round((float) $h->total_hpp, 2);
            if ($amt <= 0) {
                throw new BusinessRuleException('Total HPP nol — tidak ada yang diposting.', errorCode: 'COGS_ZERO');
            }
            $j = $this->journal->post([
                'journal_date' => $date, 'source' => 'memorial', 'reference' => $h->cogs_id,
                'description'  => 'HPP Persediaan RealCost — Unit '.$h->unit_code, 'created_by' => $userId,
            ], [
                ['account_id' => $persediaan, 'debit' => $amt, 'credit' => 0, 'description' => 'Persediaan unit '.$h->unit_code],
                ['account_id' => $cip, 'debit' => 0, 'credit' => $amt, 'description' => 'Kapitalisasi dari CIP'],
            ]);
            $h->update(['status' => 'posted_persediaan', 'journal_persediaan_id' => $j->id]);
        });
    }

    /** Posting Jurnal HPP — akui beban: Dr Beban Pokok / Cr Persediaan Unit sebesar HPP diakui (POC). */
    public function postHpp(BsCogsHpp $h, string $date, ?int $userId = null): void
    {
        DB::transaction(function () use ($h, $date, $userId): void {
            if ($h->status === 'posted_hpp') {
                throw new BusinessRuleException('HPP sudah diposting.', errorCode: 'COGS_POSTED');
            }
            $prov = BsCogsProvision::where('project_id', $h->project_id)->first();
            $hppAcc     = $prov?->acc_hpp_id ?: BsAccount::where('code', config('business_support.gl_accounts.cogs'))->value('id');
            $persediaan = $prov?->acc_persediaan_unit_id ?: $this->acc('inventory');
            if (! $hppAcc || ! $persediaan) {
                throw new BusinessRuleException('Akun Beban Pokok / Persediaan Unit belum diset.', errorCode: 'COGS_NO_ACC');
            }
            $amt = round((float) $h->hpp_recognized, 2);
            if ($amt <= 0) {
                throw new BusinessRuleException('HPP diakui nol — tidak ada yang diposting.', errorCode: 'COGS_ZERO');
            }
            $j = $this->journal->post([
                'journal_date' => $date, 'source' => 'memorial', 'reference' => $h->cogs_id,
                'description'  => 'Pengakuan HPP RealCost ('.$h->progress_pct.'%) — Unit '.$h->unit_code, 'created_by' => $userId,
            ], [
                ['account_id' => $hppAcc, 'debit' => $amt, 'credit' => 0, 'description' => 'Beban pokok penjualan unit '.$h->unit_code],
                ['account_id' => $persediaan, 'debit' => 0, 'credit' => $amt, 'description' => 'Pengurangan persediaan unit'],
            ]);
            $h->update(['status' => 'posted_hpp', 'journal_hpp_id' => $j->id]);
        });
    }

    private function acc(string $key): ?int
    {
        $map = ['inventory' => 'contractor.material_inventory'];
        $code = config('business_support.'.($map[$key] ?? ''));

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }

    private function nextCogsId(int $year, int $month): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('COGS', Carbon::parse(sprintf('%04d-%02d-01', $year, $month)), [], fn () => $this->nextCogsIdLama($year, $month));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextCogsIdLama(int $year, int $month): string
    {
        $count = BsCogsHpp::whereYear('created_at', $year)->count() + 1;

        return sprintf('COGS/MUP/%02d/%02d/%05d', $month, $year % 100, $count);
    }
}
