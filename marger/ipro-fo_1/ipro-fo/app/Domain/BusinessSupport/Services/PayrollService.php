<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsPayrollRun;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll (penggajian) → jurnal GL otomatis.
 *   Dr Biaya Gaji & Tunjangan / Cr Hutang PPh21 + Cr Potongan (BPJS dll) + Cr Kas/Bank (net)
 */
final class PayrollService
{
    public function __construct(private readonly JournalService $journal) {}

    private function acc(string $key): ?int
    {
        $code = config("business_support.payroll.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }

    /** @param array{period_year:int, period_month:int, run_date:string, cash_account_id:int, pph21_pct?:float|int, bpjs_pct?:float|int} $data */
    public function processRun(array $data, ?int $userId = null): BsPayrollRun
    {
        return DB::transaction(function () use ($data, $userId): BsPayrollRun {
            $employees = BsEmployee::where('is_active', true)->orderBy('name')->get();
            if ($employees->isEmpty()) {
                throw new BusinessRuleException('Tidak ada karyawan aktif untuk diproses.', errorCode: 'PAYROLL_NO_EMPLOYEE');
            }
            $salaryAcc = $this->acc('salary_expense');
            $pphAcc    = $this->acc('pph21_payable');
            $dedAcc    = $this->acc('deduction_payable');
            if (! $salaryAcc || ! $pphAcc || ! $dedAcc) {
                throw new BusinessRuleException('Akun payroll (gaji/PPh21/potongan) belum diset.', errorCode: 'PAYROLL_NO_ACCOUNT');
            }

            $pphPct  = (float) ($data['pph21_pct'] ?? 5);
            $bpjsPct = (float) ($data['bpjs_pct'] ?? 1);

            $lines = [];
            $totBruto = $totPph = $totBpjs = $totNet = 0.0;
            foreach ($employees as $emp) {
                $gaji  = round((float) $emp->base_salary, 2);
                $tunj  = round((float) $emp->allowance, 2);
                $bruto = round($gaji + $tunj, 2);
                $pph   = round($bruto * $pphPct / 100, 2);
                $bpjs  = round($bruto * $bpjsPct / 100, 2);
                $net   = round($bruto - $pph - $bpjs, 2);
                $lines[] = [
                    'employee_id' => $emp->id, 'employee_name' => $emp->name,
                    'gaji_pokok' => $gaji, 'tunjangan' => $tunj, 'pph21' => $pph, 'bpjs' => $bpjs,
                    'potongan_lain' => 0, 'bruto' => $bruto, 'net' => $net,
                ];
                $totBruto += $bruto; $totPph += $pph; $totBpjs += $bpjs; $totNet += $net;
            }
            $totBruto = round($totBruto, 2); $totPph = round($totPph, 2); $totBpjs = round($totBpjs, 2); $totNet = round($totNet, 2);

            $run = BsPayrollRun::create([
                'run_number'      => $this->nextNumber($data['run_date']),
                'period_year'     => $data['period_year'],
                'period_month'    => $data['period_month'],
                'run_date'        => $data['run_date'],
                'description'     => 'Penggajian '.$data['period_year'].'/'.str_pad((string) $data['period_month'], 2, '0', STR_PAD_LEFT),
                'total_bruto'     => $totBruto,
                'total_deduction' => round($totPph + $totBpjs, 2),
                'total_net'       => $totNet,
                'status'          => 'posted',
                'created_by'      => $userId,
            ]);
            foreach ($lines as $l) {
                $run->lines()->create($l);
            }

            // Jurnal agregat.
            $jl = [['account_id' => $salaryAcc, 'debit' => $totBruto, 'credit' => 0, 'description' => 'Biaya gaji & tunjangan']];
            if ($totPph > 0) {
                $jl[] = ['account_id' => $pphAcc, 'debit' => 0, 'credit' => $totPph, 'description' => 'PPh 21 dipotong'];
            }
            if ($totBpjs > 0) {
                $jl[] = ['account_id' => $dedAcc, 'debit' => 0, 'credit' => $totBpjs, 'description' => 'BPJS/potongan'];
            }
            $jl[] = ['account_id' => $data['cash_account_id'], 'debit' => 0, 'credit' => $totNet, 'description' => 'Gaji dibayar'];

            $journal = $this->journal->post([
                'journal_date' => $data['run_date'],
                'source'       => 'cash',
                'reference'    => $run->run_number,
                'description'  => $run->description,
                'created_by'   => $userId,
            ], $jl);

            $run->update(['journal_id' => $journal->id]);

            return $run;
        });
    }

    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('PAY', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsPayrollRun::withTrashed()->whereYear('run_date', $d->year)->count() + 1;

        return sprintf('PAY/%s/%04d', $d->format('y').$d->format('m'), $count);
    }
}
