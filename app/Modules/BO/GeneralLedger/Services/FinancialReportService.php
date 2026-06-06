<?php

namespace App\Modules\BO\GeneralLedger\Services;

use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives financial statements from posted journal lines (bo_jurnal_detil)
 * joined to the chart of accounts. All figures come straight from the GL, so
 * the trial balance is always balanced by construction.
 */
class FinancialReportService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Per-account debit/credit totals + net balance. Each row is a stdClass with
     * fields: kode, nama, tipe_akun, saldo_normal, debet, kredit, saldo.
     *
     * @return Collection<int,\stdClass>
     */
    public function trialBalance(?int $companyId = null, ?string $sampaiPeriode = null): Collection
    {
        $tenantId = $this->tenant->id();

        return DB::table('bo_jurnal_detil as d')
            ->join('bo_jurnal_header as h', 'h.id', '=', 'd.jurnal_id')
            ->join('bo_chart_of_accounts as a', function ($join) use ($tenantId) {
                $join->on('a.kode', '=', 'd.kode_account')->where('a.tenant_id', '=', $tenantId);
            })
            ->where('h.tenant_id', $tenantId)
            ->where('h.status', 'POSTED')
            ->whereNull('h.deleted_at')
            ->when($companyId, fn ($q) => $q->where('h.company_id', $companyId))
            ->when($sampaiPeriode, fn ($q) => $q->where('h.periode', '<=', $sampaiPeriode))
            ->groupBy('a.kode', 'a.nama', 'a.tipe_akun', 'a.saldo_normal')
            ->select([
                'a.kode', 'a.nama', 'a.tipe_akun', 'a.saldo_normal',
                DB::raw('SUM(d.debet) as debet'),
                DB::raw('SUM(d.kredit) as kredit'),
            ])
            ->orderBy('a.kode')
            ->get()
            ->map(function ($row) {
                $row->debet = (float) $row->debet;
                $row->kredit = (float) $row->kredit;
                // Net balance in the account's normal direction.
                $row->saldo = $row->saldo_normal === 'DEBET'
                    ? $row->debet - $row->kredit
                    : $row->kredit - $row->debet;

                return $row;
            });
    }

    /**
     * Income statement totals.
     *
     * @return array{pendapatan:float,beban:float,laba:float}
     */
    public function labaRugi(?int $companyId = null, ?string $sampaiPeriode = null): array
    {
        $tb = $this->trialBalance($companyId, $sampaiPeriode);
        $pendapatan = (float) $tb->where('tipe_akun', 'REVENUE')->sum('saldo');
        $beban = (float) $tb->where('tipe_akun', 'EXPENSE')->sum('saldo');

        return [
            'pendapatan' => $pendapatan,
            'beban' => $beban,
            'laba' => round($pendapatan - $beban, 2),
        ];
    }

    /**
     * Balance sheet totals (laba berjalan folded into equity).
     *
     * @return array{aset:float,liabilitas:float,ekuitas:float,laba_berjalan:float,seimbang:bool}
     */
    public function neraca(?int $companyId = null, ?string $sampaiPeriode = null): array
    {
        $tb = $this->trialBalance($companyId, $sampaiPeriode);
        $aset = (float) $tb->where('tipe_akun', 'ASSET')->sum('saldo');
        $liabilitas = (float) $tb->where('tipe_akun', 'LIABILITY')->sum('saldo');
        $ekuitas = (float) $tb->where('tipe_akun', 'EQUITY')->sum('saldo');
        $laba = $this->labaRugi($companyId, $sampaiPeriode)['laba'];

        return [
            'aset' => round($aset, 2),
            'liabilitas' => round($liabilitas, 2),
            'ekuitas' => round($ekuitas, 2),
            'laba_berjalan' => $laba,
            'seimbang' => abs($aset - ($liabilitas + $ekuitas + $laba)) < 0.01,
        ];
    }

    /**
     * @return array{total_debet:float,total_kredit:float,seimbang:bool}
     */
    public function trialBalanceTotals(?int $companyId = null, ?string $sampaiPeriode = null): array
    {
        $tb = $this->trialBalance($companyId, $sampaiPeriode);
        $d = (float) $tb->sum('debet');
        $k = (float) $tb->sum('kredit');

        return ['total_debet' => round($d, 2), 'total_kredit' => round($k, 2), 'seimbang' => abs($d - $k) < 0.01];
    }
}
