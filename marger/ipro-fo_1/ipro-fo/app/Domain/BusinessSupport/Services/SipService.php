<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsSip;
use App\Domain\BusinessSupport\Models\BsSipLine;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * #98 — SIP (Standing Instruction Payment). Membangun dokumen instruksi pembayaran dari
 * tagihan AP & bon sementara yang dipilih, di-snapshot tujuan & nominalnya.
 * CATATAN: pengajuan saja — TIDAK memposting jurnal. Pembayaran riil tetap lewat
 * Kuitansi Hutang (AP) / Bayar Bon (bon sementara).
 */
final class SipService
{
    public function create(array $data, ?int $userId = null): BsSip
    {
        return DB::transaction(function () use ($data, $userId): BsSip {
            $c = $this->composeLines($data);
            $nomor = $this->nextNumber($data['sip_date']);

            $sip = BsSip::create([
                'sip_number'      => $nomor['number'],
                // Urutan & tahunnya ikut disimpan: dua kolom inilah yang dijaga unique index,
                // dan dari sinilah SIP lama (yang nomornya masih format SIP/MUP/...) menyusun
                // nomor cetaknya. Dengan diisi sejak awal, nomor dokumen dan nomor cetak
                // TIDAK PERNAH lagi menjadi dua angka yang berbeda.
                'print_seq'       => $nomor['seq'],
                'print_year'      => $nomor['year'],
                'sip_date'        => $data['sip_date'],
                'title'           => $data['title'] ?? null,
                'cash_account_id' => (int) $data['cash_account_id'],
                'description'     => $data['description'] ?? null,
                'total'           => $c['total'],
                'status'          => 'submitted',
                'approvals'       => [],
                'created_by'      => $userId,
            ]);

            foreach ($c['rows'] as $r) {
                $sip->lines()->create($r);
            }

            return $sip;
        });
    }

    /** #124 — Edit SIP (oleh staf finance pembuat). Hapus & bangun ulang baris, reset persetujuan. */
    public function update(BsSip $sip, array $data): BsSip
    {
        return DB::transaction(function () use ($sip, $data): BsSip {
            $c = $this->composeLines($data, $sip->id);

            $sip->lines()->delete();
            foreach ($c['rows'] as $r) {
                $sip->lines()->create($r);
            }

            $sip->update([
                'sip_date'         => $data['sip_date'],
                'title'            => $data['title'] ?? null,
                'cash_account_id'  => (int) $data['cash_account_id'],
                'description'      => $data['description'] ?? null,
                'total'            => $c['total'],
                // Revisi → persetujuan dibuka lagi dari awal (status kembali Diajukan).
                'status'           => 'submitted',
                'approvals'        => [],
                'rejection_reason' => null,
                'rejected_by'      => null,
                'rejected_at'      => null,
            ]);

            return $sip->refresh();
        });
    }

    /**
     * Bangun baris SIP + total dari payload `lines` (dipakai create & update).
     * #174: VALIDASI SERVER — tolak tagihan/bon yang sudah ada di SIP lain yang aktif
     * (selain cancelled/rejected), agar tidak dobel-bayar walau form sudah basi/concurrent.
     * $exceptSipId mengecualikan SIP yang sedang diedit (barisnya sendiri tak dianggap "dipakai").
     */
    private function composeLines(array $data, ?int $exceptSipId = null): array
    {
        $total    = 0.0;
        $lineRows = [];

        // True bila source sudah dipakai di SIP lain yang AKTIF (bukan cancelled/rejected).
        $takenElsewhere = fn (string $type, int $id) => BsSipLine::where('sourceable_type', $type)
            ->where('sourceable_id', $id)
            ->whereHas('sip', function ($q) use ($exceptSipId): void {
                $q->whereNotIn('status', ['cancelled', 'rejected']);
                if ($exceptSipId) {
                    $q->where('id', '!=', $exceptSipId);
                }
            })->exists();

        foreach ($data['lines'] as $l) {
            $amount = round((float) ($l['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            // #114: bank sumber per baris (fallback ke header untuk kompatibilitas data lama).
            $lineBank = (int) ($l['cash_account_id'] ?? $data['cash_account_id'] ?? 0);

            if (($l['source_type'] ?? '') === 'ap') {
                $inv = BsApInvoice::with('supplier')->findOrFail((int) $l['source_id']);
                if ($takenElsewhere(BsApInvoice::class, $inv->id)) {
                    throw new BusinessRuleException('Tagihan '.$inv->invoice_number.' sudah masuk SIP lain — muat ulang halaman & pilih ulang.', errorCode: 'SIP_AP_TAKEN');
                }
                if ($amount > (float) $inv->outstanding + 0.01) {
                    throw new BusinessRuleException('Nominal melebihi sisa hutang '.$inv->invoice_number.'.', errorCode: 'SIP_OVER');
                }
                $lineRows[] = [
                    'cash_account_id'   => $lineBank,
                    'sourceable_type'   => BsApInvoice::class,
                    'sourceable_id'     => $inv->id,
                    'payee_type'        => 'supplier',
                    'payee_name'        => $inv->supplier?->name ?? '—',
                    'dest_bank_name'    => $l['dest_bank_name']    ?? $inv->supplier?->bank_name,
                    'dest_account_no'   => $l['dest_account_no']   ?? $inv->supplier?->bank_account_no,
                    'dest_account_name' => $l['dest_account_name'] ?? $inv->supplier?->bank_account_name,
                    'description'       => $l['description'] ?? $inv->description,
                    'amount'            => $amount,
                ];
            } else {
                $adv = BsCashAdvance::findOrFail((int) $l['source_id']);
                if ($takenElsewhere(BsCashAdvance::class, $adv->id)) {
                    throw new BusinessRuleException('Bon '.$adv->advance_number.' sudah masuk SIP lain — muat ulang halaman & pilih ulang.', errorCode: 'SIP_ADV_TAKEN');
                }
                $lineRows[] = [
                    'cash_account_id'   => $lineBank,
                    'sourceable_type'   => BsCashAdvance::class,
                    'sourceable_id'     => $adv->id,
                    'payee_type'        => 'employee',
                    'payee_name'        => $adv->holder,
                    'dest_bank_name'    => $l['dest_bank_name']    ?? null,
                    'dest_account_no'   => $l['dest_account_no']   ?? null,
                    'dest_account_name' => $l['dest_account_name'] ?? null,
                    'description'       => $l['description'] ?? $adv->purpose,
                    'amount'            => $amount,
                ];
            }
            $total += $amount;
        }

        if ($total <= 0) {
            throw new BusinessRuleException('Tidak ada tagihan/bon yang dipilih.', errorCode: 'SIP_EMPTY');
        }

        return ['rows' => $lineRows, 'total' => round($total, 2)];
    }

    /** Stempel satu slot approval (TTD) untuk user login. */
    public function approve(BsSip $sip, string $slot, int $userId, string $name): BsSip
    {
        if ($sip->status === 'cancelled') {
            throw new BusinessRuleException('SIP sudah dibatalkan.', errorCode: 'SIP_CANCELLED');
        }
        if ($sip->status === 'rejected') {
            throw new BusinessRuleException('SIP ditolak — finance harus membuat SIP baru (item sudah kembali ke daftar).', errorCode: 'SIP_REJECTED');
        }
        if (! array_key_exists($slot, BsSip::SIGNERS)) {
            throw new BusinessRuleException('Slot persetujuan tidak dikenal.', errorCode: 'SIP_BAD_SLOT');
        }
        $approvals = $sip->approvals ?? [];

        // NO109: persetujuan harus URUT — slot ini tidak bisa disetujui bila ada slot sebelumnya
        // (sesuai urutan BsSip::SIGNERS) yang belum menandatangani.
        $keys = array_keys(BsSip::SIGNERS);
        $idx  = array_search($slot, $keys, true);
        for ($i = 0; $i < $idx; $i++) {
            if (! isset($approvals[$keys[$i]])) {
                throw new BusinessRuleException(
                    'Persetujuan harus berurutan: '.BsSip::SIGNERS[$keys[$i]].' belum menyetujui.',
                    errorCode: 'SIP_ORDER',
                );
            }
        }
        $approvals[$slot] = ['name' => $name, 'approved_by' => $userId, 'approved_at' => now()->toDateTimeString()];

        // #111: status 'Disetujui' (approved) HANYA bila SEMUA signer sudah TTD. Selama belum
        // lengkap status tetap berjalan (Diajukan / Dicetak). Koreksi data lama yang terlanjur 'approved'.
        $sip->approvals = $approvals;
        if ($sip->isFullyApproved()) {
            $sip->status = 'approved';
        } elseif ($sip->status === 'approved') {
            $sip->status = 'printed';
        }
        $sip->save();

        return $sip;
    }

    /** #122 — Tolak SIP: reset semua TTD (approval dibuka lagi) + catat alasan. */
    public function reject(BsSip $sip, int $userId, string $reason): BsSip
    {
        if ($sip->status === 'cancelled') {
            throw new BusinessRuleException('SIP sudah dibatalkan.', errorCode: 'SIP_CANCELLED');
        }

        $sip->update([
            'status'           => 'rejected',
            'approvals'        => [],
            'rejection_reason' => $reason,
            'rejected_by'      => $userId,
            'rejected_at'      => now(),
        ]);

        return $sip;
    }

    /**
     * Nomor SIP: NNN/FIN/MITRA-SI/{bulan romawi}/{tahun} — SATU nomor untuk dokumen ini.
     *
     * Dulu ada dua: sip_number berformat SIP/MUP/26/VIII/00007 diterbitkan saat SIP dibuat,
     * dan nomor cetak 001/FIN/MITRA-SI/VIII/2026 diterbitkan lagi saat pertama kali dicetak.
     * Satu dokumen dengan dua nomor memaksa orang merekonsiliasi keduanya setiap kali
     * dokumennya dirujuk. Sekarang nomornya satu, diterbitkan sekali di sini.
     *
     * Urutannya per TAHUN SIP DIAJUKAN (sip_date), bukan tahun berjalan — SIP bertanggal
     * tahun lalu tidak boleh menyerobot urutan tahun ini.
     *
     * Urutan dibaca dari MAX(print_seq) di dalam transaksi ber-lockForUpdate, bukan dari
     * COUNT(*) seperti sebelumnya: menghitung baris membuat nomor terpakai ulang begitu ada
     * satu SIP terhapus, dan dua pembuatan bersamaan membaca hitungan yang sama. Unique index
     * (tenant_id, print_year, print_seq) tetap menjadi penjaga terakhirnya.
     *
     * @return array{number: string, seq: int, year: int}
     */
    private function nextNumber(string $date): array
    {
        $d = Carbon::parse($date);
        $tahun = (int) $d->year;

        $seq = (int) BsSip::withTrashed()->where('print_year', $tahun)->lockForUpdate()->max('print_seq') + 1;

        return [
            'number' => sprintf('%03d/FIN/MITRA-SI/%s/%d', $seq, BsSip::ROMAWI[$d->month] ?? 'I', $tahun),
            'seq'    => $seq,
            'year'   => $tahun,
        ];
    }
}
