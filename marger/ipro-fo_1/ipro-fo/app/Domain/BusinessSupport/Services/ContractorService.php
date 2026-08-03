<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\BusinessSupport\Models\BsWorkOrderAddendum;
use App\Domain\BusinessSupport\Models\BsWorkOrderMaterial;
use App\Domain\BusinessSupport\Models\BsWorkOrderMaterialReturn;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgress;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgressMutation;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contractor Management (SPK).
 *   Progress termin : Dr CIP/Konstruksi + Dr PPN Masukan / Cr Hutang Usaha (net) + Cr Hutang Retensi
 *   Pelunasan       : Dr Hutang Usaha / Cr Kas
 *   Cairkan retensi : Dr Hutang Retensi / Cr Kas
 */
final class ContractorService
{
    public function __construct(private readonly JournalService $journal) {}

    private function acc(string $key): ?int
    {
        $code = config("business_support.contractor.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }

    /**
     * SUB TOTAL 1 tabel Rincian Harga = jumlah baris BoQ (qty × harga satuan).
     * Tanpa baris BoQ, nilai kontrak dipakai sebagai basis (SPK lama/ringkas).
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function subTotal1(array $items, float $contractValue = 0.0): float
    {
        $sum = 0.0;
        foreach ($items as $it) {
            $sum += round((float) ($it['qty'] ?? 0), 2) * round((float) ($it['unit_price'] ?? 0), 2);
        }

        return round($sum > 0 ? $sum : $contractValue, 2);
    }

    /**
     * Nominal profit dari persentase — basis MARKUP: Profit = SUB TOTAL 1 × %.
     * (Sesuai SPK 083/Infra. Bila kelak dipakai basis MARGIN seperti SPK 076/Housing,
     * cukup ganti rumus di sini: $base / (1 - $pct/100) - $base.)
     */
    public static function profitAmount(float $subTotal1, float $pct): float
    {
        return round($subTotal1 * $pct / 100, 2);
    }

    /**
     * @param array{spk_date:string, supplier_id:int, cost_center_id?:?int, construction_account_id:int, title:string,
     *   contract_value:float|int|string, retention_pct?:float|int|string, ppn_pct?:float|int|string, notes?:?string,
     *   project_id?:?int, jenis?:?string, est_start?:?string, est_end?:?string, is_pkp?:bool, releaser?:?string,
     *   pic_project?:?string, no_manual?:?string, items?:array, terms?:array} $data
     */
    public function createSpk(array $data, ?int $userId = null): BsWorkOrder
    {
        return DB::transaction(function () use ($data, $userId): BsWorkOrder {
            $profitPct = round((float) ($data['profit_pct'] ?? 0), 2);

            $wo = BsWorkOrder::create([
                'spk_number'              => $this->nextNumber($data['spk_date']),
                'no_manual'               => $data['no_manual'] ?? null,
                'spk_date'                => $data['spk_date'],
                'est_start'               => $data['est_start'] ?? null,
                'est_end'                 => $data['est_end'] ?? null,
                'project_id'              => $data['project_id'] ?? null,
                'supplier_id'             => $data['supplier_id'],
                'cost_center_id'          => $data['cost_center_id'] ?? null,
                'construction_account_id' => $data['construction_account_id'],
                'title'                   => $data['title'],
                'cluster'                 => $data['cluster'] ?? null,
                'tahap'                   => $data['tahap'] ?? null,
                'jenis'                   => $data['jenis'] ?? 'bangunan',
                'releaser'                => $data['releaser'] ?? null,
                'pic_project'             => $data['pic_project'] ?? null,
                'is_pkp'                  => $data['is_pkp'] ?? true,
                'contract_value'          => round((float) $data['contract_value'], 2),
                'profit_pct'              => $profitPct,
                'nominal_profit'          => self::profitAmount(
                    self::subTotal1($data['items'] ?? [], (float) $data['contract_value']),
                    $profitPct,
                ),
                'retention_pct'           => round((float) ($data['retention_pct'] ?? 0), 2),
                'advance_pct'             => round((float) ($data['advance_pct'] ?? 0), 2),
                'ppn_pct'                 => round((float) ($data['ppn_pct'] ?? 0), 2),
                'status'                  => 'active',
                'notes'                   => $data['notes'] ?? null,
                'created_by'              => $userId,
            ]);

            foreach (($data['items'] ?? []) as $i => $it) {
                $desc = trim((string) ($it['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $qty   = round((float) ($it['qty'] ?? 1), 2);
                $price = round((float) ($it['unit_price'] ?? 0), 2);
                $wo->items()->create([
                    'seq' => $i + 1, 'description' => $desc, 'unit' => $it['unit'] ?? null,
                    'qty' => $qty, 'unit_price' => $price, 'amount' => round($qty * $price, 2),
                ]);
            }
            foreach (($data['terms'] ?? []) as $i => $t) {
                $pct = round((float) ($t['percentage'] ?? 0), 2);
                $amt = round((float) ($t['amount'] ?? 0), 2);
                if ($pct <= 0 && $amt <= 0) {
                    continue;
                }
                $wo->terms()->create([
                    'seq' => $i + 1, 'description' => $t['description'] ?? null,
                    'percentage' => $pct, 'amount' => $amt ?: round((float) $wo->contract_value * $pct / 100, 2),
                    'due_date' => $t['due_date'] ?? null,
                ]);
            }

            // Alokasi unit rumah (Detil Pekerjaan per unit) — harga satuan = porsi kontrak per unit.
            // Lewati porsi <= 0 (tak bermakna) & duplikat unit (jaga unique + hindari 500).
            $seenUnits = [];
            foreach (($data['units'] ?? []) as $i => $u) {
                $unitId = (int) ($u['unit_id'] ?? 0);
                $price  = round((float) ($u['unit_price'] ?? 0), 2);
                if ($unitId <= 0 || $price <= 0 || isset($seenUnits[$unitId])) {
                    continue;
                }
                $seenUnits[$unitId] = true;
                $wo->units()->create([
                    'unit_id'    => $unitId,
                    'unit_code'  => isset($u['unit_code']) && $u['unit_code'] !== '' ? mb_substr((string) $u['unit_code'], 0, 50) : null,
                    'unit_price' => $price,
                    'seq'        => $i + 1,
                ]);
            }

            return $wo;
        });
    }

    /** @param array{progress_date:string, description:string, progress_value:float|int|string, releaser?:?string, pic?:?string, no_manual?:?string} $data */
    public function addProgress(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderProgress
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderProgress {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('SPK sudah ditutup.', errorCode: 'SPK_CLOSED');
            }
            $dpp = round((float) $data['progress_value'], 2);
            if ($dpp <= 0) {
                throw new BusinessRuleException('Nilai progress harus lebih dari 0.', errorCode: 'SPK_ZERO');
            }
            if ($dpp > (float) $wo->remaining_value + 0.01) {
                throw new BusinessRuleException('Progress melebihi sisa nilai kontrak ('.number_format((float) $wo->remaining_value, 2).').', errorCode: 'SPK_OVER');
            }

            $retention = round($dpp * (float) $wo->retention_pct / 100, 2);
            $ppn       = round($dpp * (float) $wo->ppn_pct / 100, 2);
            $net       = round($dpp + $ppn - $retention, 2);

            $payable       = $this->acc('payable');
            $retentionAcc  = $retention > 0 ? $this->acc('retention_payable') : null;
            if (! $payable) {
                throw new BusinessRuleException('Akun Hutang Usaha belum diset.', errorCode: 'SPK_NO_PAYABLE');
            }
            if ($retention > 0 && ! $retentionAcc) {
                throw new BusinessRuleException('Akun Hutang Retensi belum diset.', errorCode: 'SPK_NO_RETENTION_ACC');
            }

            // Sisi debit.
            $ppnAcc = $ppn > 0 ? $this->acc('ppn_in') : null;
            $lines  = [];
            if ($ppn > 0 && $ppnAcc) {
                $lines[] = ['account_id' => $wo->construction_account_id, 'debit' => $dpp, 'credit' => 0, 'description' => 'Progress '.$wo->title];
                $lines[] = ['account_id' => $ppnAcc, 'debit' => $ppn, 'credit' => 0, 'description' => 'PPN Masukan'];
            } else {
                $lines[] = ['account_id' => $wo->construction_account_id, 'debit' => round($dpp + $ppn, 2), 'credit' => 0, 'description' => 'Progress '.$wo->title];
            }
            // Sisi kredit.
            $lines[] = ['account_id' => $payable, 'debit' => 0, 'credit' => $net, 'description' => 'Hutang kontraktor '.($wo->supplier?->name ?? '')];
            if ($retention > 0) {
                $lines[] = ['account_id' => $retentionAcc, 'debit' => 0, 'credit' => $retention, 'description' => 'Retensi ditahan'];
            }

            $journal = $this->journal->post([
                'journal_date'   => $data['progress_date'],
                'source'         => 'ap',
                'reference'      => $wo->spk_number,
                'description'    => 'Progress SPK '.$wo->spk_number.' — '.$data['description'],
                'cost_center_id' => $wo->cost_center_id,
                'created_by'     => $userId,
            ], $lines);

            $progress = $wo->progress()->create([
                'progress_date'    => $data['progress_date'],
                'description'      => $data['description'],
                'progress_value'   => $dpp,
                'retention_amount' => $retention,
                'ppn_amount'       => $ppn,
                'net_payable'      => $net,
                'status'           => 'unpaid',
                'journal_id'       => $journal->id,
                'kpl_number'       => $this->nextKplNumber($data['progress_date']),
                'releaser'         => $data['releaser'] ?? null,
                'pic'              => $data['pic'] ?? null,
                'no_manual'        => $data['no_manual'] ?? null,
                'contractor_signer'=> $data['contractor_signer'] ?? null,
                'created_by'       => $userId,
            ]);

            $wo->update([
                'progress_value' => round((float) $wo->progress_value + $dpp, 2),
                'retention_held' => round((float) $wo->retention_held + $retention, 2),
            ]);

            return $progress;
        });
    }

    /**
     * Registrasi KPL MASSAL per unit rumah. Nilai (DPP) tiap unit = porsi kontrak SPK
     * (harga unit / Σ harga unit project) × progress%. Jurnal termin = AGREGAT (1 posting,
     * mirror addProgress). Rincian per unit disimpan → feed HPP/CIP per unit (modul COGS).
     *
     * @param array{project_id:int, progress_date:string, description:string, units:array<int,array{unit_id:int,progress_pct:float}>, releaser?:string, pic?:string, no_manual?:string, contractor_signer?:string} $data
     */
    public function addProgressBulk(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderProgress
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderProgress {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('SPK sudah ditutup.', errorCode: 'SPK_CLOSED');
            }

            // Porsi Kontrak per unit = harga satuan yang dialokasikan pada SPK (bs_work_order_units).
            // DPP = Porsi Kontrak × progress%. Bila SPK belum punya alokasi unit → fallback proporsional (perilaku lama).
            $alloc = $wo->units()->get()->keyBy('unit_id'); // unit_id => BsWorkOrderUnit
            $lines = [];

            if ($alloc->isNotEmpty()) {
                // DPP yang sudah terpakai per unit dari KPL sebelumnya (ceiling = porsi kontrak / unit_price).
                $consumed = \App\Domain\BusinessSupport\Models\BsWorkOrderProgressUnit::query()
                    ->whereHas('progress', fn ($q) => $q->where('work_order_id', $wo->id))
                    ->selectRaw('unit_id, SUM(dpp_value) AS used')
                    ->groupBy('unit_id')
                    ->pluck('used', 'unit_id');

                foreach ((array) ($data['units'] ?? []) as $row) {
                    $uid = (int) ($row['unit_id'] ?? 0);
                    $a   = $alloc->get($uid);
                    $pct = round((float) ($row['progress_pct'] ?? 0), 2);
                    if (! $a || $pct <= 0) {
                        continue;
                    }
                    $portion = round((float) $a->unit_price, 2);
                    $dpp     = round($portion * $pct / 100, 2);
                    if ($dpp <= 0) {
                        continue;
                    }
                    // Cegah akumulasi progress per unit melebihi porsi kontraknya (jaga HPP/CIP per unit).
                    $used = round((float) ($consumed[$uid] ?? 0), 2);
                    if ($used + $dpp > $portion + 0.01) {
                        throw new BusinessRuleException('Progress unit '.$a->unit_code.' melebihi porsi kontrak ('.number_format($portion, 2).').', errorCode: 'SPK_UNIT_OVER');
                    }
                    $consumed[$uid] = $used + $dpp;   // tangkap juga duplikat dalam satu submission
                    $lines[] = ['unit_id' => $uid, 'unit_code' => $a->unit_code, 'progress_pct' => $pct, 'contract_portion' => $portion, 'dpp_value' => $dpp];
                }
            } else {
                $projectId = (int) ($data['project_id'] ?? 0);
                if ($projectId <= 0) {
                    throw new BusinessRuleException('Pilih Project sumber unit rumah.', errorCode: 'SPK_NO_PROJECT');
                }
                $allUnits  = Unit::where('project_id', $projectId)->get(['id', 'unit_code', 'current_price', 'base_price']);
                if ($allUnits->isEmpty()) {
                    throw new BusinessRuleException('Project belum memiliki unit rumah.', errorCode: 'SPK_NO_UNITS');
                }
                $priceOf  = fn ($u) => (float) ($u->current_price ?: $u->base_price ?: 0);
                $denom    = (float) $allUnits->sum($priceOf);
                $equal    = $denom <= 0; // fallback: bagi rata bila semua harga kosong
                $contract = (float) $wo->contract_value;
                $byId     = $allUnits->keyBy('id');
                foreach ((array) ($data['units'] ?? []) as $row) {
                    $u   = $byId->get((int) ($row['unit_id'] ?? 0));
                    $pct = round((float) ($row['progress_pct'] ?? 0), 2);
                    if (! $u || $pct <= 0) {
                        continue;
                    }
                    $portion = $equal ? round($contract / $allUnits->count(), 2) : round($contract * $priceOf($u) / $denom, 2);
                    $dpp     = round($portion * $pct / 100, 2);
                    if ($dpp <= 0) {
                        continue;
                    }
                    $lines[] = ['unit_id' => $u->id, 'unit_code' => $u->unit_code, 'progress_pct' => $pct, 'contract_portion' => $portion, 'dpp_value' => $dpp];
                }
            }

            if ($lines === []) {
                throw new BusinessRuleException('Isi progress% minimal satu unit rumah.', errorCode: 'SPK_BULK_EMPTY');
            }

            return $this->persistAggregateProgress($wo, array_merge($data, ['category' => 'housing']), $lines, count($lines).' unit', $userId);
        });
    }

    /**
     * Registrasi KPL MASSAL per ITEM pekerjaan (INFRA / LAIN-LAIN, berbasis BoQ). DPP tiap item = nilai × progress%.
     * Jurnal termin AGREGAT (sama seperti HOUSING). Rincian item disimpan (line_label/satuan/volume).
     *
     * @param array{category?:string, progress_date:string, description:string, items:array<int,array{label?:string,satuan?:string,volume?:float,nilai:float,progress_pct:float}>, releaser?:string, pic?:string, no_manual?:string, contractor_signer?:string} $data
     */
    public function addProgressItems(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderProgress
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderProgress {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('SPK sudah ditutup.', errorCode: 'SPK_CLOSED');
            }
            $cat = in_array($data['category'] ?? '', ['infra', 'lain_lain'], true) ? $data['category'] : 'infra';

            $lines = [];
            foreach ((array) ($data['items'] ?? []) as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                $nilai = round((float) ($row['nilai'] ?? 0), 2);
                $pct   = round((float) ($row['progress_pct'] ?? 0), 2);
                if ($label === '' || $nilai <= 0 || $pct <= 0) {
                    continue;
                }
                $dpp = round($nilai * $pct / 100, 2);
                if ($dpp <= 0) {
                    continue;
                }
                $lines[] = [
                    'unit_id'          => null,
                    'line_label'       => mb_substr($label, 0, 200),
                    'satuan'           => isset($row['satuan']) && $row['satuan'] !== '' ? mb_substr((string) $row['satuan'], 0, 30) : null,
                    'volume'           => isset($row['volume']) && $row['volume'] !== '' ? round((float) $row['volume'], 2) : null,
                    'progress_pct'     => $pct,
                    'contract_portion' => $nilai,
                    'dpp_value'        => $dpp,
                ];
            }
            if ($lines === []) {
                throw new BusinessRuleException('Isi minimal satu item pekerjaan dengan Nilai & progress% > 0.', errorCode: 'SPK_ITEM_EMPTY');
            }

            return $this->persistAggregateProgress($wo, array_merge($data, ['category' => $cat]), $lines, count($lines).' item', $userId);
        });
    }

    /**
     * Inti bersama: rincian baris (unit/item) → total DPP → jurnal termin AGREGAT + simpan KPL + rincian + update SPK.
     * Dipanggil dlm transaksi oleh addProgressBulk (HOUSING) & addProgressItems (INFRA/LAIN).
     *
     * @param array<int,array<string,mixed>> $childLines tiap baris WAJIB punya dpp_value + field rincian utk disimpan
     */
    private function persistAggregateProgress(BsWorkOrder $wo, array $data, array $childLines, string $countLabel, ?int $userId): BsWorkOrderProgress
    {
        $totalDpp = round(array_sum(array_map(fn ($l) => (float) $l['dpp_value'], $childLines)), 2);
        if ($totalDpp <= 0) {
            throw new BusinessRuleException('Total progress harus lebih dari 0.', errorCode: 'SPK_BULK_EMPTY');
        }
        if ($totalDpp > (float) $wo->remaining_value + 0.01) {
            throw new BusinessRuleException('Total progress melebihi sisa nilai kontrak ('.number_format((float) $wo->remaining_value, 2).').', errorCode: 'SPK_OVER');
        }

        // ── Jurnal agregat (identik pola addProgress) ──
        $retention = round($totalDpp * (float) $wo->retention_pct / 100, 2);
        $ppn       = round($totalDpp * (float) $wo->ppn_pct / 100, 2);
        $net       = round($totalDpp + $ppn - $retention, 2);
        $payable      = $this->acc('payable');
        $retentionAcc = $retention > 0 ? $this->acc('retention_payable') : null;
        if (! $payable) {
            throw new BusinessRuleException('Akun Hutang Usaha belum diset.', errorCode: 'SPK_NO_PAYABLE');
        }
        if ($retention > 0 && ! $retentionAcc) {
            throw new BusinessRuleException('Akun Hutang Retensi belum diset.', errorCode: 'SPK_NO_RETENTION_ACC');
        }
        $ppnAcc = $ppn > 0 ? $this->acc('ppn_in') : null;
        $jlines = [];
        if ($ppn > 0 && $ppnAcc) {
            $jlines[] = ['account_id' => $wo->construction_account_id, 'debit' => $totalDpp, 'credit' => 0, 'description' => 'Progress '.$wo->title];
            $jlines[] = ['account_id' => $ppnAcc, 'debit' => $ppn, 'credit' => 0, 'description' => 'PPN Masukan'];
        } else {
            $jlines[] = ['account_id' => $wo->construction_account_id, 'debit' => round($totalDpp + $ppn, 2), 'credit' => 0, 'description' => 'Progress '.$wo->title];
        }
        $jlines[] = ['account_id' => $payable, 'debit' => 0, 'credit' => $net, 'description' => 'Hutang kontraktor '.($wo->supplier?->name ?? '')];
        if ($retention > 0) {
            $jlines[] = ['account_id' => $retentionAcc, 'debit' => 0, 'credit' => $retention, 'description' => 'Retensi ditahan'];
        }
        $journal = $this->journal->post([
            'journal_date'   => $data['progress_date'],
            'source'         => 'ap',
            'reference'      => $wo->spk_number,
            'description'    => 'Progress SPK '.$wo->spk_number.' — '.$data['description'].' ('.$countLabel.')',
            'cost_center_id' => $wo->cost_center_id,
            'created_by'     => $userId,
        ], $jlines);

        $progress = $wo->progress()->create([
            'progress_date'     => $data['progress_date'],
            'description'       => $data['description'],
            'category'          => $data['category'] ?? 'housing',
            'progress_value'    => $totalDpp,
            'retention_amount'  => $retention,
            'ppn_amount'        => $ppn,
            'net_payable'       => $net,
            'status'            => 'unpaid',
            'journal_id'        => $journal->id,
            'kpl_number'        => $this->nextKplNumber($data['progress_date']),
            'releaser'          => $data['releaser'] ?? null,
            'pic'               => $data['pic'] ?? null,
            'no_manual'         => $data['no_manual'] ?? null,
            'contractor_signer' => $data['contractor_signer'] ?? null,
            'created_by'        => $userId,
        ]);

        foreach ($childLines as $ln) {
            $progress->progressUnits()->create($ln);
        }

        $wo->update([
            'progress_value' => round((float) $wo->progress_value + $totalDpp, 2),
            'retention_held' => round((float) $wo->retention_held + $retention, 2),
        ]);

        return $progress;
    }

    /** @param array{payment_date:string, cash_account_id:int} $data */
    public function payProgress(BsWorkOrderProgress $progress, array $data, ?int $userId = null): BsWorkOrderProgress
    {
        return DB::transaction(function () use ($progress, $data, $userId): BsWorkOrderProgress {
            if ($progress->status !== 'unpaid') {
                throw new BusinessRuleException('Termin sudah dibayar.', errorCode: 'SPK_PAID');
            }
            $amount = round((float) $progress->net_payable, 2);
            $wo = $progress->workOrder;

            $journal = $this->journal->post([
                'journal_date'   => $data['payment_date'],
                'source'         => 'cash',
                'reference'      => $wo->spk_number,
                'description'    => 'Pembayaran termin SPK '.$wo->spk_number,
                'cost_center_id' => $wo->cost_center_id,
                'created_by'     => $userId,
            ], [
                ['account_id' => $this->acc('payable'), 'debit' => $amount, 'credit' => 0, 'description' => 'Pelunasan kontraktor'],
                ['account_id' => $data['cash_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Kas/Bank keluar'],
            ]);

            $progress->update(['status' => 'paid', 'paid_at' => $data['payment_date'], 'pay_journal_id' => $journal->id]);

            return $progress;
        });
    }

    /** @param array{settled_date:string, cash_account_id:int} $data */
    public function releaseRetention(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrder
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrder {
            $held = round((float) $wo->retention_held, 2);
            if ($held <= 0) {
                throw new BusinessRuleException('Tidak ada retensi yang tertahan.', errorCode: 'SPK_NO_RETENTION');
            }

            $this->journal->post([
                'journal_date'   => $data['settled_date'],
                'source'         => 'cash',
                'reference'      => $wo->spk_number,
                'description'    => 'Pencairan retensi SPK '.$wo->spk_number,
                'cost_center_id' => $wo->cost_center_id,
                'created_by'     => $userId,
            ], [
                ['account_id' => $this->acc('retention_payable'), 'debit' => $held, 'credit' => 0, 'description' => 'Pelunasan retensi'],
                ['account_id' => $data['cash_account_id'], 'debit' => 0, 'credit' => $held, 'description' => 'Kas/Bank keluar'],
            ]);

            $wo->update(['retention_held' => 0, 'retention_released_at' => $data['settled_date'], 'status' => 'closed']);

            return $wo;
        });
    }

    /**
     * Addendum SPK — menambah/mengurangi nilai kontrak (komitmen, tanpa jurnal).
     *
     * @param array{addendum_date:string, value_delta:float|int|string, note?:?string} $data
     */
    public function addendum(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderAddendum
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderAddendum {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('Addendum hanya untuk SPK aktif.', errorCode: 'SPK_NOT_ACTIVE');
            }
            $delta   = round((float) $data['value_delta'], 2);
            if (abs($delta) < 0.009) {
                throw new BusinessRuleException('Nilai addendum tidak boleh nol.', errorCode: 'SPK_ADD_ZERO');
            }
            $old     = round((float) $wo->contract_value, 2);
            $new     = round($old + $delta, 2);
            if ($new < (float) $wo->progress_value - 0.009) {
                throw new BusinessRuleException('Nilai kontrak baru (Rp '.number_format($new, 0, ',', '.').') tidak boleh di bawah progress terpakai (Rp '.number_format((float) $wo->progress_value, 0, ',', '.').').', errorCode: 'SPK_ADD_BELOW_PROGRESS');
            }

            $add = BsWorkOrderAddendum::create([
                'work_order_id'      => $wo->id,
                'addendum_date'      => $data['addendum_date'],
                'kind'               => 'value',
                'value_delta'        => $delta,
                'old_contract_value' => $old,
                'new_contract_value' => $new,
                'old_ppn_pct'        => (float) $wo->ppn_pct,
                'new_ppn_pct'        => (float) $wo->ppn_pct,
                'note'               => $data['note'] ?? null,
                'created_by'         => $userId,
            ]);

            $wo->update([
                'contract_value' => $new,
                'addendum_value' => round((float) $wo->addendum_value + $delta, 2),
            ]);

            return $add;
        });
    }

    /**
     * Adjust PPN SPK — mengubah persentase PPN untuk progress berikutnya (tanpa mengubah jurnal lama).
     *
     * @param array{addendum_date:string, new_ppn_pct:float|int|string, note?:?string} $data
     */
    public function adjustPpn(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderAddendum
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderAddendum {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('Adjust PPN hanya untuk SPK aktif.', errorCode: 'SPK_NOT_ACTIVE');
            }
            $oldPct = round((float) $wo->ppn_pct, 2);
            $newPct = round((float) $data['new_ppn_pct'], 2);
            if (abs($newPct - $oldPct) < 0.009) {
                throw new BusinessRuleException('Persentase PPN tidak berubah.', errorCode: 'SPK_PPN_SAME');
            }

            $add = BsWorkOrderAddendum::create([
                'work_order_id'      => $wo->id,
                'addendum_date'      => $data['addendum_date'],
                'kind'               => 'ppn',
                'value_delta'        => 0,
                'old_contract_value' => (float) $wo->contract_value,
                'new_contract_value' => (float) $wo->contract_value,
                'old_ppn_pct'        => $oldPct,
                'new_ppn_pct'        => $newPct,
                'note'               => $data['note'] ?? null,
                'created_by'         => $userId,
            ]);

            $wo->update(['ppn_pct' => $newPct]);

            return $add;
        });
    }

    /**
     * Pembatalan SPK. Hanya bila tak ada termin belum-bayar & retensi tertahan.
     *
     * @param array{cancel_date:string, reason?:?string} $data
     */
    public function cancel(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrder
    {
        return DB::transaction(function () use ($wo, $data): BsWorkOrder {
            if (in_array($wo->status, ['cancelled', 'closed'], true)) {
                throw new BusinessRuleException('SPK sudah '.($wo->status === 'closed' ? 'ditutup' : 'dibatalkan').'.', errorCode: 'SPK_FINAL');
            }
            if ($wo->progress()->where('status', 'unpaid')->exists()) {
                throw new BusinessRuleException('Masih ada termin progress yang belum dibayar. Selesaikan dulu sebelum membatalkan.', errorCode: 'SPK_HAS_UNPAID');
            }
            if (round((float) $wo->retention_held, 2) > 0.009) {
                throw new BusinessRuleException('Masih ada retensi tertahan. Cairkan retensi dulu.', errorCode: 'SPK_HAS_RETENTION');
            }

            $wo->update([
                'status'        => 'cancelled',
                'cancelled_at'  => $data['cancel_date'],
                'cancel_reason' => $data['reason'] ?? null,
            ]);

            return $wo;
        });
    }

    /**
     * Pemakaian Barang (material) ke proyek SPK.
     *   Dr Konstruksi/CIP (akun SPK) / Cr Persediaan (dipilih).
     *
     * @param array{usage_date:string, description:string, amount:float|int|string, inventory_account_id:int} $data
     */
    public function useMaterial(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderMaterial
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderMaterial {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('Pemakaian barang hanya untuk SPK aktif.', errorCode: 'SPK_NOT_ACTIVE');
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0.009) {
                throw new BusinessRuleException('Nilai pemakaian harus lebih dari nol.', errorCode: 'SPK_MAT_ZERO');
            }
            $invAcc = (int) $data['inventory_account_id'];

            $journal = $this->journal->post([
                'journal_date'   => $data['usage_date'],
                'source'         => 'memorial',
                'reference'      => $wo->spk_number,
                'description'    => 'Pemakaian barang SPK '.$wo->spk_number.' — '.$data['description'],
                'cost_center_id' => $wo->cost_center_id,
                'created_by'     => $userId,
            ], [
                ['account_id' => $wo->construction_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Pemakaian material '.$data['description']],
                ['account_id' => $invAcc, 'debit' => 0, 'credit' => $amount, 'description' => 'Persediaan keluar'],
            ]);

            $material = $wo->materials()->create([
                'usage_date'           => $data['usage_date'],
                'description'          => $data['description'],
                'amount'               => $amount,
                'inventory_account_id' => $invAcc,
                'journal_id'           => $journal->id,
                'created_by'           => $userId,
            ]);

            $wo->update(['material_used' => round((float) $wo->material_used + $amount, 2)]);

            return $material;
        });
    }

    /**
     * Mutasi Progress — reklasifikasi CIP yang sudah ter-progress ke akun/cost-center lain.
     *   Dr CIP-tujuan (cost-center baru) / Cr CIP-asal (cost-center SPK).
     *
     * @param array{mutation_date:string, amount:float|int|string, to_account_id:int, to_cost_center_id?:?int, note?:?string} $data
     */
    public function mutateProgress(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderProgressMutation
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderProgressMutation {
            if ($wo->status !== 'active') {
                throw new BusinessRuleException('Mutasi progress hanya untuk SPK aktif.', errorCode: 'SPK_NOT_ACTIVE');
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0.009) {
                throw new BusinessRuleException('Nilai mutasi harus lebih dari nol.', errorCode: 'SPK_MUT_ZERO');
            }
            $available = round((float) $wo->progress_value - (float) $wo->progress_mutated, 2);
            if ($amount > $available + 0.009) {
                throw new BusinessRuleException('Nilai mutasi melebihi progress yang belum dimutasi (Rp '.number_format($available, 0, ',', '.').').', errorCode: 'SPK_MUT_OVER');
            }

            $toAccount = (int) $data['to_account_id'];
            $toCc      = $data['to_cost_center_id'] ?? null;
            if ($toAccount === (int) $wo->construction_account_id && (int) $toCc === (int) $wo->cost_center_id) {
                throw new BusinessRuleException('Akun & cost-center tujuan sama dengan asal — tidak ada yang dimutasi.', errorCode: 'SPK_MUT_SAME');
            }

            $journal = $this->journal->post([
                'journal_date' => $data['mutation_date'],
                'source'       => 'memorial',
                'reference'    => $wo->spk_number,
                'description'  => 'Mutasi progress SPK '.$wo->spk_number.(($data['note'] ?? '') !== '' ? ' — '.$data['note'] : ''),
                'created_by'   => $userId,
            ], [
                ['account_id' => $toAccount, 'debit' => $amount, 'credit' => 0, 'cost_center_id' => $toCc, 'description' => 'Mutasi masuk CIP'],
                ['account_id' => $wo->construction_account_id, 'debit' => 0, 'credit' => $amount, 'cost_center_id' => $wo->cost_center_id, 'description' => 'Mutasi keluar CIP'],
            ]);

            $mutation = $wo->progressMutations()->create([
                'mutation_date'       => $data['mutation_date'],
                'amount'              => $amount,
                'from_account_id'     => $wo->construction_account_id,
                'from_cost_center_id' => $wo->cost_center_id,
                'to_account_id'       => $toAccount,
                'to_cost_center_id'   => $toCc,
                'journal_id'          => $journal->id,
                'note'                => $data['note'] ?? null,
                'status'              => 'approved',
                'created_by'          => $userId,
            ]);

            $wo->update(['progress_mutated' => round((float) $wo->progress_mutated + $amount, 2)]);

            return $mutation;
        });
    }

    /**
     * Registrasi Mutasi Progress Bangunan — buat usulan mutasi (status=baru, BELUM ada jurnal).
     * Jurnal & penambahan progress_mutated baru terjadi saat Approval.
     *
     * @param array{mutation_date:string, amount:float|int|string, to_account_id:int, to_cost_center_id?:?int, note?:?string} $data
     */
    public function registerMutation(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderProgressMutation
    {
        if ($wo->status !== 'active') {
            throw new BusinessRuleException('Mutasi progress hanya untuk SPK aktif.', errorCode: 'SPK_NOT_ACTIVE');
        }
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0.009) {
            throw new BusinessRuleException('Nilai mutasi harus lebih dari nol.', errorCode: 'SPK_MUT_ZERO');
        }
        $available = round((float) $wo->progress_value - (float) $wo->progress_mutated, 2);
        if ($amount > $available + 0.009) {
            throw new BusinessRuleException('Nilai mutasi melebihi progress yang belum dimutasi (Rp '.number_format($available, 0, ',', '.').').', errorCode: 'SPK_MUT_OVER');
        }

        return $wo->progressMutations()->create([
            'mutation_date'       => $data['mutation_date'],
            'amount'              => $amount,
            'from_account_id'     => $wo->construction_account_id,
            'from_cost_center_id' => $wo->cost_center_id,
            'to_account_id'       => (int) $data['to_account_id'],
            'to_cost_center_id'   => $data['to_cost_center_id'] ?? null,
            'note'                => $data['note'] ?? null,
            'status'              => 'baru',
            'created_by'          => $userId,
        ]);
    }

    /** Approval Mutasi Progress Bangunan — posting jurnal reklas CIP & tambah progress_mutated. */
    public function approveMutation(BsWorkOrderProgressMutation $m, ?int $userId = null): void
    {
        DB::transaction(function () use ($m, $userId): void {
            if ($m->status !== 'baru') {
                throw new BusinessRuleException('Mutasi sudah diproses.', errorCode: 'MUT_DONE');
            }
            $wo     = $m->workOrder;
            $amount = round((float) $m->amount, 2);

            $journal = $this->journal->post([
                'journal_date' => $m->mutation_date->toDateString(),
                'source'       => 'memorial',
                'reference'    => $wo->spk_number,
                'description'  => 'Mutasi progress SPK '.$wo->spk_number.(($m->note ?? '') !== '' ? ' — '.$m->note : ''),
                'created_by'   => $userId,
            ], [
                ['account_id' => $m->to_account_id, 'debit' => $amount, 'credit' => 0, 'cost_center_id' => $m->to_cost_center_id, 'description' => 'Mutasi masuk CIP'],
                ['account_id' => $m->from_account_id, 'debit' => 0, 'credit' => $amount, 'cost_center_id' => $m->from_cost_center_id, 'description' => 'Mutasi keluar CIP'],
            ]);

            $m->update(['status' => 'approved', 'journal_id' => $journal->id, 'approved_at' => now(), 'approved_by' => $userId]);
            $wo->update(['progress_mutated' => round((float) $wo->progress_mutated + $amount, 2)]);
        });
    }

    public function rejectMutation(BsWorkOrderProgressMutation $m, ?int $userId = null): void
    {
        if ($m->status !== 'baru') {
            throw new BusinessRuleException('Mutasi sudah diproses.', errorCode: 'MUT_DONE');
        }
        $m->update(['status' => 'rejected', 'approved_at' => now(), 'approved_by' => $userId]);
    }

    /** Close SBO — tutup seluruh pemakaian barang (bon) yang masih open untuk satu SPK. */
    public function closeSbo(BsWorkOrder $wo, string $date): int
    {
        return BsWorkOrderMaterial::where('work_order_id', $wo->id)->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => $date]);
    }

    /**
     * Pengembalian Sisa Pemakaian — retur material ke gudang: Dr Persediaan / Cr CIP.
     *
     * @param array{return_date:string, amount:float|int|string, inventory_account_id:int, description?:?string, material_id?:?int} $data
     */
    public function returnMaterial(BsWorkOrder $wo, array $data, ?int $userId = null): BsWorkOrderMaterialReturn
    {
        return DB::transaction(function () use ($wo, $data, $userId): BsWorkOrderMaterialReturn {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0.009) {
                throw new BusinessRuleException('Nilai pengembalian harus lebih dari nol.', errorCode: 'SBO_RET_ZERO');
            }
            $netUsed = round((float) $wo->material_used, 2);
            if ($amount > $netUsed + 0.009) {
                throw new BusinessRuleException('Pengembalian melebihi pemakaian barang (Rp '.number_format($netUsed, 0, ',', '.').').', errorCode: 'SBO_RET_OVER');
            }
            $invAcc = (int) $data['inventory_account_id'];

            $journal = $this->journal->post([
                'journal_date'   => $data['return_date'],
                'source'         => 'memorial',
                'reference'      => $wo->spk_number,
                'description'    => 'Pengembalian sisa pemakaian SPK '.$wo->spk_number.(($data['description'] ?? '') !== '' ? ' — '.$data['description'] : ''),
                'cost_center_id' => $wo->cost_center_id,
                'created_by'     => $userId,
            ], [
                ['account_id' => $invAcc, 'debit' => $amount, 'credit' => 0, 'description' => 'Persediaan masuk (retur)'],
                ['account_id' => $wo->construction_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Koreksi CIP retur material'],
            ]);

            $ret = $wo->materialReturns()->create([
                'material_id'          => $data['material_id'] ?? null,
                'return_date'          => $data['return_date'],
                'description'          => $data['description'] ?? null,
                'amount'               => $amount,
                'inventory_account_id' => $invAcc,
                'journal_id'           => $journal->id,
                'created_by'           => $userId,
            ]);

            $wo->update(['material_used' => round((float) $wo->material_used - $amount, 2)]);
            if ($data['material_id'] ?? null) {
                $mat = BsWorkOrderMaterial::find($data['material_id']);
                if ($mat) {
                    $mat->update(['returned_amount' => round((float) $mat->returned_amount + $amount, 2)]);
                }
            }

            return $ret;
        });
    }

    private function nextNumber(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsWorkOrder::withTrashed()->whereYear('spk_date', $d->year)->count() + 1;

        return sprintf('SPK/%s/%04d', $d->format('y').$d->format('m'), $count);
    }

    private function nextKplNumber(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsWorkOrderProgress::whereYear('progress_date', $d->year)->count() + 1;

        return sprintf('PNP.KPL/MUP/%s/%05d', $d->format('y'), $count);
    }
}
