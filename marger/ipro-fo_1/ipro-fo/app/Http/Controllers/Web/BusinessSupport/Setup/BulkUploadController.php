<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsStockMovement;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Domain\BusinessSupport\Services\FixedAssetService;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\BusinessSupport\Services\StockService;
use App\Http\Controllers\Controller;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Upload Massal (BO BudgetUpload2 / AssetUpload / opening stok).
 *   asset  → registrasi aset tetap massal
 *   budget → alokasi anggaran massal
 *   stock  → saldo awal stok (Dr Persediaan / Cr Laba Ditahan)
 */
class BulkUploadController extends Controller
{
    /** mode => [judul, header kolom] */
    private const MODES = [
        'asset'  => ['Upload Aset Tetap', 'name,category,acquisition_date,acquisition_cost,residual_value,useful_life_months'],
        'budget' => ['Upload Anggaran', 'year,cost_center_code,account_code,amount'],
        'stock'  => ['Saldo Awal Stok', 'item_code,warehouse_code,qty,unit_price,date'],
    ];

    public function form(Request $request, string $mode): Response
    {
        abort_unless(isset(self::MODES[$mode]), 404);
        abort_unless($request->user()?->can('businesssupport.transaction.create'), 403);

        return Inertia::render('BusinessSupport/Setup/BulkUpload', [
            'mode'     => $mode,
            'title'    => self::MODES[$mode][0],
            'template' => self::MODES[$mode][1],
            'modes'    => collect(self::MODES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m[0]])->values(),
            'note'     => match ($mode) {
                'asset'  => 'category ∈ bangunan|kendaraan|inventaris|tanah. Aset terdaftar tanpa jurnal perolehan.',
                'budget' => 'cost_center_code boleh kosong (= semua). Anggaran dibuat status draft.',
                'stock'  => 'Membuat saldo awal stok + jurnal Dr Persediaan / Cr Laba Ditahan (total).',
                default  => '',
            },
        ]);
    }

    public function store(Request $request, string $mode, JournalService $journal, FixedAssetService $fa, StockService $stock): RedirectResponse
    {
        abort_unless(isset(self::MODES[$mode]), 404);
        abort_unless($request->user()?->can('businesssupport.transaction.create'), 403);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx'], 'dry_run' => ['nullable', 'boolean']], [
            'file.mimes' => 'Berkas harus Excel (.xlsx).',
        ]);
        $dry    = $request->boolean('dry_run');
        $userId = $request->user()->id;

        try {
            $rows = Xlsx::baca($request->file('file')->getRealPath());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        // Baris yang seluruh selnya kosong dibuang — dulu FILE_SKIP_EMPTY_LINES melakukannya untuk CSV.
        $rows = array_values(array_filter($rows, fn ($r) => implode('', array_map('trim', $r)) !== ''));
        if (count($rows) < 2) {
            return back()->with('error', 'Berkas kosong atau tanpa baris data.');
        }
        $header  = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows));
        $records = array_map(fn ($r) => array_combine($header, array_pad(array_slice($r, 0, count($header)), count($header), null)), $rows);

        // Tanggal yang diketik di Excel tersimpan sebagai angka serial (45930), bukan teks
        // seperti di CSV — tanpa ini angka itu masuk mentah ke kolom tanggal.
        $records = array_map(function ($rec) {
            foreach (['acquisition_date', 'date'] as $k) {
                $s = trim((string) ($rec[$k] ?? ''));
                if (is_numeric($s) && (float) $s > 20000 && (float) $s < 90000) {
                    $rec[$k] = \Illuminate\Support\Carbon::create(1899, 12, 30)->addDays((int) $s)->toDateString();
                }
            }

            return $rec;
        }, $records);

        $errors = [];
        $ok = 0;
        $stockTotal = 0.0;

        DB::beginTransaction();
        try {
            foreach ($records as $idx => $rec) {
                $line = $idx + 2;
                try {
                    match ($mode) {
                        'asset'  => $this->rowAsset($rec, $fa, $userId),
                        'budget' => $this->rowBudget($rec, $userId),
                        'stock'  => $stockTotal += $this->rowStock($rec, $stock, $userId),
                    };
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "Baris {$line}: ".$e->getMessage();
                }
            }

            // Jurnal saldo awal stok (sekali, total) — hanya bila final & tak ada error.
            if ($mode === 'stock' && $stockTotal > 0.009 && ! $dry && ! $errors) {
                $inv = BsAccount::where('code', config('business_support.gl_accounts.inventory'))->value('id');
                $eq  = BsAccount::where('code', config('business_support.gl_accounts.retained_earnings'))->value('id');
                if ($inv && $eq) {
                    $journal->post([
                        'journal_date' => now()->toDateString(), 'source' => 'memorial', 'reference' => 'SALDO-AWAL-STOK',
                        'description' => 'Saldo awal stok (upload massal)', 'created_by' => $userId,
                    ], [
                        ['account_id' => $inv, 'debit' => round($stockTotal, 2), 'credit' => 0, 'description' => 'Persediaan awal'],
                        ['account_id' => $eq, 'debit' => 0, 'credit' => round($stockTotal, 2), 'description' => 'Saldo awal (laba ditahan)'],
                    ]);
                }
            }

            if ($dry || $errors) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()->with('error', 'Gagal memproses: '.$e->getMessage());
        }

        $msg = ($dry ? '[Dry-run] ' : '')."{$ok} baris valid".($errors ? ', '.count($errors).' baris error' : '').'.';
        if ($errors) {
            return back()->with('error', $msg.' — '.implode(' | ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : ''));
        }

        return back()->with('success', $dry ? $msg.' Hapus centang dry-run untuk menyimpan.' : $msg.' Tersimpan.');
    }

    private function rowAsset(array $rec, FixedAssetService $fa, int $userId): void
    {
        $cat = strtolower(trim((string) ($rec['category'] ?? '')));
        if (! in_array($cat, ['bangunan', 'kendaraan', 'inventaris', 'tanah'], true)) {
            throw new \RuntimeException("kategori '{$cat}' tidak valid");
        }
        $fa->register([
            'name'               => trim((string) ($rec['name'] ?? '')) ?: throw new \RuntimeException('name kosong'),
            'category'           => $cat,
            'acquisition_date'   => $rec['acquisition_date'] ?: throw new \RuntimeException('acquisition_date kosong'),
            'acquisition_cost'   => (float) ($rec['acquisition_cost'] ?? 0),
            'residual_value'     => (float) ($rec['residual_value'] ?? 0),
            'useful_life_months' => (int) ($rec['useful_life_months'] ?? 0),
        ], $userId);
    }

    private function rowBudget(array $rec, int $userId): void
    {
        $acc = BsAccount::where('code', trim((string) ($rec['account_code'] ?? '')))->value('id');
        if (! $acc) {
            throw new \RuntimeException("akun '{$rec['account_code']}' tidak ditemukan");
        }
        $ccId = null;
        if (! empty($rec['cost_center_code'])) {
            $ccId = BsCostCenter::where('code', trim((string) $rec['cost_center_code']))->value('id');
            if (! $ccId) {
                throw new \RuntimeException("cost center '{$rec['cost_center_code']}' tidak ditemukan");
            }
        }
        BsBudget::updateOrCreate(
            ['tenant_id' => tenant_id(), 'year' => (int) ($rec['year'] ?? 0), 'cost_center_id' => $ccId, 'account_id' => $acc],
            ['amount' => (float) ($rec['amount'] ?? 0), 'status' => 'draft', 'created_by' => $userId],
        );
    }

    private function rowStock(array $rec, StockService $stock, int $userId): float
    {
        $item = BsItem::where('code', trim((string) ($rec['item_code'] ?? '')))->first();
        if (! $item) {
            throw new \RuntimeException("item '{$rec['item_code']}' tidak ditemukan");
        }
        $wh = BsWarehouse::where('code', trim((string) ($rec['warehouse_code'] ?? '')))->first();
        if (! $wh) {
            throw new \RuntimeException("gudang '{$rec['warehouse_code']}' tidak ditemukan");
        }
        $qty   = (float) ($rec['qty'] ?? 0);
        $price = (float) ($rec['unit_price'] ?? 0);
        if ($qty <= 0) {
            throw new \RuntimeException('qty harus > 0');
        }
        $bal = $stock->balance($item->id, $wh->id);
        BsStockMovement::create([
            'item_id' => $item->id, 'warehouse_id' => $wh->id,
            'moved_at' => $rec['date'] ?: now()->toDateString(), 'direction' => 'in',
            'qty' => $qty, 'unit_price' => $price, 'balance_after' => $bal + $qty,
            'ref_type' => 'opening', 'ref_id' => null, 'ref_number' => 'SALDO AWAL',
        ]);

        return round($qty * $price, 2);
    }
}
