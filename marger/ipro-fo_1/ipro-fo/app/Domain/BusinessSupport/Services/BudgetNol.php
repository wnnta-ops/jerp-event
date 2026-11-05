<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;

/**
 * Akun wajib-anggaran yang belum punya anggaran (akun × cost center) dianggap anggaran 0 di langkah
 * Struktur Registrasi Invoice Hutang. Layar memakai budget_id negatif (kunci()) untuk baris itu; saat
 * alokasi / koreksinya diajukan, Master Budget bernilai 0 dibuat (approved — nolnya tidak menambah
 * ruang apa pun) lalu id negatif diganti id budget itu. Alokasi / koreksi yang disetujui kemudian
 * mengisi bulannya, sama seperti budget biasa.
 */
class BudgetNol
{
    private const PENGALI = 1_000_000;

    public static function kunci(int $accountId, int $costCenterId): int
    {
        return -($accountId * self::PENGALI + $costCenterId);
    }

    /** @return array{0:int,1:int} [account_id, cost_center_id] */
    public static function urai(int $kunci): array
    {
        $n = abs($kunci);

        return [intdiv($n, self::PENGALI), $n % self::PENGALI];
    }

    /**
     * Ganti budget_id negatif pada koreksi / alokasi isian invoice dengan Master Budget nol yang dibuat
     * (atau yang sudah ada) untuk akun × cost center itu.
     *
     * @param  array<string,mixed>  $data  isian wizard (koreksi[], alokasi[], invoice_date)
     * @return array<string,mixed>
     */
    public function siapkan(array $data, ?int $userId = null): array
    {
        $tahun = Carbon::parse($data['invoice_date'] ?? now()->toDateString())->year;
        foreach (['koreksi', 'alokasi'] as $jenis) {
            foreach ((array) ($data[$jenis] ?? []) as $i => $item) {
                $id = (int) ($item['budget_id'] ?? 0);
                if ($id < 0) {
                    $data[$jenis][$i]['budget_id'] = $this->budget($id, (int) $tahun, $userId)->id;
                }
            }
        }

        return $data;
    }

    private function budget(int $kunci, int $tahun, ?int $userId): BsBudget
    {
        [$accountId, $cc] = self::urai($kunci);
        $ada = BsBudget::query()->where('year', $tahun)->where('account_id', $accountId)->where('cost_center_id', $cc)->first();
        if ($ada) {
            if ($ada->status !== 'approved') {
                throw new BusinessRuleException('Anggaran '.$ada->doc_no.' untuk akun ini masih menunggu Approval Budget — setujui dulu sebelum mengajukan alokasi / koreksi.', errorCode: 'BUDGET_BELUM_APPROVED');
            }

            return $ada;
        }

        return BsBudget::create([
            'tenant_id' => tenant_id(),
            'doc_no' => BsBudget::nextDocNo($tahun),
            'year' => $tahun, 'account_id' => $accountId, 'cost_center_id' => $cc,
            'amount' => 0, 'm1' => 0, 'm2' => 0, 'm3' => 0, 'm4' => 0, 'm5' => 0, 'm6' => 0,
            'm7' => 0, 'm8' => 0, 'm9' => 0, 'm10' => 0, 'm11' => 0, 'm12' => 0,
            'notes' => 'Dibuat otomatis (anggaran 0) — akun belum punya anggaran; diisi lewat Alokasi / Koreksi dari Invoice Hutang.',
            'status' => 'approved', 'created_by' => $userId, 'submitted_at' => now(), 'approved_at' => now(), 'approved_by' => $userId,
        ]);
    }
}
