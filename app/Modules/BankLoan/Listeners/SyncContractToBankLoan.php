<?php

namespace App\Modules\BankLoan\Listeners;

use App\Modules\BankLoan\Models\BankloanCustomer;
use App\Modules\BankLoan\Models\Contract;
use App\Modules\FO\MarketingSales\Events\KontrakCreated;
use Illuminate\Support\Facades\DB;

/**
 * Sync FO Kontrak (cara_bayar=KPR) → BankLoan. Idempotent (upsert by natural key):
 *   - bankloan_customers.code  = fo_customers.code
 *   - bankloan_contracts.contract_number = fo_kontrak.no_kontrak
 * Hanya mirror header (DRAFT); proses KPR (BI, bank, akad, RA, retensi) ditambah di BankLoan.
 */
class SyncContractToBankLoan
{
    public function handle(KontrakCreated $event): void
    {
        $kontrak = $event->kontrak;

        // Hanya kontrak KPR yang masuk pipeline BankLoan.
        if (strtoupper((string) $kontrak->cara_bayar) !== 'KPR') {
            return;
        }

        $kontrak->loadMissing(['customer', 'unit', 'reservasi', 'agent']);
        $cust = $kontrak->customer;
        if (! $cust) {
            return;
        }

        DB::transaction(function () use ($kontrak, $cust) {
            // code = natural key; fallback ke FO-{id} bila customer FO belum punya kode.
            $custCode = $cust->code ?: ('FO-'.$cust->id);

            $blCustomer = BankloanCustomer::updateOrCreate(
                ['code' => $custCode],
                [
                    'tenant_id' => $kontrak->tenant_id ?? 1,
                    'name' => $cust->full_name,
                    'nik' => $cust->nik,
                    'birth_place' => $cust->birth_place,
                    'birth_date' => $cust->birth_date,
                    'email' => $cust->email,
                    'phone_number' => $cust->mobile ?: $cust->phone,
                    'address' => $cust->jalan ?: $cust->address,
                ],
            );

            $dp = (float) ($kontrak->dp_amount ?? 0);
            $total = (float) ($kontrak->total_kontrak ?? 0);

            $mirror = [
                'tenant_id' => $kontrak->tenant_id ?? 1,
                'booking_number' => $kontrak->reservasi?->no_reservasi,
                'booking_date' => $kontrak->reservasi?->tgl_reservasi ?? $kontrak->tgl_kontrak,
                'customer_id' => $blCustomer->id,
                'project_id' => $kontrak->unit?->project_id ?? $cust->project_id,
                'unit_code' => $kontrak->unit?->no_unit,
                'sales_person_name' => $kontrak->agent?->name,
                'total_price' => $total,
                'submission_amount' => max(0, $total - $dp),
                'down_payment' => $dp,
            ];

            $contract = Contract::where('contract_number', $kontrak->no_kontrak)->first();
            if ($contract) {
                // Re-sync header FO; JANGAN timpa active_status (progres KPR).
                $contract->update($mirror);
            } else {
                Contract::create($mirror + ['contract_number' => $kontrak->no_kontrak, 'active_status' => 'DRAFT']);
            }
        });
    }
}
