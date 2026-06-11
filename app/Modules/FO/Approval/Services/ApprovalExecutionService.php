<?php

namespace App\Modules\FO\Approval\Services;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menerapkan perubahan ke Kontrak saat Approval Level-2 disetujui (selaras legacy:
 * Diskon disetujui → ubah kontrak; Batal → batalkan; Ganti Nama/Unit → tukar; dst).
 * detail_baru (JSON) = nilai yang diminta. Hasil dicatat di executed_result.
 *
 * TERINTEGRASI: perubahan kontrak memengaruhi jadwal tagihan (Finance AR) & komisi —
 * gunakan KontrakGeneratorService untuk regenerate bila perlu (tahap lanjut).
 */
class ApprovalExecutionService
{
    public function execute(Approval $approval): string
    {
        $kontrak = $approval->subject;
        if (! $kontrak instanceof Kontrak) {
            // Subjek non-kontrak (mis. REFUND atas TTS) — eksekusi khusus menyusul.
            return 'Tidak ada eksekusi otomatis untuk subjek ini (perlu tindak lanjut manual).';
        }

        $baru = (array) $approval->detail_baru;

        return DB::transaction(fn () => match ($approval->jenis) {
            'DISKON' => $this->applyDiskon($kontrak, $baru),
            'ADJ_HARGA' => $this->applyAdjustHarga($kontrak, $baru),
            'BATAL_KONTRAK' => $this->applyBatal($kontrak, $baru),
            'GANTI_NAMA' => $this->applyGantiNama($kontrak, $baru),
            'GANTI_UNIT' => $this->applyGantiUnit($kontrak, $baru),
            default => 'Jenis "'.$approval->jenis.'" disetujui — penerapan otomatis belum tersedia (tindak lanjut manual).',
        });
    }

    private function applyDiskon(Kontrak $k, array $baru): string
    {
        $diskon = (float) ($baru['nilai_diskon'] ?? $baru['diskon'] ?? 0);
        $total = isset($baru['nilai_kontrak_baru'])
            ? (float) $baru['nilai_kontrak_baru']
            : max(0, (float) $k->harga_jual - $diskon);

        $k->update([
            'diskon' => $diskon,
            'total_kontrak' => $total,
            'ppn' => $this->ppn($total),
        ]);

        return 'Diskon Rp '.number_format($diskon, 0, ',', '.').' diterapkan → Nilai Kontrak Rp '.number_format($total, 0, ',', '.').'.';
    }

    private function applyAdjustHarga(Kontrak $k, array $baru): string
    {
        $total = (float) ($baru['total_kontrak_baru'] ?? $baru['nilai_kontrak'] ?? $k->total_kontrak);
        $gross = (float) ($baru['harga_jual_baru'] ?? $baru['gross'] ?? $total);

        $update = [
            'harga_jual' => $gross,
            'total_kontrak' => $total,
            'ppn' => $this->ppn($total),
        ];
        if (isset($baru['diskon'])) {
            $update['diskon'] = (float) $baru['diskon'];
        }
        $k->update($update);

        return 'Harga di-adjust → Nilai Kontrak Rp '.number_format($total, 0, ',', '.').'.';
    }

    private function applyBatal(Kontrak $k, array $baru = []): string
    {
        $k->update(['status' => 'BATAL']);
        if ($k->unit_id && $unit = Unit::find($k->unit_id)) {
            $unit->update(['status' => 'TERSEDIA']);
        }
        if ($k->reservasi) {
            $k->reservasi->update(['status' => 'BATAL']);
        }

        // Ada pengembalian → buat Refund (Finance AR) untuk diproses.
        $pengembalian = (float) ($baru['total_pengembalian'] ?? 0);
        $extra = '';
        if ($pengembalian > 0) {
            $refund = Refund::create([
                'tenant_id' => $k->tenant_id ?? 1,
                'tgl_refund' => $baru['tgl_pengembalian'] ?? now()->toDateString(),
                'jenis' => 'BOOKING',
                'customer_id' => $k->customer_id,
                'kontrak_id' => $k->id,
                'nilai_kotor' => (float) ($baru['total_pelunasan'] ?? $pengembalian),
                'biaya_admin' => (float) ($baru['unrefund'] ?? 0),
                'nilai_refund' => $pengembalian,
                'status' => 'DRAFT',
                'keterangan' => 'Pengembalian batal kontrak '.$k->no_kontrak,
            ]);
            $extra = ' Refund '.$refund->no_refund.' (Rp '.number_format($pengembalian, 0, ',', '.').') dibuat.';
        }

        return 'Kontrak '.$k->no_kontrak.' DIBATALKAN; unit dikembalikan TERSEDIA.'.$extra;
    }

    private function applyGantiNama(Kontrak $k, array $baru): string
    {
        if (! empty($baru['customer_baru_id'])) {
            $k->update(['customer_id' => (int) $baru['customer_baru_id']]);

            return 'Customer kontrak diganti (ID '.$baru['customer_baru_id'].').';
        }
        if (! empty($baru['nama_baru']) && $k->customer) {
            $k->customer->update(['full_name' => $baru['nama_baru']]);

            return 'Nama customer diubah menjadi "'.$baru['nama_baru'].'".';
        }

        throw new RuntimeException('Ganti Nama: detail_baru harus berisi customer_baru_id atau nama_baru.');
    }

    private function applyGantiUnit(Kontrak $k, array $baru): string
    {
        $unitBaruId = (int) ($baru['unit_baru_id'] ?? 0);
        if (! $unitBaruId) {
            throw new RuntimeException('Ganti Unit: detail_baru harus berisi unit_baru_id.');
        }
        $unitBaru = Unit::lockForUpdate()->findOrFail($unitBaruId);
        if ($unitBaru->status === 'TERJUAL') {
            throw new RuntimeException('Unit tujuan '.$unitBaru->no_unit.' sudah TERJUAL.');
        }

        if ($k->unit_id && $unitLama = Unit::find($k->unit_id)) {
            $unitLama->update(['status' => 'TERSEDIA']);
        }
        $k->update(['unit_id' => $unitBaru->id]);
        $unitBaru->update(['status' => 'TERJUAL']);
        if ($k->reservasi) {
            $k->reservasi->update(['unit_id' => $unitBaru->id]);
        }

        return 'Unit kontrak diganti ke '.$unitBaru->no_unit.'.';
    }

    private function ppn(float $total): float
    {
        return round($total - round($total / 1.11), 2);
    }
}
