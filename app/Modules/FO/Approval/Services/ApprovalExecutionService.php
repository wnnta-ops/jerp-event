<?php

namespace App\Modules\FO\Approval\Services;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use App\Modules\FO\MarketingSales\Services\PengalihanHakService;
use App\Modules\FO\MarketingSales\Services\PindahUnitService;
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
        // Pengajuan BLT / Komisi — subjek bukan Kontrak (batch via No. Pengajuan di detail).
        if ($approval->jenis === 'BLT') {
            return $this->applyBlt($approval);
        }
        if ($approval->jenis === 'KOMISI') {
            return $this->applyKomisi($approval);
        }

        $kontrak = $approval->subject;
        if (! $kontrak instanceof Kontrak) {
            // Subjek non-kontrak (mis. REFUND atas TTS) — eksekusi khusus menyusul.
            return 'Tidak ada eksekusi otomatis untuk subjek ini (perlu tindak lanjut manual).';
        }

        $baru = (array) $approval->detail_baru;

        return DB::transaction(fn () => match ($approval->jenis) {
            'DISKON', 'DISKON_SPRS' => $this->applyDiskon($kontrak, $baru),
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

        return 'Diskon Rp '.number_format($diskon, 0, ',', '.').' diterapkan → Nilai Kontrak Rp '.number_format($total, 0, ',', '.').'.'.$this->regenTagihan($k);
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

        return 'Harga di-adjust → Nilai Kontrak Rp '.number_format($total, 0, ',', '.').'.'.$this->regenTagihan($k);
    }

    /** Bentuk ulang Jadwal Tagihan mengikuti Nilai Kontrak terbaru (selisih jadi pas 0). */
    private function regenTagihan(Kontrak $k): string
    {
        $n = app(KontrakGeneratorService::class)->tagihan($k->fresh());

        return ' Jadwal Tagihan dibentuk ulang ('.$n.' baris).';
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
        // Faktur Penjualan kontrak ini → BATAL (printout otomatis ber-watermark BATAL).
        \App\Modules\FO\FinanceAR\Models\Fp::where('kontrak_id', $k->id)->update(['status' => 'BATAL']);

        // Ada pengembalian → buat Refund (Finance AR) untuk diproses.
        $pengembalian = (float) ($baru['total_pengembalian'] ?? 0);
        $extra = '';
        if ($pengembalian > 0) {
            $cust = $k->customer; // Bank & Rekening Tujuan ikut data rekening customer
            $refund = Refund::create([
                'tenant_id' => $k->tenant_id ?? 1,
                'tgl_refund' => null, // diisi di Finance → Refund saat diproses
                'jenis' => 'BOOKING',
                'customer_id' => $k->customer_id,
                'kontrak_id' => $k->id,
                'nilai_kotor' => (float) ($baru['total_pelunasan'] ?? $pengembalian),
                'biaya_admin' => (float) ($baru['unrefund'] ?? 0), // % dari UTJ diisi di Finance → Refund
                'nilai_refund' => $pengembalian,
                'bank_id' => $cust?->bank_id,
                'rekening_tujuan' => $cust?->no_rekening,
                'status' => 'DRAFT',
                'keterangan' => 'Pengembalian batal kontrak '.$k->no_kontrak,
            ]);
            $extra = ' Refund '.$refund->no_refund.' (Rp '.number_format($pengembalian, 0, ',', '.').') dibuat.';
        }

        return 'Kontrak '.$k->no_kontrak.' DIBATALKAN; unit dikembalikan TERSEDIA.'.$extra;
    }

    private function applyGantiNama(Kontrak $k, array $baru): string
    {
        // Pengalihan Hak ke customer lain → jalankan transfer penuh (kontrak +
        // reservasi pindah customer, plus catat history PengalihanHak).
        if (! empty($baru['customer_baru_id'])) {
            $ph = app(PengalihanHakService::class)->transfer(
                $k,
                (int) $baru['customer_baru_id'],
                [
                    'tgl_pengalihan' => $baru['tgl_pengalihan'] ?? null,
                    'keterangan' => $baru['keterangan'] ?? null,
                ]
            );

            return 'Pengalihan Hak '.$ph->no_pengalihan.' — customer kontrak kini "'.($ph->customerBaru?->full_name ?? '').'".';
        }
        // Koreksi nama saja (tanpa ganti customer) — ubah nama di master customer.
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
        // Pindah unit penuh: swap unit kontrak + reservasi, unit lama TERSEDIA, +
        // catat history PindahUnit (selaras alur langsung PindahUnitService).
        $pu = app(PindahUnitService::class)->move($k, $unitBaruId, [
            'tgl_pindah' => $baru['tgl_pindah'] ?? null,
            'keterangan' => $baru['keterangan'] ?? null,
        ]);

        return 'Pindah Unit '.$pu->no_pindah.' — unit kontrak kini '.($pu->unitBaru?->kode_unit ?: $pu->unitBaru?->no_unit ?: '').'.';
    }

    private function ppn(float $total): float
    {
        return round($total - round($total / 1.11), 2);
    }

    /** Eksekusi Pengajuan BLT: cairkan semua BLT dalam batch (PENDING → PAID). */
    private function applyBlt(Approval $approval): string
    {
        $no = (string) (((array) $approval->detail_baru)['no_pengajuan'] ?? '');
        if ($no === '') {
            return 'Pengajuan BLT tidak memiliki No. Pengajuan.';
        }

        return DB::transaction(function () use ($no) {
            $lines = \App\Modules\FO\Komisi\Models\Blt::query()
                ->where('no_pengajuan', $no)->where('status', 'PENDING')->get();
            foreach ($lines as $b) {
                $b->update([
                    'status' => 'PAID',
                    'tgl_bayar' => now(),
                    'nett' => max(0, (float) $b->nilai_blt - (float) $b->pph_amount - (float) $b->ppn_amount),
                    'rek_bank' => $b->rek_bank ?: $b->agent?->rekening_bank,
                    'rek_number' => $b->rek_number ?: $b->agent?->rekening_number,
                    'rek_holder' => $b->rek_holder ?: ($b->agent?->rekening_holder ?: $b->nama_user),
                ]);
            }

            return 'Pengajuan BLT '.$no.' disetujui — '.$lines->count().' BLT dicairkan (PAID). Lihat di Realisasi BLT.';
        });
    }

    /** Eksekusi Pengajuan Komisi: cairkan semua komisi dalam batch (PENDING → PAID). */
    private function applyKomisi(Approval $approval): string
    {
        $no = (string) (((array) $approval->detail_baru)['no_pengajuan'] ?? '');
        if ($no === '') {
            return 'Pengajuan Komisi tidak memiliki No. Pengajuan.';
        }

        return DB::transaction(function () use ($no) {
            $lines = \App\Modules\FO\Komisi\Models\Komisi::query()
                ->where('no_pengajuan', $no)->where('status', 'PENDING')->get();
            foreach ($lines as $k) {
                $k->update([
                    'status' => 'PAID',
                    'tgl_bayar' => now(),
                    'amount_net' => max(0, (float) $k->amount - (float) $k->pph_amount - (float) $k->ppn_amount),
                    'rek_bank' => $k->rek_bank ?: $k->agent?->rekening_bank,
                    'rek_number' => $k->rek_number ?: $k->agent?->rekening_number,
                    'rek_holder' => $k->rek_holder ?: ($k->agent?->rekening_holder ?: $k->agent?->name),
                ]);
            }

            return 'Pengajuan Komisi '.$no.' disetujui — '.$lines->count().' komisi dicairkan (PAID). Lihat di Realisasi Komisi.';
        });
    }
}
