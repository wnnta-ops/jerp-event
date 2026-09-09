<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * ALOKASI KASBON — pengeluaran bertahap atas bon yang dibayar lewat Debit VA.
 *
 * Pembayaran Debit VA tidak berjurnal: uangnya masih berada di rekening perusahaan, hanya
 * disediakan pada kartu/VA-nya. Yang memindahkannya ke tangan pemegang bon adalah ALOKASI —
 * dan alokasi itulah yang berjurnal:
 *
 *     Dr Uang Muka Operasional  /  Cr bank pengeluaran yang dipilih
 *
 * Karena itu Uang Muka Operasional sebuah bon Debit VA TIDAK sebesar nominal bonnya, melainkan
 * sebesar total alokasinya. Seluruh tahap sesudahnya (realisasi, refund, reimburse) berhitung
 * terhadap angka itu — lihat CashAdvanceService::acuanPanjar().
 */
class CashAdvanceAllocationService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * Tambah satu baris alokasi + jurnalnya.
     *
     * @param  array{alloc_date:string, description?:?string, amount:float|int|string, cash_account_id:int|string}  $data
     */
    public function tambah(BsCashAdvance $adv, array $data, ?int $userId = null): BsCashAdvanceAllocation
    {
        return DB::transaction(function () use ($adv, $data, $userId): BsCashAdvanceAllocation {
            $this->pastikanBolehDialokasikan($adv);

            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new BusinessRuleException('Jumlah alokasi harus lebih dari 0.', errorCode: 'ALLOC_ZERO');
            }

            $akunPanjar = (int) $adv->advance_account_id;
            if (! $akunPanjar) {
                throw new BusinessRuleException('Akun Uang Muka Operasional belum diset pada bon ini.', errorCode: 'ALLOC_NO_ADV_ACCOUNT');
            }

            $cashAccount = (int) ($data['cash_account_id'] ?? 0);
            if (! $cashAccount) {
                throw new BusinessRuleException('Pilih bank pengeluaran alokasi.', errorCode: 'ALLOC_NO_CASH');
            }

            /* Total alokasi tidak boleh melampaui nominal bonnya. Alokasi adalah pembagian bon
               itu — bukan pengeluaran baru — dan yang melebihi berarti kartunya dipakai untuk
               sesuatu yang tidak pernah diajukan. Kekurangan yang memang terjadi diselesaikan
               lewat Reimburse sesudah realisasi, bukan dengan alokasi melebihi bon. */
            $sudah = $this->totalAlokasi($adv);
            $sisa = round((float) $adv->amount - $sudah, 2);
            if ($amount > $sisa + 0.001) {
                throw new BusinessRuleException(
                    'Alokasi melebihi sisa bon. Nominal bon '.number_format((float) $adv->amount, 2, ',', '.')
                    .', sudah dialokasikan '.number_format($sudah, 2, ',', '.')
                    .', sisa '.number_format($sisa, 2, ',', '.').'.',
                    errorCode: 'ALLOC_EXCEEDS',
                );
            }

            $ket = trim((string) ($data['description'] ?? '')) ?: 'Alokasi kasbon '.$adv->advance_number;

            /* cost_center_id dibawa KEDUA baris: itulah dimensi yang dibaca Budget Control, dan
               jurnal yang hanya separuh berdimensi terbaca timpang saat direkap per cost center. */
            $cc = $adv->cost_center_id;

            $journal = $this->journal->post([
                'journal_date' => $data['alloc_date'],
                'source'       => 'cash',
                'status'       => 'posted', // Treasury: pengeluaran kasbon langsung posting → mutasi bank tercatat di Rekening Ledger.
                'reference'    => $adv->advance_number,
                'description'  => 'Alokasi Kasbon '.$adv->advance_number.' — '.$adv->holder.' · '.$ket,
                'created_by'   => $userId,
            ], [
                ['account_id' => $akunPanjar, 'cost_center_id' => $cc, 'debit' => $amount, 'credit' => 0, 'description' => 'Panjar '.$adv->holder.' — '.$ket],
                ['account_id' => $cashAccount, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => $amount, 'description' => 'Pengeluaran kasbon '.$adv->advance_number],
            ]);

            return BsCashAdvanceAllocation::create([
                'cash_advance_id' => $adv->id,
                'credit_card_id'  => $adv->credit_card_id,
                'alloc_date'      => $data['alloc_date'],
                'description'     => $ket,
                'amount'          => $amount,
                'cash_account_id' => $cashAccount,
                'journal_id'      => $journal->id,
                'created_by'      => $userId,
            ]);
        });
    }

    /**
     * Hapus satu baris alokasi beserta jurnalnya.
     *
     * Hanya selama bonnya BELUM direalisasi. Sesudah realisasi, angka Uang Muka Operasional
     * yang dialokasikan sudah ikut menjadi dasar realisasi & penyelesaian sisanya — mencabutnya
     * di belakang akan membuat realisasi mengkredit akun yang tidak pernah didebit sebesar itu,
     * tanpa satu galat pun yang memberi tahu.
     */
    public function hapus(BsCashAdvanceAllocation $alloc, ?int $userId = null): void
    {
        DB::transaction(function () use ($alloc): void {
            $adv = BsCashAdvance::whereKey($alloc->cash_advance_id)->first();
            if ($adv && $adv->status !== 'outstanding') {
                throw new BusinessRuleException(
                    'Alokasi tidak bisa dihapus — bon ini sudah direalisasi.',
                    errorCode: 'ALLOC_LOCKED',
                );
            }

            if ($alloc->journal_id) {
                $journal = BsJournal::whereKey($alloc->journal_id)->first();
                // Jurnal yang periodenya sudah ditutup tidak boleh ikut lenyap: pembatalannya
                // harus lewat jurnal koreksi supaya buku besar bulan itu tetap seperti yang
                // sudah dilaporkan.
                if ($journal) {
                    $this->pastikanPeriodeTerbuka($journal);
                    $journal->delete();
                }
            }

            $alloc->delete();
        });
    }

    /** Total alokasi sebuah bon — dasar Uang Muka Operasional bon Debit VA. */
    public function totalAlokasi(BsCashAdvance $adv): float
    {
        return round((float) BsCashAdvanceAllocation::where('cash_advance_id', $adv->id)->sum('amount'), 2);
    }

    private function pastikanBolehDialokasikan(BsCashAdvance $adv): void
    {
        if ($adv->payment_method !== 'debit_va') {
            throw new BusinessRuleException(
                'Alokasi Kasbon hanya untuk bon yang dibayar lewat Debit VA.',
                errorCode: 'ALLOC_NOT_DEBIT_VA',
            );
        }
        if ($adv->status !== 'outstanding') {
            throw new BusinessRuleException(
                $adv->status === 'requested'
                    ? 'Bon ini belum dibayar — alokasi baru bisa dibuat setelah pembayaran Debit VA tersimpan.'
                    : 'Bon ini sudah direalisasi — alokasi tidak bisa ditambah lagi.',
                errorCode: 'ALLOC_BAD_STATUS',
            );
        }
    }

    private function pastikanPeriodeTerbuka(BsJournal $journal): void
    {
        $tgl = $journal->journal_date;
        $periode = $this->journal->ensurePeriod((int) $tgl->year, (int) $tgl->month);
        if ($periode->status === 'closed') {
            throw new BusinessRuleException(
                "Periode {$tgl->year}/{$tgl->month} sudah CLOSED — alokasi bulan itu tidak bisa dihapus.",
                errorCode: 'PERIOD_CLOSED',
            );
        }
    }
}
