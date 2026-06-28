<?php

declare(strict_types=1);

namespace App\Domain\Commission\Services;

use App\Domain\Commission\Models\Incentive;
use Illuminate\Support\Carbon;

/**
 * Lifecycle insentif: Pengajuan → Disetujui/Ditolak → Direalisasi.
 */
class IncentiveService
{
    private const PREFIX = ['blt' => 'BLT', 'reward' => 'RWD'];

    /** Buat pengajuan baru (status diajukan). */
    public function submit(array $data, int $userId): Incentive
    {
        return Incentive::create([
            ...$data,
            'incentive_no' => $this->nextNo($data['type']),
            'status'       => 'diajukan',
            'submitted_by' => $userId,
            'submitted_at' => Carbon::now(),
        ]);
    }

    public function approve(Incentive $incentive, int $userId): Incentive
    {
        abort_unless($incentive->status === 'diajukan', 422, 'Hanya pengajuan berstatus "diajukan" yang bisa disetujui.');
        $incentive->update(['status' => 'disetujui', 'approved_by' => $userId, 'approved_at' => Carbon::now(), 'reject_reason' => null]);

        return $incentive;
    }

    public function reject(Incentive $incentive, string $reason, int $userId): Incentive
    {
        abort_unless($incentive->status === 'diajukan', 422, 'Hanya pengajuan berstatus "diajukan" yang bisa ditolak.');
        $incentive->update(['status' => 'ditolak', 'approved_by' => $userId, 'approved_at' => Carbon::now(), 'reject_reason' => $reason]);

        return $incentive;
    }

    public function realize(Incentive $incentive, int $userId): Incentive
    {
        abort_unless($incentive->status === 'disetujui', 422, 'Hanya insentif yang sudah disetujui yang bisa direalisasi.');
        $incentive->update(['status' => 'direalisasi', 'realized_by' => $userId, 'realized_at' => Carbon::now()]);

        return $incentive;
    }

    public function nextNo(string $type): string
    {
        $prefix = self::PREFIX[$type] ?? 'INC';
        $d      = Carbon::now();
        $seq    = Incentive::withTrashed()->where('type', $type)->whereYear('created_at', $d->year)->count() + 1;
        do {
            $no = sprintf('%s/%s/%05d', $prefix, $d->format('y'), $seq);
            $seq++;
        } while (Incentive::withTrashed()->where('incentive_no', $no)->exists());

        return $no;
    }
}
