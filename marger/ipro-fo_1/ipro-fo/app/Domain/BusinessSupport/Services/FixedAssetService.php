<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsDepreciationEntry;
use App\Domain\BusinessSupport\Models\BsFixedAsset;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aset Tetap + Penyusutan garis lurus.
 *   Perolehan (opsional) : Dr Aset Tetap / Cr Kas-Bank/Hutang
 *   Penyusutan bulanan   : Dr Beban Penyusutan / Cr Akumulasi Penyusutan
 */
final class FixedAssetService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{name:string, category:string, acquisition_date:string, acquisition_cost:float|int|string,
     *              residual_value?:float|int|string, useful_life_months:int, funding_account_id?:?int} $data
     */
    public function register(array $data, ?int $userId = null): BsFixedAsset
    {
        return DB::transaction(function () use ($data, $userId): BsFixedAsset {
            $map = config("business_support.fixed_asset.{$data['category']}");
            if (! $map) {
                throw new BusinessRuleException('Kategori aset tidak dikenal.', errorCode: 'FA_CATEGORY');
            }
            $accId = fn (?string $code) => $code ? BsAccount::where('code', $code)->value('id') : null;
            $cost  = round((float) $data['acquisition_cost'], 2);

            $asset = BsFixedAsset::create([
                'asset_number'       => $this->nextNumber($data['acquisition_date']),
                'name'               => $data['name'],
                'quantity'           => $data['quantity'] ?? null,
                'category'           => $data['category'],
                'acquisition_date'   => $data['acquisition_date'],
                'acquisition_cost'   => $cost,
                'residual_value'     => round((float) ($data['residual_value'] ?? 0), 2),
                'useful_life_months' => (int) ($data['useful_life_months'] ?? 0),
                'method'             => 'straight_line',
                'asset_account_id'   => $accId($map['asset']),
                'accum_account_id'   => $accId($map['accum']),
                'expense_account_id' => $accId($map['expense']),
                'status'             => 'active',
                'pic'                => $data['pic'] ?? null,
                'location'           => $data['location'] ?? null,
                'created_by'         => $userId,
            ]);

            // Jurnal perolehan (opsional): Dr Aset / Cr sumber dana.
            if (! empty($data['funding_account_id']) && $cost > 0) {
                $journal = $this->journal->post([
                    'journal_date' => $asset->acquisition_date->toDateString(),
                    'source'       => 'memorial',
                    'reference'    => $asset->asset_number,
                    'description'  => 'Perolehan aset tetap — '.$asset->name,
                    'created_by'   => $userId,
                ], [
                    ['account_id' => $asset->asset_account_id, 'debit' => $cost, 'credit' => 0, 'description' => $asset->name],
                    ['account_id' => (int) $data['funding_account_id'], 'debit' => 0, 'credit' => $cost, 'description' => 'Sumber dana perolehan'],
                ]);
                $asset->update(['acquire_journal_id' => $journal->id]);
            }

            return $asset;
        });
    }

    /** Posting penyusutan satu aset untuk satu periode. Mengembalikan entry atau null bila dilewati. */
    public function depreciate(BsFixedAsset $asset, int $year, int $month, ?int $userId = null): ?BsDepreciationEntry
    {
        return DB::transaction(function () use ($asset, $year, $month, $userId): ?BsDepreciationEntry {
            if ($asset->status !== 'active' || $asset->category === 'tanah') {
                return null;
            }
            // Akun beban/akumulasi wajib ada agar jurnal tidak rusak.
            if (! $asset->expense_account_id || ! $asset->accum_account_id) {
                throw new BusinessRuleException('Akun beban/akumulasi penyusutan belum diset untuk aset ini.', errorCode: 'FA_NO_ACCOUNT');
            }
            // Belum boleh menyusut sebelum tanggal perolehan.
            $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();
            if ($asset->acquisition_date->gt($periodEnd)) {
                return null;
            }
            // Sudah disusut untuk periode ini?
            if (BsDepreciationEntry::where('fixed_asset_id', $asset->id)->where('period_year', $year)->where('period_month', $month)->exists()) {
                return null;
            }

            $remaining = round($asset->depreciable_base - (float) $asset->accumulated_depreciation, 2);
            if ($remaining <= 0.009) {
                $asset->update(['status' => 'fully_depreciated']);
                return null;
            }
            $amount = min($asset->monthly_depreciation, $remaining);
            if ($amount <= 0.009) {
                return null;
            }

            $journal = $this->journal->post([
                'journal_date' => $periodEnd->toDateString(),
                'source'       => 'memorial',
                'reference'    => $asset->asset_number,
                'description'  => "Penyusutan {$asset->name} {$year}/{$month}",
                'created_by'   => $userId,
            ], [
                ['account_id' => $asset->expense_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Beban penyusutan'],
                ['account_id' => $asset->accum_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Akumulasi penyusutan'],
            ]);

            $newAccum = round((float) $asset->accumulated_depreciation + $amount, 2);
            $entry = $asset->entries()->create([
                'period_year' => $year, 'period_month' => $month, 'entry_date' => $periodEnd->toDateString(),
                'amount' => $amount, 'book_value_after' => round((float) $asset->acquisition_cost - $newAccum, 2),
                'journal_id' => $journal->id,
            ]);

            $asset->update([
                'accumulated_depreciation' => $newAccum,
                'last_depreciation_date'   => $periodEnd->toDateString(),
                'status'                   => $newAccum >= $asset->depreciable_base - 0.009 ? 'fully_depreciated' : 'active',
            ]);

            return $entry;
        });
    }

    /** Jalankan penyusutan seluruh aset aktif untuk satu periode. @return array{posted:int, total:float, skipped:int} */
    public function runBatch(int $year, int $month, ?int $userId = null): array
    {
        $posted = 0; $total = 0.0; $skipped = 0;
        BsFixedAsset::where('status', 'active')->where('category', '!=', 'tanah')->get()->each(function ($asset) use ($year, $month, $userId, &$posted, &$total, &$skipped): void {
            $entry = $this->depreciate($asset, $year, $month, $userId);
            if ($entry) { $posted++; $total += (float) $entry->amount; } else { $skipped++; }
        });

        return ['posted' => $posted, 'total' => round($total, 2), 'skipped' => $skipped];
    }

    /**
     * Penghapusan aset (write-off, tanpa hasil jual).
     *   Dr Akumulasi Penyusutan + Dr Rugi Penghapusan / Cr Aset Tetap.
     *
     * @param array{disposed_date:string, note?:?string} $data
     */
    public function dispose(BsFixedAsset $asset, array $data, ?int $userId = null): BsFixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $userId): BsFixedAsset {
            $this->guardDisposable($asset);
            $cost  = round((float) $asset->acquisition_cost, 2);
            $accum = round((float) $asset->accumulated_depreciation, 2);
            $book  = round($cost - $accum, 2);
            $date  = $data['disposed_date'];

            $lines = [];
            if ($accum > 0.009 && $asset->accum_account_id) {
                $lines[] = ['account_id' => $asset->accum_account_id, 'debit' => $accum, 'credit' => 0, 'description' => 'Akumulasi penyusutan dihapus'];
            }
            if ($book > 0.009) {
                $lossId = $this->accId(config('business_support.fixed_asset_disposal.loss'));
                if (! $lossId) {
                    throw new BusinessRuleException('Akun rugi penghapusan aset belum tersedia.', errorCode: 'FA_NO_LOSS_ACCOUNT');
                }
                $lines[] = ['account_id' => $lossId, 'debit' => $book, 'credit' => 0, 'description' => 'Rugi penghapusan aset'];
            }
            $lines[] = ['account_id' => $asset->asset_account_id, 'debit' => 0, 'credit' => $cost, 'description' => 'Aset tetap dihapus'];

            $note = trim((string) ($data['note'] ?? ''));
            $journal = $this->journal->post([
                'journal_date' => $date,
                'source'       => 'memorial',
                'reference'    => $asset->asset_number,
                'description'  => 'Penghapusan aset — '.$asset->name.($note !== '' ? ' ('.$note.')' : ''),
                'created_by'   => $userId,
            ], $lines);

            $asset->update([
                'status' => 'disposed', 'disposed_date' => $date, 'disposal_type' => 'write_off',
                'disposal_proceeds' => 0, 'disposal_note' => $note ?: null, 'disposal_journal_id' => $journal->id,
            ]);

            return $asset->refresh();
        });
    }

    /**
     * Penjualan aset. Selisih hasil jual vs nilai buku → laba/rugi.
     *   Dr Kas/Bank + Dr Akumulasi Penyusutan / Cr Aset Tetap + (Cr Laba | Dr Rugi).
     *
     * @param array{disposed_date:string, proceeds:float|int|string, cash_account_id:int, note?:?string} $data
     */
    public function sell(BsFixedAsset $asset, array $data, ?int $userId = null): BsFixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $userId): BsFixedAsset {
            $this->guardDisposable($asset);
            $cost     = round((float) $asset->acquisition_cost, 2);
            $accum    = round((float) $asset->accumulated_depreciation, 2);
            $book     = round($cost - $accum, 2);
            $proceeds = round((float) $data['proceeds'], 2);
            if ($proceeds <= 0.009) {
                throw new BusinessRuleException('Harga jual harus lebih dari nol. Untuk pelepasan tanpa hasil gunakan Penghapusan.', errorCode: 'FA_NO_PROCEEDS');
            }
            $gain = round($proceeds - $book, 2);
            $date = $data['disposed_date'];

            $lines = [];
            $lines[] = ['account_id' => (int) $data['cash_account_id'], 'debit' => $proceeds, 'credit' => 0, 'description' => 'Hasil penjualan aset'];
            if ($accum > 0.009 && $asset->accum_account_id) {
                $lines[] = ['account_id' => $asset->accum_account_id, 'debit' => $accum, 'credit' => 0, 'description' => 'Akumulasi penyusutan dihapus'];
            }
            $lines[] = ['account_id' => $asset->asset_account_id, 'debit' => 0, 'credit' => $cost, 'description' => 'Aset tetap dilepas'];
            if ($gain > 0.009) {
                $gainId = $this->accId(config('business_support.fixed_asset_disposal.gain'));
                if (! $gainId) {
                    throw new BusinessRuleException('Akun laba penjualan aset belum tersedia.', errorCode: 'FA_NO_GAIN_ACCOUNT');
                }
                $lines[] = ['account_id' => $gainId, 'debit' => 0, 'credit' => $gain, 'description' => 'Laba penjualan aset'];
            } elseif ($gain < -0.009) {
                $lossId = $this->accId(config('business_support.fixed_asset_disposal.loss'));
                if (! $lossId) {
                    throw new BusinessRuleException('Akun rugi penjualan aset belum tersedia.', errorCode: 'FA_NO_LOSS_ACCOUNT');
                }
                $lines[] = ['account_id' => $lossId, 'debit' => abs($gain), 'credit' => 0, 'description' => 'Rugi penjualan aset'];
            }

            $note = trim((string) ($data['note'] ?? ''));
            $journal = $this->journal->post([
                'journal_date' => $date,
                'source'       => 'memorial',
                'reference'    => $asset->asset_number,
                'description'  => 'Penjualan aset — '.$asset->name.($note !== '' ? ' ('.$note.')' : ''),
                'created_by'   => $userId,
            ], $lines);

            $asset->update([
                'status' => 'sold', 'disposed_date' => $date, 'disposal_type' => 'sale',
                'disposal_proceeds' => $proceeds, 'disposal_note' => $note ?: null, 'disposal_journal_id' => $journal->id,
            ]);

            return $asset->refresh();
        });
    }

    /**
     * Saldo awal akumulasi penyusutan (migrasi aset yang sudah berjalan).
     *   Dr Laba Ditahan / Cr Akumulasi Penyusutan. Hanya bila belum ada entry penyusutan.
     *
     * @param array{as_of_date:string, amount:float|int|string} $data
     */
    public function openingDepreciation(BsFixedAsset $asset, array $data, ?int $userId = null): BsFixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $userId): BsFixedAsset {
            if (in_array($asset->status, ['disposed', 'sold'], true)) {
                throw new BusinessRuleException('Aset sudah dilepas/dijual.', errorCode: 'FA_DISPOSED');
            }
            if ($asset->entries()->exists()) {
                throw new BusinessRuleException('Saldo awal hanya untuk aset yang belum pernah disusutkan di sistem.', errorCode: 'FA_HAS_ENTRIES');
            }
            if (! $asset->accum_account_id) {
                throw new BusinessRuleException('Aset ini tidak memiliki akun akumulasi penyusutan (mis. tanah).', errorCode: 'FA_NO_ACCUM');
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0.009) {
                throw new BusinessRuleException('Nilai saldo awal penyusutan harus lebih dari nol.', errorCode: 'FA_AMOUNT');
            }
            if ($amount > $asset->depreciable_base + 0.009) {
                throw new BusinessRuleException('Saldo awal melebihi dasar penyusutan (harga − residu).', errorCode: 'FA_OVER');
            }
            $date   = $data['as_of_date'];
            $offset = $this->accId(config('business_support.fixed_asset_disposal.opening_offset'));
            if (! $offset) {
                throw new BusinessRuleException('Akun lawan saldo awal (laba ditahan) belum tersedia.', errorCode: 'FA_NO_OFFSET');
            }

            $journal = $this->journal->post([
                'journal_date' => $date,
                'source'       => 'memorial',
                'reference'    => $asset->asset_number,
                'description'  => 'Saldo awal akumulasi penyusutan — '.$asset->name,
                'created_by'   => $userId,
            ], [
                ['account_id' => $offset, 'debit' => $amount, 'credit' => 0, 'description' => 'Saldo awal (laba ditahan)'],
                ['account_id' => $asset->accum_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Akumulasi penyusutan awal'],
            ]);

            $asset->update([
                'accumulated_depreciation' => $amount,
                'last_depreciation_date'   => $date,
                'status'                   => $amount >= $asset->depreciable_base - 0.009 ? 'fully_depreciated' : 'active',
            ]);
            // Jejak audit: catat sebagai entry "saldo awal".
            $asset->entries()->create([
                'period_year'      => (int) Carbon::parse($date)->year,
                'period_month'     => (int) Carbon::parse($date)->month,
                'entry_date'       => $date,
                'amount'           => $amount,
                'book_value_after' => round((float) $asset->acquisition_cost - $amount, 2),
                'journal_id'       => $journal->id,
            ]);

            return $asset->refresh();
        });
    }

    private function guardDisposable(BsFixedAsset $asset): void
    {
        if (in_array($asset->status, ['disposed', 'sold'], true)) {
            throw new BusinessRuleException('Aset sudah dilepas/dijual sebelumnya.', errorCode: 'FA_ALREADY_DISPOSED');
        }
        if (! $asset->asset_account_id) {
            throw new BusinessRuleException('Akun aset belum diset untuk aset ini.', errorCode: 'FA_NO_ASSET_ACCOUNT');
        }
    }

    private function accId(?string $code): ?int
    {
        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }

    private function nextNumber(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsFixedAsset::withTrashed()->whereYear('acquisition_date', $d->year)->count() + 1;

        return sprintf('FA/%s/%04d', $d->format('y').$d->format('m'), $count);
    }
}
