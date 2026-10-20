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

            /* SATU JURNAL UNTUK SATU TANGGAL & SATU BANK. Alokasi yang tanggal dan bank
               pengeluarannya sama menumpang jurnal yang sudah ada: barisnya bertambah di posisi
               DEBIT (masing-masing berketerangan sendiri) dan baris KREDIT banknya membesar
               sebesar totalnya. Tanggal atau bank yang berbeda tetap melahirkan jurnal sendiri —
               keduanya memang pengeluaran bank yang berbeda. */
            $jurnalGrup = $this->jurnalGrup($adv, (string) $data['alloc_date'], $cashAccount);

            $journal = $jurnalGrup
                ? $this->tempelkanKeJurnal($jurnalGrup, $adv, $akunPanjar, $cc, $amount, $ket)
                : $this->journal->post([
                    'journal_date' => $data['alloc_date'],
                    'source'       => 'cash',
                    'status'       => 'posted', // Treasury: pengeluaran kasbon langsung posting → mutasi bank tercatat di Rekening Ledger.
                    'reference'    => $adv->advance_number,
                    'description'  => $this->ketJurnal($adv, $ket, 1),
                    'created_by'   => $userId,
                ], [
                    ['account_id' => $akunPanjar, 'cost_center_id' => $cc, 'debit' => $amount, 'credit' => 0, 'description' => $this->ketBaris($adv, $ket)],
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

    /** Keterangan baris DEBIT — inilah "beberapa keterangan" yang membedakan alokasi dalam satu jurnal. */
    private function ketBaris(BsCashAdvance $adv, string $ket): string
    {
        return 'Panjar '.$adv->holder.' — '.$ket;
    }

    /**
     * Keterangan kepala jurnal. Selama masih satu alokasi, bunyinya persis seperti dulu; begitu
     * beberapa alokasi menumpang, keterangan satu di antaranya akan menyesatkan — yang disebut
     * cacahnya, dan rinciannya dibaca pada Keterangan Baris tiap posisi debit.
     */
    private function ketJurnal(BsCashAdvance $adv, string $ket, int $cacah): string
    {
        $awal = 'Alokasi Kasbon '.$adv->advance_number.' — '.$adv->holder;

        return $cacah > 1 ? $awal.' · '.$cacah.' alokasi' : $awal.' · '.$ket;
    }

    /**
     * Jurnal milik grup (bon + tanggal + bank) — diambil dari alokasi yang sudah ada.
     *
     * Data lama punya satu jurnal per alokasi, sehingga satu grup bisa menunjuk beberapa jurnal
     * sekaligus. Yang dipakai adalah jurnal alokasi TERTUA di grup itu; jurnal lama yang lain
     * sengaja DIBIARKAN apa adanya. Menggabungkannya surut berarti menghapus jurnal yang sudah
     * efektif di buku besar — nomornya berlubang selamanya (soft delete tidak pernah membebaskan
     * nomor), dan klasifikasi arus kas barisnya ikut lenyap.
     */
    private function jurnalGrup(BsCashAdvance $adv, string $tanggal, int $bank): ?BsJournal
    {
        $journalId = BsCashAdvanceAllocation::where('cash_advance_id', $adv->id)
            ->whereDate('alloc_date', $tanggal)
            ->where('cash_account_id', $bank)
            ->whereNotNull('journal_id')
            ->orderBy('id')
            ->value('journal_id');

        return $journalId ? BsJournal::whereKey($journalId)->first() : null;
    }

    /**
     * Tempelkan satu alokasi ke jurnal grup yang sudah ada.
     *
     * BARIS LAMA TIDAK DIBONGKAR. JournalService::update() menulis ulang seluruh barisnya —
     * baris lama dihapus permanen, dan bs_ledger_cf_tags serta bs_ledger_cluster_splits ber-FK
     * cascade ke journal_line_id. Memakainya di sini berarti setiap penambahan alokasi
     * MENGHAPUS klasifikasi arus kas alokasi-alokasi sebelumnya, tanpa satu galat pun; Report
     * Cashflow lalu kehilangan angkanya diam-diam. Karena itu barisnya ditambah dan baris
     * kreditnya dibesarkan di tempat, sehingga id tiap baris — dan semua yang menggantung
     * padanya — tetap hidup.
     */
    private function tempelkanKeJurnal(
        BsJournal $journal,
        BsCashAdvance $adv,
        int $akunPanjar,
        ?int $cc,
        float $amount,
        string $ket,
    ): BsJournal {
        // Periode jurnal yang ditumpangi harus terbuka: menambah baris pada bulan yang sudah
        // ditutup mengubah angka yang sudah dilaporkan.
        $this->pastikanPeriodeTerbuka($journal);

        // Jurnal alokasi selalu berbaris kredit tunggal (banknya); itulah baris yang membesar.
        $kredit = $journal->lines()->where('credit', '>', 0)->orderBy('id')->first();

        if (! $kredit) {
            throw new BusinessRuleException(
                'Jurnal alokasi ini tidak punya baris kredit bank — alokasi tidak bisa digabungkan ke dalamnya.',
                errorCode: 'ALLOC_JOURNAL_NO_CREDIT',
            );
        }

        $journal->lines()->create([
            'account_id'     => $akunPanjar,
            'cost_center_id' => $cc,
            'currency'       => 'IDR',
            'kurs'           => 1,
            'description'    => $this->ketBaris($adv, $ket),
            'debit'          => $amount,
            'credit'         => 0,
        ]);

        $kredit->update(['credit' => round((float) $kredit->credit + $amount, 2)]);

        $cacah = $journal->lines()->where('debit', '>', 0)->count();
        $total = round((float) $journal->lines()->sum('debit'), 2);
        $journal->update([
            'total_debit'  => $total,
            'total_credit' => round((float) $journal->lines()->sum('credit'), 2),
            'description'  => $this->ketJurnal($adv, $ket, $cacah),
        ]);

        return $journal->refresh();
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
        DB::transaction(function () use ($alloc, $userId): void {
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

                    /* JURNALNYA BISA DITUMPANGI ALOKASI LAIN. Sejak alokasi bertanggal & berbank
                       sama berbagi satu jurnal, menghapus jurnalnya begitu saja akan ikut
                       melenyapkan pengeluaran alokasi yang masih ada — barisnya hilang dari buku
                       besar sementara nominalnya tetap terhitung sebagai Uang Muka Operasional
                       oleh totalAlokasi(), dan realisasi berhitung terhadap panjar yang di buku
                       besar tidak sebesar itu. Karena itu yang dicabut hanya baris debit miliknya;
                       jurnalnya baru dihapus bila memang tidak ada lagi yang menumpang. */
                    $saudara = BsCashAdvanceAllocation::where('journal_id', $journal->id)
                        ->whereKeyNot($alloc->id)->exists();

                    if ($saudara) {
                        $this->cabutDariJurnal($journal, $alloc, $adv);
                    } else {
                        // Lewat buang(), bukan $journal->delete() saja: laporan GL menjumlah
                        // bs_journal_lines tanpa melihat deleted_at jurnalnya, jadi baris jurnal
                        // yang cuma di-soft-delete tetap terhitung sebagai pengeluaran bank.
                        app(JournalService::class)->buang($journal, 'Hapus alokasi bon sementara', $userId);
                    }
                }
            }

            $alloc->delete();
        });
    }

    /**
     * Cabut satu alokasi dari jurnal grup yang masih ditumpangi alokasi lain.
     *
     * Sama seperti saat menempel: baris lain TIDAK dibongkar, supaya id-nya — dan klasifikasi
     * arus kas yang menggantung padanya — tetap hidup. Baris debit yang dicabut dikenali dari
     * keterangan & nominalnya; bila kebetulan ada dua baris yang persis sama, keduanya memang
     * setara sehingga mencabut yang mana pun menghasilkan jurnal yang sama.
     */
    private function cabutDariJurnal(BsJournal $journal, BsCashAdvanceAllocation $alloc, ?BsCashAdvance $adv): void
    {
        $amount = round((float) $alloc->amount, 2);

        $baris = $journal->lines()
            ->where('debit', '>', 0)
            ->when($adv, fn ($q) => $q->where('description', $this->ketBaris($adv, (string) $alloc->description)))
            ->where('debit', $amount)
            ->orderBy('id')
            ->first();

        // Keterangannya bisa saja sudah disunting orang dari layar Jurnal. Yang menentukan tetap
        // nominalnya — tanpa cadangan ini, barisnya tertinggal dan jurnalnya berhenti balance.
        $baris ??= $journal->lines()->where('debit', $amount)->orderBy('id')->first();

        if (! $baris) {
            throw new BusinessRuleException(
                'Baris jurnal alokasi ini tidak ditemukan — hapus lewat jurnal koreksi supaya buku besarnya tetap seimbang.',
                errorCode: 'ALLOC_LINE_NOT_FOUND',
            );
        }

        $kredit = $journal->lines()->where('credit', '>', 0)->orderBy('id')->first();
        if (! $kredit) {
            throw new BusinessRuleException(
                'Jurnal alokasi ini tidak punya baris kredit bank.',
                errorCode: 'ALLOC_JOURNAL_NO_CREDIT',
            );
        }

        $baris->delete();
        $kredit->update(['credit' => round((float) $kredit->credit - $amount, 2)]);

        $sisaKet = (string) BsCashAdvanceAllocation::where('journal_id', $journal->id)
            ->whereKeyNot($alloc->id)->orderBy('id')->value('description');
        $cacah = $journal->lines()->where('debit', '>', 0)->count();

        $journal->update([
            'total_debit'  => round((float) $journal->lines()->sum('debit'), 2),
            'total_credit' => round((float) $journal->lines()->sum('credit'), 2),
            'description'  => $adv ? $this->ketJurnal($adv, $sisaKet, $cacah) : $journal->description,
        ]);
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
