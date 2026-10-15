<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * BATAL Invoice Hutang / Reimburse / Deposit.
 *
 * Datanya TIDAK dihapus: status 'batal' + siapa/kapan/alasannya, lalu invoice di-soft-delete
 * supaya otomatis keluar dari Aging, Kartu Hutang, Kuitansi, Proyeksi, dan laporan lain yang
 * memakai model ini — tanpa perlu menyisir satu per satu penyaring di layar-layar itu. Nomor &
 * jejaknya tetap bisa dibuka dari daftar Invoice Hutang (baris berlabel BATAL).
 *
 * Yang ikut dibatalkan: jurnal registrasinya (seperti Void Kuitansi), termin pembayarannya,
 * dan Purchase Order yang DITERBITKAN OTOMATIS untuknya.
 *
 * Syarat (diperiksa di sini, bukan hanya di layar): invoice dari Registrasi, belum batal, tidak
 * ada Kuitansi menunggu/aktif, belum ada pembayaran/penghapusan, dan periode jurnalnya belum
 * ditutup. Siapa yang BOLEH (izin & keadaan "sudah diproses FAT") diputuskan controller.
 */
final class BatalkanApInvoice
{
    public function execute(BsApInvoice $inv, string $alasan, ?int $userId): void
    {
        if ($inv->batal()) {
            throw new BusinessRuleException('Invoice ini sudah dibatalkan.', errorCode: 'AP_SUDAH_BATAL');
        }
        if (! $inv->dariRegistrasi()) {
            throw new BusinessRuleException('Invoice ini bukan dari Registrasi (hasil Posting / Saldo Awal) — tidak bisa dibatalkan dari sini.', errorCode: 'AP_BUKAN_REGISTRASI');
        }
        if ($inv->kuitansiAktifAtauMenunggu()) {
            throw new BusinessRuleException('Invoice ini masih tercantum di Kuitansi Hutang. Void / tolak Kuitansinya lebih dulu.', errorCode: 'AP_ADA_KUITANSI');
        }
        if ((float) $inv->paid_amount > 0 || $inv->payments()->exists()) {
            throw new BusinessRuleException('Invoice ini sudah ada pembayarannya — batalkan pembayarannya lebih dulu.', errorCode: 'AP_SUDAH_DIBAYAR');
        }
        if (\App\Domain\BusinessSupport\Models\BsApMemorial::where('ap_invoice_id', $inv->id)->exists()) {
            throw new BusinessRuleException('Invoice ini sudah ada Penghapusan Nilai Hutang — tidak bisa dibatalkan.', errorCode: 'AP_ADA_PENGHAPUSAN');
        }

        DB::transaction(function () use ($inv, $alasan, $userId): void {
            if ($inv->journal_id && ($journal = BsJournal::find($inv->journal_id))) {
                self::pastikanPeriodeTerbuka($journal);
                $journal->lines()->delete();
                $journal->delete();
            }

            $inv->schedules()->delete();

            if ($po = $inv->poOtomatis()) {
                $po->lines()->delete();
                $po->delete();
            }

            $inv->forceFill([
                'status' => BsApInvoice::STATUS_BATAL,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => mb_substr($alasan, 0, 255),
            ])->save();
            $inv->delete();
        });
    }

    /** Jurnal pada periode yang sudah CLOSED tidak boleh disentuh — sama dengan Void Kuitansi. */
    public static function pastikanPeriodeTerbuka(BsJournal $journal): void
    {
        $period = BsFiscalPeriod::where('tenant_id', $journal->tenant_id)
            ->where('year', $journal->period_year)->where('month', $journal->period_month)->first();
        if ($period && $period->status === 'closed') {
            throw new BusinessRuleException("Periode {$journal->period_year}/{$journal->period_month} sudah CLOSED — invoice tidak bisa diubah atau dibatalkan.", errorCode: 'AP_PERIOD_CLOSED');
        }
    }
}
