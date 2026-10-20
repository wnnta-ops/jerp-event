<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bon Sementara (panjar / uang muka operasional).
 *
 * PEMBAYARAN REGULER
 *   Pengeluaran : Dr Uang Muka Operasional / Cr Kas/Bank   (saat dibayar)
 *
 * PEMBAYARAN DEBIT VA — pembayarannya TIDAK berjurnal
 *   Pembayaran  : tidak ada jurnal; saldo disediakan pada kartu/VA (Set Up Debit Card)
 *   Alokasi     : Dr Uang Muka Operasional / Cr bank pengeluaran  (per baris Alokasi Kasbon)
 *   → Uang Muka Operasional bon Debit VA = TOTAL ALOKASI-nya, bukan nominal bonnya.
 *
 * REALISASI (keduanya)
 *   Dr Beban (rincian) [+ Dr Kas jika ada refund] / Cr Uang Muka Operasional [+ Cr Kas jika nombok]
 */
final class CashAdvanceService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * Pengajuan bon sementara (#49) — BELUM membentuk jurnal. Jurnal Uang Muka Operasional
     * baru terbentuk saat dibayar Finance (lihat pay()). Sumber kas/bank diisi Finance saat bayar.
     *
     * #150: departemen pengaju tidak lagi disimpan terpisah — cost_center_id yang wajib
     *       karyawan yang dipilih (employee_id).
     *
     * @param array{advance_date:string, holder:string, purpose:string, amount:float|int|string, department_id?:int|string|null, employee_id?:int|string|null} $data
     */
    public function issue(array $data, ?int $userId = null): BsCashAdvance
    {
        return DB::transaction(function () use ($data, $userId): BsCashAdvance {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new BusinessRuleException('Nilai panjar harus lebih dari 0.', errorCode: 'ADV_ZERO');
            }
            $advanceAccount = BsAccount::where('code', config('business_support.gl_accounts.advance_op'))->value('id');
            if (! $advanceAccount) {
                throw new BusinessRuleException('Akun Uang Muka Operasional belum diset.', errorCode: 'ADV_NO_ACCOUNT');
            }

            /* COST CENTER WAJIB — inilah yang menautkan bon ke Budget Control.
               Nilainya diteruskan ke bs_journal_lines.cost_center_id pada SELURUH jurnal yang
               lahir dari bon ini (pembayaran, realisasi, refund/reimburse), lalu dibaca
               BudgetRealizationService untuk mencocokkan realisasi dengan plafon per
               (akun, cost center). Bon tanpa cost center tetap membentuk jurnal dan tetap
               menjadi beban di GL, tetapi TIDAK PERNAH muncul sebagai realisasi di Budget
               Control — anggarannya seolah tidak pernah terpakai, dan itu baru ketahuan
               berbulan kemudian saat anggaran direkonsiliasi.

               Ditegakkan DI SINI juga, bukan hanya di validasi request: pemanggilan service
               langsung (job, perintah artisan, kode baru) tidak melewati validasi itu. */
            $costCenterId = ($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null;
            if (! $costCenterId) {
                throw new BusinessRuleException(
                    'Cost center wajib dipilih — tanpa itu bon ini tidak akan terbaca sebagai realisasi di Budget Control.',
                    errorCode: 'ADV_NO_COST_CENTER',
                );
            }

            /* GERBANG ANGGARAN — ditegakkan SEBELUM bon terbentuk.
               Saat bon diajukan, akun bebannya belum diketahui (baru ditentukan pada
               realisasi), jadi yang diperiksa plafon DEPARTEMENnya: jumlah anggaran approved
               cost center itu dikurangi realisasinya. Lihat BudgetGuardService.
               Di sini, bukan di validasi request: pemanggilan service langsung (job, perintah
               artisan) tidak melewati validasi itu — alasan yang sama dengan cost center. */
            app(BudgetGuardService::class)->assertPlafonCostCenter($costCenterId, $amount, (string) $data['advance_date']);

            return BsCashAdvance::create([
                'advance_number' => $this->nextNumber($data['advance_date']),
                'advance_date' => $data['advance_date'],
                'holder' => $data['holder'],
                'cost_center_id' => $costCenterId,
                'purpose' => $data['purpose'],
                'cash_account_id' => null,                 // diisi Finance saat pembayaran
                'advance_account_id' => $advanceAccount,
                'amount' => $amount,
                'status' => 'requested',          // menunggu pembayaran Finance
                // Cara persetujuan dipilih per pengajuan. Nilai asing DIABAIKAN — jatuh ke
                // 'online', bukan diterima apa adanya: satu untai keliru yang lolos ke sini
                // akan membaca dirinya "bukan offline" pada satu tempat dan "bukan online" di
                // tempat lain, dan bonnya berperilaku setengah-setengah.
                'approval_mode' => (($data['approval_mode'] ?? null) === 'offline') ? 'offline' : 'online',
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Batalkan pengajuan bon — DITOLAK SEBELUM DIBAYAR.
     *
     * HANYA selagi berstatus 'requested'. Setelah dibayar, uangnya sudah keluar dan jurnalnya
     * sudah terbentuk; yang berlaku saat itu Refund/Reimburse, bukan pembatalan. Dijaga di
     * sini, bukan hanya di layar: pemanggilan service langsung tidak melewati gerbang layar,
     * dan membatalkan bon yang sudah berjurnal meninggalkan jurnal tanpa dokumen.
     *
     * TIDAK ADA JURNAL yang dibalik — pada tahap ini memang belum ada satu pun.
     */
    public function cancel(BsCashAdvance $advance, string $alasan, ?int $userId = null): BsCashAdvance
    {
        if ($advance->isCancelled()) {
            throw new BusinessRuleException('Bon ini sudah dibatalkan.', errorCode: 'ADV_ALREADY_CANCELLED');
        }

        if ($advance->status !== 'requested') {
            throw new BusinessRuleException(
                'Bon '.$advance->advance_number.' sudah dibayar — pembatalan hanya untuk pengajuan yang belum dibayar. '
                .'Untuk bon yang sudah berjalan, pakai Refund atau Reimburse.',
                errorCode: 'ADV_CANCEL_TOO_LATE',
            );
        }

        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new BusinessRuleException(
                'Alasan penolakan wajib diisi — tanpa itu pengaju harus bertanya lewat jalur lain, dan jawabannya tidak tercatat.',
                errorCode: 'ADV_CANCEL_NO_REASON',
            );
        }

        $advance->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancel_reason' => mb_substr($alasan, 0, 255),
        ]);

        return $advance->refresh();
    }

    /** #127 — Stempel satu slot approval bon sementara (urut, sesuai requiredSigners). */
    /**
     * Langkah mundur berikutnya untuk bon ini — null bila tidak ada (bon sudah terhapus).
     *
     * Bon dimundurkan SATU LANGKAH per tindakan, kebalikan urutan majunya:
     *   Realized + Refund/Reimburse → Batal Refund/Reimburse (sisa kembali "belum diselesaikan")
     *   Realized + Masuk Saldo       → Batal Masuk Saldo
     *   Realized                     → Batal Realisasi   (→ Outstanding)
     *   Outstanding                  → Batal Pembayaran  (→ Diajukan)
     *   Diajukan / Batal             → Hapus Pengajuan
     *
     * @return array{kode:string,label:string}|null
     */
    public function langkahMundur(BsCashAdvance $adv): ?array
    {
        if ($adv->trashed()) {
            return null;
        }
        if ($adv->status === 'realized') {
            if ($adv->settle_journal_id) {
                return ['kode' => 'settle', 'label' => (float) $adv->settlement < 0 ? 'Batal Reimburse' : 'Batal Refund'];
            }
            if ($adv->settlement_status === 'card') {
                return ['kode' => 'card', 'label' => 'Batal Masuk Saldo'];
            }

            return ['kode' => 'realize', 'label' => 'Batal Realisasi'];
        }
        if ($adv->status === 'outstanding') {
            return ['kode' => 'pay', 'label' => 'Batal Pembayaran'];
        }

        return ['kode' => 'delete', 'label' => 'Hapus Pengajuan'];
    }

    /**
     * Jalankan langkah mundur berikutnya. Jurnal yang dibuang lewat JournalService::buang()
     * (periode harus terbuka; jejaknya tetap di log status jurnal), dan langkahnya sendiri
     * dicatat di bs_cash_advance_mundur.
     *
     * $kode WAJIB sama dengan langkah yang sedang berlaku — kalau orang lain lebih dulu
     * memundurkan bon yang sama, klik kedua ditolak alih-alih ikut memundurkan satu langkah lagi.
     */
    public function mundur(BsCashAdvance $adv, string $kode, string $alasan, ?int $userId = null): string
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new BusinessRuleException('Alasan wajib diisi.', errorCode: 'ADV_UNDO_NO_REASON');
        }

        return DB::transaction(function () use ($adv, $kode, $alasan, $userId): string {
            $adv = BsCashAdvance::whereKey($adv->id)->lockForUpdate()->firstOrFail();
            $langkah = $this->langkahMundur($adv);
            if (! $langkah || $langkah['kode'] !== $kode) {
                throw new BusinessRuleException('Status bon sudah berubah — muat ulang halamannya.', errorCode: 'ADV_UNDO_STALE');
            }
            $dari = $adv->status.($adv->settlement_status ? '/'.$adv->settlement_status : '');
            $buang = fn (?int $id) => $id && ($j = BsJournal::find($id))
                ? $this->journal->buang($j, $langkah['label'].' '.$adv->advance_number.': '.$alasan, $userId) : null;

            switch ($kode) {
                case 'settle':
                    $buang((int) $adv->settle_journal_id);
                    $adv->update(['settlement_status' => 'pending', 'settle_journal_id' => null]);
                    break;

                case 'card':
                    $this->batalMasukSaldo($adv, $userId);
                    $adv->update(['settlement_status' => 'pending']);
                    break;

                case 'realize':
                    $buang((int) $adv->realize_journal_id);
                    $adv->lines()->delete();
                    $adv->update([
                        'status' => 'outstanding', 'realized_date' => null, 'realized_amount' => 0,
                        'settlement' => 0, 'settlement_status' => null, 'realize_journal_id' => null,
                    ]);
                    break;

                case 'pay':
                    // Alokasi Debit VA dicabut lewat service-nya sendiri: jurnalnya bisa ditumpangi
                    // alokasi bon lain, dan hanya service itu yang tahu cara mencabut baris miliknya.
                    $alokasi = app(CashAdvanceAllocationService::class);
                    foreach (BsCashAdvanceAllocation::where('cash_advance_id', $adv->id)->get() as $al) {
                        $alokasi->hapus($al, $userId);
                    }
                    $buang((int) $adv->journal_id);
                    // Pengajuan masa berlaku kartu yang lahir dari pembayaran ini, bila belum diputus.
                    BsCreditCardRequest::where('source_type', CreditCardService::SUMBER_BON)
                        ->where('source_id', $adv->id)->where('status', 'pending')
                        ->update(['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => now(),
                            'note' => 'Dibatalkan — pembayaran bon '.$adv->advance_number.' dibatalkan']);
                    $adv->update([
                        'status' => 'requested', 'paid_date' => null, 'journal_id' => null,
                        'cash_account_id' => null, 'credit_card_id' => null, 'va_expired_date' => null,
                        'allocation_done_at' => null, 'allocation_done_by' => null,
                    ]);
                    break;

                case 'delete':
                    $adv->delete();
                    break;
            }

            DB::table('bs_cash_advance_mundur')->insert([
                'tenant_id' => $adv->tenant_id, 'cash_advance_id' => $adv->id,
                'advance_number' => $adv->advance_number, 'langkah' => $langkah['label'],
                'dari_status' => $dari, 'alasan' => mb_substr($alasan, 0, 255),
                'user_id' => $userId, 'created_at' => now(),
            ]);

            return $langkah['label'];
        });
    }

    /** Kebalikan masukSaldo(): saldo kartu dikurangi lagi, permintaannya ditandai dibatalkan. */
    private function batalMasukSaldo(BsCashAdvance $adv, ?int $userId): void
    {
        $req = BsCreditCardRequest::where('source_type', CreditCardService::SUMBER_MASUK_SALDO)
            ->where('source_id', $adv->id)->where('status', 'approved')->latest('id')->first();
        if (! $req) {
            return;
        }
        $card = BsCreditCard::whereKey($req->credit_card_id)->lockForUpdate()->first();
        if ($card) {
            $sisa = round((float) $card->balance - (float) $req->amount, 2);
            if ($sisa < 0) {
                throw new BusinessRuleException(
                    'Saldo kartu '.$card->label().' sudah terpakai — Masuk Saldo tidak bisa dibatalkan (saldo tinggal '
                    .number_format((float) $card->balance, 2, ',', '.').').',
                    errorCode: 'ADV_UNDO_CARD_USED',
                );
            }
            $card->balance = $sisa;
            $card->save();
        }
        $req->update(['status' => 'rejected', 'note' => trim(($req->note ?? '').' — DIBATALKAN (mundur bon) oleh user #'.$userId)]);
    }

    public function approve(BsCashAdvance $advance, string $slot, int $userId, string $name): BsCashAdvance
    {
        if (! array_key_exists($slot, BsCashAdvance::SIGNERS)) {
            throw new BusinessRuleException('Slot persetujuan tidak dikenal.', errorCode: 'ADV_BAD_SLOT');
        }
        // Bon berpersetujuan OFFLINE tidak menerima tanda tangan digital sama sekali.
        // Diperiksa tersendiri agar pesannya menyebut sebabnya; tanpa ini penolakannya jatuh
        // ke ADV_SLOT_NA yang berbunyi "tidak diperlukan untuk nominal bon ini" — menyesatkan,
        // karena yang menentukan bukan nominalnya melainkan cara persetujuannya.
        if ($advance->isOfflineApproval()) {
            throw new BusinessRuleException(
                'Bon ini memakai Persetujuan Offline — tanda tangannya dikumpulkan di formulir cetak, bukan di aplikasi.',
                errorCode: 'ADV_OFFLINE',
            );
        }
        $required = $advance->requiredSigners();
        if (! in_array($slot, $required, true)) {
            throw new BusinessRuleException('Slot ini tidak diperlukan untuk nominal bon ini.', errorCode: 'ADV_SLOT_NA');
        }
        $approvals = $advance->approvals ?? [];

        // Urut: slot ini tidak bisa di-approve bila ada slot sebelumnya (dalam requiredSigners) belum TTD.
        $idx = array_search($slot, $required, true);
        for ($i = 0; $i < $idx; $i++) {
            if (! isset($approvals[$required[$i]])) {
                throw new BusinessRuleException(
                    'Persetujuan harus berurutan: '.BsCashAdvance::SIGNERS[$required[$i]].' belum menyetujui.',
                    errorCode: 'ADV_ORDER',
                );
            }
        }
        $approvals[$slot] = ['name' => $name, 'approved_by' => $userId, 'approved_at' => now()->toDateTimeString()];
        $advance->update(['approvals' => $approvals]);

        return $advance;
    }

    /**
     * Pembayaran bon sementara oleh Finance (#49) — baru di sini jurnal terbentuk:
     *   Dr Uang Muka Operasional / Cr Bank/Kas (tanggal = tanggal transfer).
     *
     * @param array{paid_date:string, cash_account_id:int} $data
     */
    /**
     * Akun tempat panjar bon ini tersimpan — yang harus dikredit saat realisasi & diselesaikan
     * saat refund/reimburse.
     *
     * Bon Debit VA mendebit REKENING KARTUNYA saat dibayar, bukan Uang Muka Operasional; jadi
     * akun itu pula yang dilepas kembali. Sisa saldo kartu adalah bakinya: pengajuan mendebit
     * sebesar nominal bon, realisasi mengkredit sebesar yang terpakai.
     *
     * Akun kartunya dibaca lewat query, BUKAN $adv->creditCard: relasinya belum tentu ter-load,
     * dan Model::preventLazyLoading() aktif di luar produksi — pembacaan malas di sini akan
     * melempar di dev/test sementara di produksi lolos diam-diam.
     */
    private function akunPanjar(BsCashAdvance $adv): int
    {
        if (! $this->bonKartuLama($adv)) {
            return (int) $adv->advance_account_id;
        }

        $akunKartu = BsCreditCard::whereKey($adv->credit_card_id)->value('account_id');

        return (int) ($akunKartu ?: $adv->advance_account_id);
    }

    /**
     * Bon Debit VA MODEL LAMA — yang pembayarannya dulu masih membentuk jurnal ke akun kartunya.
     *
     * Pembedanya adalah DATA, bukan payment_method: sejak pembayaran Debit VA tidak lagi
     * berjurnal, bon baru punya journal_id NULL sedangkan bon lama sudah terlanjur mendebit akun
     * kartunya. Membedakannya lewat payment_method saja akan membuat bon lama yang belum
     * direalisasi mengkredit Uang Muka Operasional yang tidak pernah menerima panjarnya — akun
     * kartunya tinggal bersaldo debit selamanya, dan Uang Muka Operasional menjadi minus.
     */
    private function bonKartuLama(BsCashAdvance $adv): bool
    {
        return $adv->credit_card_id !== null && $adv->journal_id !== null;
    }

    /**
     * ACUAN PANJAR — berapa rupiah yang benar-benar masuk ke akun panjarnya.
     *
     * Bon reguler (dan bon Debit VA model lama): sebesar nominal bonnya, sebab pembayarannya
     * langsung mendebit akun panjar sejumlah itu.
     *
     * Bon Debit VA model baru: sebesar TOTAL ALOKASI KASBON-nya. Pembayarannya tidak berjurnal;
     * yang mendebit Uang Muka Operasional adalah tiap baris alokasi. Memakai nominal bon di sini
     * akan menghitung selisih terhadap uang yang tidak pernah dikeluarkan dari bank, sehingga
     * refund/reimburse-nya menyelesaikan angka yang tidak ada.
     */
    private function acuanPanjar(BsCashAdvance $adv): float
    {
        if ($adv->payment_method !== 'debit_va' || $this->bonKartuLama($adv)) {
            return round((float) $adv->amount, 2);
        }

        return round((float) BsCashAdvanceAllocation::where('cash_advance_id', $adv->id)->sum('amount'), 2);
    }

    public function pay(BsCashAdvance $adv, array $data, ?int $userId = null): BsCashAdvance
    {
        return DB::transaction(function () use ($adv, $data, $userId): BsCashAdvance {
            if ($adv->status !== 'requested') {
                throw new BusinessRuleException('Bon sementara ini sudah dibayar / diproses.', errorCode: 'ADV_NOT_REQUESTED');
            }
            $amount = round((float) $adv->amount, 2);
            $advanceAccount = $adv->advance_account_id
                ?: BsAccount::where('code', config('business_support.gl_accounts.advance_op'))->value('id');
            if (! $advanceAccount) {
                throw new BusinessRuleException('Akun Uang Muka Operasional belum diset.', errorCode: 'ADV_NO_ACCOUNT');
            }

            // Cara pembayaran. Nilai asing jatuh ke 'reguler' — bukan diterima apa adanya,
            // sebab untai keliru akan membaca dirinya "bukan debit_va" dan lolos dari kewajiban
            // tanggal expired sambil tersimpan sebagai cara pembayaran yang tidak dikenal.
            $method = (($data['payment_method'] ?? null) === 'debit_va') ? 'debit_va' : 'reguler';
            $expired = $method === 'debit_va' ? ($data['va_expired_date'] ?? null) : null;

            /* Pembayaran reguler tetap WAJIB bersumber bank/kas — hanya Debit VA yang bebas dari
               kewajiban itu. Ditegakkan di sini juga, bukan hanya di validasi request: layar
               bayar massal dan pemanggilan service langsung tidak lewat aturan required_unless. */
            if ($method !== 'debit_va' && empty($data['cash_account_id'])) {
                throw new BusinessRuleException('Pilih sumber bank/kas pembayaran.', errorCode: 'ADV_NO_CASH');
            }

            if ($method === 'debit_va') {
                // Ditegakkan DI SINI juga, bukan hanya di layar & validasi request: VA tanpa
                // tanggal expired tidak bisa direkonsiliasi — tidak ada cara mengetahui kapan
                // ia berhenti bisa didebit, sementara jurnalnya sudah terbentuk.
                if (! $expired) {
                    throw new BusinessRuleException(
                        'Pembayaran Debit VA wajib mencantumkan tanggal expired VA.',
                        errorCode: 'ADV_VA_NO_EXPIRY',
                    );
                }
                // VA yang kedaluwarsa sebelum tanggal transfernya tidak pernah bisa didebit.
                if (strtotime((string) $expired) < strtotime((string) $data['paid_date'])) {
                    throw new BusinessRuleException(
                        'Tanggal expired VA tidak boleh mendahului tanggal transfer.',
                        errorCode: 'ADV_VA_EXPIRED_BEFORE_PAID',
                    );
                }
            }

            /* KARTU KREDIT/VA — wajib pada Debit VA.
               Kartunya menentukan DUA hal sekaligus: akun yang didebit jurnalnya, dan kartu
               mana yang menerima permintaan saldo & masa berlakunya. Tanpa kartu, keduanya
               tidak punya sasaran. */
            $card = null;
            if ($method === 'debit_va') {
                $card = ! empty($data['credit_card_id'])
                    ? BsCreditCard::whereKey((int) $data['credit_card_id'])->first()
                    : null;
                if (! $card) {
                    throw new BusinessRuleException(
                        'Pembayaran Debit VA wajib memilih kartu debit/VA.',
                        errorCode: 'ADV_VA_NO_CARD',
                    );
                }
                // Kartu nonaktif atau yang sudah lewat masa berlakunya tidak boleh dipakai —
                // "apabila expired, maka saldo tidak bisa digunakan".
                if (! $card->isUsable($data['paid_date'])) {
                    throw new BusinessRuleException(
                        $card->is_active
                            ? 'Kartu '.$card->label().' sudah melewati masa berlakunya ('.$card->expired_date?->toDateString().').'
                            : 'Kartu '.$card->label().' belum diaktifkan di Set Up Debit Card.',
                        errorCode: 'ADV_VA_CARD_UNUSABLE',
                    );
                }
            }

            /* PEMBAYARAN DEBIT VA TIDAK MEMBENTUK JURNAL — dan itu bukan kelalaian.
               Uangnya belum berpindah ke mana pun: ia hanya DISEDIAKAN pada kartu/VA-nya, masih
               di rekening perusahaan sendiri. Yang memindahkannya ke tangan pemegang bon adalah
               ALOKASI KASBON, dan alokasi itulah yang berjurnal (Dr Uang Muka Operasional / Cr
               bank pengeluaran). Menjurnalnya di sini berarti mencatat pengeluaran bank pada
               tanggal transfer VA — padahal banknya baru berkurang saat kartunya dipakai, bisa
               beberapa kali, dari bank yang berbeda pula.

               Karena tidak ada jurnal, tidak ada pula sumber bank/kas yang harus dipilih:
               cash_account_id sengaja dibiarkan NULL untuk bon Debit VA. */
            $journal = null;

            if ($method !== 'debit_va') {
                $journal = $this->journal->post([
                    'journal_date' => $data['paid_date'],
                    'source' => 'cash',
                    'status' => 'posted', // Treasury: pembayaran panjar langsung posting → mutasi bank tercatat di Rekening Ledger.
                    'reference' => $adv->advance_number,
                    'description' => 'Pembayaran Bon Sementara — '.$adv->purpose.' ('.$adv->holder.')',
                    'created_by' => $userId,
                ], [
                    // cost_center_id dibawa KEDUA baris: itulah dimensi yang dibaca Budget Control,
                    // dan jurnal yang hanya separuh berdimensi akan terbaca timpang saat direkap
                    // per cost center.
                    ['account_id' => $advanceAccount, 'cost_center_id' => $adv->cost_center_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Panjar '.$adv->holder],
                    ['account_id' => (int) $data['cash_account_id'], 'cost_center_id' => $adv->cost_center_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Transfer bon sementara '.$adv->advance_number],
                ]);
            }

            $adv->update([
                'cash_account_id' => $method === 'debit_va' ? null : (int) $data['cash_account_id'],
                'advance_account_id' => $advanceAccount,
                'paid_date' => $data['paid_date'],
                'payment_method' => $method,
                'va_expired_date' => $expired,
                'credit_card_id' => $card?->id,
                'status' => 'outstanding',
                'journal_id' => $journal?->id,
            ]);

            /* MASA BERLAKU kartunya diajukan otomatis dari tanggal expired VA yang ditentukan di
               sini — masa berlaku memang keputusan tersendiri dan tetap menunggu persetujuan.

               SALDO-nya TIDAK diajukan lagi. "Penambahan" saldo kartu kini dibaca langsung dari
               pembayaran Debit VA yang tersimpan (lihat CreditCardClosingService), sehingga
               permintaan penambahan otomatis hanya akan menjadi angka kedua untuk uang yang
               sama — menumpuk di antrean persetujuan tanpa pernah berarti apa pun. */
            if ($card) {
                app(CreditCardService::class)->requestMasaBerlakuDariBon(
                    $card, (int) $adv->id, (string) $adv->advance_number, (string) $expired, $userId,
                );
            }

            return $adv;
        });
    }

    /**
     * @param array{realized_date:string, lines:array<int,array{expense_account_id:int, description?:?string, amount:float|int|string}>} $data
     */
    /**
     * Apakah bon ini melewati langkah Alokasi Kasbon (sub-modul Alokasi Bon Sementara)?
     *
     * Pembedanya DATA, bukan payment_method saja: bon Debit VA model LAMA sudah terlanjur
     * berjurnal saat dibayar (journal_id terisi), jadi ia tidak pernah punya alokasi — sama
     * seperti bon reguler. Syarat ini dipakai bersama oleh gerbang realisasi di sini dan oleh
     * daftar sub-modul Alokasi Bon Sementara di controller, supaya keduanya tidak bisa berbeda.
     */
    public function perluLanjutAlokasi(BsCashAdvance $adv): bool
    {
        return $adv->payment_method === 'debit_va' && ! $this->bonKartuLama($adv);
    }

    public function realize(BsCashAdvance $adv, array $data, ?int $userId = null): BsCashAdvance
    {
        return DB::transaction(function () use ($adv, $data, $userId): BsCashAdvance {
            if ($adv->status !== 'outstanding') {
                throw new BusinessRuleException('Bon sementara sudah direalisasi.', errorCode: 'ADV_DONE');
            }
            $lines = collect($data['lines'])->map(fn ($l) => [
                'expense_account_id' => (int) $l['expense_account_id'],
                // Cost center per baris — inilah yang membuat beban bon sementara bisa
                // dipasangkan dengan anggarannya oleh BudgetRealizationService. Tanpa ini,
                // belanja yang nyata terjadi tidak pernah muncul sebagai realisasi anggaran.
                'cost_center_id' => ($l['cost_center_id'] ?? null) ? (int) $l['cost_center_id'] : null,
                'description' => $l['description'] ?? 'Realisasi',
                'amount' => round((float) $l['amount'], 2),
            ])->filter(fn ($l) => $l['amount'] > 0)->values();

            if ($lines->isEmpty()) {
                throw new BusinessRuleException('Rincian realisasi tidak boleh kosong.', errorCode: 'ADV_NO_LINES');
            }
            $realized = round((float) $lines->sum('amount'), 2);

            /* ACUANNYA BUKAN SELALU NOMINAL BON. Bon Debit VA model baru mendebit Uang Muka
               Operasional lewat ALOKASI KASBON, jadi yang bisa direalisasi & diselesaikan adalah
               sebesar total alokasinya. Memakai nominal bon akan menghitung selisih terhadap
               uang yang belum pernah keluar dari bank. */
            $acuan = $this->acuanPanjar($adv);
            $settlement = round($acuan - $realized, 2); // + refund (kembali) / - reimburse (nombok)
            $defer = (bool) ($data['defer_settlement'] ?? false);

            /* Bon Debit VA yang belum dialokasikan sama sekali belum menaruh sepeser pun di Uang
               Muka Operasional. Merealisasinya akan mengkredit akun yang tidak pernah didebit —
               akunnya langsung minus, dan tidak ada galat apa pun yang memberi tahu. */
            if ($acuan <= 0 && $adv->payment_method === 'debit_va' && ! $this->bonKartuLama($adv)) {
                throw new BusinessRuleException(
                    'Bon Debit VA ini belum punya Alokasi Kasbon — isi alokasinya dulu, sebab Uang Muka Operasional baru terbentuk dari alokasi.',
                    errorCode: 'ADV_NO_ALLOCATION',
                );
            }

            /* GERBANG "LANJUT REALISASIKAN BON".
               Alokasi Kasbon berdiri sebagai sub-modul sendiri, dan bon hanya boleh sampai ke
               realisasi setelah tombol "Lanjut Realisasikan Bon" ditekan di sana.

               Gerbangnya dipasang DI SINI, bukan di controller: sebelas tempat memanggil
               realize() langsung tanpa melewati controller, dan gerbang di controller akan
               dilewati semuanya tanpa satu pun tanda.

               Cakupannya SENGAJA SEMPIT — hanya bon yang memang punya langkah alokasi (Debit VA
               model baru). Bon reguler dan bon kartu model lama tidak pernah punya Alokasi
               Kasbon; menggerbangi mereka akan mengunci mati seluruh bon yang alur alokasinya
               memang tidak ada. */
            if ($this->perluLanjutAlokasi($adv) && $adv->allocation_done_at === null) {
                throw new BusinessRuleException(
                    'Bon ini belum dilanjutkan dari Alokasi Kasbon. Buka Cash Book → Transaksi Bon Sementara → '
                    .'Alokasi Bon Sementara, periksa alokasinya, lalu tekan "Lanjut Realisasikan Bon".',
                    errorCode: 'ADV_ALLOCATION_NOT_CONFIRMED',
                );
            }

            // Jurnal realisasi.
            $jl = [];
            foreach ($lines as $l) {
                // cost_center_id diteruskan ke bs_journal_lines — di situlah Budget Control
                // membacanya; menyimpannya hanya di baris bon tidak akan berpengaruh apa pun.
                $jl[] = ['account_id' => $l['expense_account_id'], 'cost_center_id' => $l['cost_center_id'],
                    'debit' => $l['amount'], 'credit' => 0, 'description' => $l['description']];
            }
            /* AKUN YANG DIKREDIT MENGIKUTI KE MANA UANGNYA DITARUH SAAT DIBAYAR.
               Bon Debit VA mendebit REKENING KARTUNYA saat pembayaran (mis. MANDIRI REK.
               141-00-9988886-9), bukan Uang Muka Operasional. Karena itu realisasinya harus
               mengkredit akun yang sama — mengkredit Uang Muka akan meninggalkan saldo di akun
               kartu yang tidak pernah bersih, sekaligus membuat Uang Muka minus atas panjar
               yang tidak pernah dicatat di sana.

               SISA SALDO KARTU muncul dengan sendirinya dari selisih ini: pengajuan mendebit
               akun kartu sebesar nominal bon, realisasi mengkreditnya sebesar yang terpakai,
               dan bakinya = saldo yang masih tersimpan di kartu itu. */
            $akunPanjar = $this->akunPanjar($adv);
            /* Sebutannya mengikuti AKUN yang benar-benar dikredit, bukan sekadar "bon ini
               berkartu". Bon Debit VA model baru mengkredit Uang Muka Operasional (uangnya
               dibawa ke sana oleh Alokasi Kasbon), jadi menuliskan "kartu/VA" pada keterangan
               jurnalnya akan menunjuk akun yang tidak tersentuh baris itu. */
            $ketPanjar = $this->bonKartuLama($adv) ? 'kartu/VA' : 'panjar';

            /* Baris LAWAN realisasi (pelepasan panjar & selisih kasnya) memakai cost center BON.
               Baris bebannya sendiri sudah membawa cost center per baris dari layar realisasi —
               yang itu boleh berbeda, sebab satu bon bisa dibelanjakan untuk beberapa cost
               center. Yang tidak boleh adalah baris lawannya tanpa dimensi sama sekali: jurnal
               yang hanya separuh berdimensi terbaca timpang saat direkap per cost center. */
            $ccBon = $adv->cost_center_id;

            if ($defer) {
                // Settlement ditunda → kredit sebatas realisasi; sisa (± settlement) diselesaikan via Refund/Reimburse.
                $jl[] = ['account_id' => $akunPanjar, 'cost_center_id' => $ccBon, 'debit' => 0, 'credit' => $realized, 'description' => 'Realisasi '.$ketPanjar.' (settlement ditunda)'];
            } else {
                // Settlement langsung (perilaku lama): lunasi penuh + kas masuk/keluar selisih.
                $jl[] = ['account_id' => $akunPanjar, 'cost_center_id' => $ccBon, 'debit' => 0, 'credit' => $acuan, 'description' => 'Pelunasan '.$ketPanjar];
                if ($settlement > 0) {
                    $jl[] = ['account_id' => $adv->cash_account_id, 'cost_center_id' => $ccBon, 'debit' => $settlement, 'credit' => 0, 'description' => 'Pengembalian sisa panjar'];
                } elseif ($settlement < 0) {
                    $jl[] = ['account_id' => $adv->cash_account_id, 'cost_center_id' => $ccBon, 'debit' => 0, 'credit' => abs($settlement), 'description' => 'Penggantian kekurangan panjar'];
                }
            }

            $journal = $this->journal->post([
                'journal_date' => $data['realized_date'],
                'source' => 'cash',
                'status' => 'posted', // Treasury: realisasi panjar langsung posting → selisih kas & Uang Muka Op tercatat di ledger.
                'reference' => $adv->advance_number,
                'description' => 'Realisasi Bon Sementara '.$adv->advance_number,
                'created_by' => $userId,
            ], $jl);

            foreach ($lines as $l) {
                $adv->lines()->create($l);
            }
            $adv->update([
                'status' => 'realized',
                'realized_date' => $data['realized_date'],
                'realized_amount' => $realized,
                'settlement' => $settlement,
                'settlement_status' => $defer && abs($settlement) >= 0.01 ? 'pending' : 'settled',
                'realize_journal_id' => $journal->id,
            ]);

            return $adv;
        });
    }

    /** Refund — sisa panjar dikembalikan pemegang ke kas/bank: Dr Kas / Cr Uang Muka.
     *  #89: bank PENERIMA dana refund bisa dipilih (default = bank sumber pembayaran). */
    public function refund(BsCashAdvance $adv, string $date, ?int $userId = null, ?int $cashAccountId = null): BsCashAdvance
    {
        return $this->settle($adv, $date, 'refund', $userId, $cashAccountId);
    }

    /** Reimburse — perusahaan mengganti kekurangan ke pemegang dari kas/bank: Dr Uang Muka / Cr Kas.
     *  #93: bank SUMBER dana reimburse bisa dipilih (default = bank sumber pembayaran). */
    public function reimburse(BsCashAdvance $adv, string $date, ?int $userId = null, ?int $cashAccountId = null): BsCashAdvance
    {
        return $this->settle($adv, $date, 'reimburse', $userId, $cashAccountId);
    }

    /**
     * MASUK SALDO — sisa panjar bon Debit VA dikembalikan ke SALDO KARTUNYA, bukan ke kas.
     *
     * TIDAK MEMBENTUK JURNAL, dan itu bukan kelalaian melainkan justru yang benar: uangnya
     * memang belum berpindah ke mana pun. Pembayaran Debit VA sudah MENDEBIT akun kartu sebesar
     * nominal bon, realisasinya MENGKREDIT sebesar yang terpakai — jadi sisanya MASIH BERADA di
     * akun kartu itu di buku besar. Refund memindahkannya kembali ke bank, karena itu ia
     * berjurnal; Masuk Saldo membiarkannya tetap di kartu dan hanya membuka kembali
     * pemakaiannya. Menjurnalnya lagi berarti mencatat perpindahan yang tidak terjadi, dan akun
     * kartunya akan terkredit DUA KALI untuk uang yang sama.
     *
     * Yang berubah hanyalah saldo terpakai kartunya (lewat permintaan yang langsung disetujui,
     * sehingga jejaknya tetap ada) dan status penyelesaian bonnya.
     */
    public function masukSaldo(BsCashAdvance $adv, ?int $userId = null): BsCashAdvance
    {
        return DB::transaction(function () use ($adv, $userId): BsCashAdvance {
            if ($adv->settlement_status !== 'pending') {
                throw new BusinessRuleException('Tidak ada sisa panjar yang perlu diselesaikan.', errorCode: 'ADV_NOT_PENDING');
            }
            // Hanya masuk akal untuk bon Debit VA: bon reguler tidak punya kartu yang bisa
            // menampung sisanya, dan uangnya memang sudah berada di Uang Muka Operasional.
            if ($adv->payment_method !== 'debit_va' || ! $adv->credit_card_id) {
                throw new BusinessRuleException(
                    'Masuk Saldo hanya untuk bon yang dibayar lewat Debit VA — sisanya dikembalikan ke kartu yang dipakai.',
                    errorCode: 'ADV_NOT_DEBIT_VA',
                );
            }

            /* HANYA UNTUK BON MODEL LAMA. Alasan Masuk Saldo tidak berjurnal adalah "uangnya
               memang masih di akun kartu" — dan itu hanya benar bila pembayarannya dulu memang
               mendebit akun kartu. Pada bon model baru, sisa realisasinya berada di UANG MUKA
               OPERASIONAL (dibawa ke sana oleh Alokasi Kasbon), bukan di kartu; memindahkannya
               ke saldo kartu tanpa jurnal akan meninggalkan Uang Muka Operasional yang tidak
               pernah bersih. Sisa bon model baru diselesaikan lewat Refund/Reimburse, sedangkan
               bagian bon yang MEMANG belum dialokasikan tetap menjadi sisa saldo kartunya dengan
               sendirinya — tanpa perlu tombol apa pun. */
            if (! $this->bonKartuLama($adv)) {
                throw new BusinessRuleException(
                    'Bon Debit VA ini tidak memakai Masuk Saldo. Sisa realisasinya berada di Uang Muka Operasional — '
                    .'selesaikan lewat Refund. Bagian bon yang belum dialokasikan otomatis tetap menjadi sisa saldo kartu.',
                    errorCode: 'ADV_MASUK_SALDO_OBSOLETE',
                );
            }
            $settlement = round((float) $adv->settlement, 2);
            if ($settlement <= 0) {
                throw new BusinessRuleException(
                    'Masuk Saldo hanya untuk sisa panjar berlebih (panjar > realisasi).',
                    errorCode: 'ADV_NOT_REFUND',
                );
            }

            $card = BsCreditCard::whereKey($adv->credit_card_id)->first();
            if (! $card) {
                throw new BusinessRuleException('Kartu debit/VA bon ini tidak ditemukan.', errorCode: 'ADV_VA_NO_CARD');
            }

            app(CreditCardService::class)->masukSaldoDariBon(
                $card, (int) $adv->id, (string) $adv->advance_number, $settlement, $userId,
            );

            // Status tersendiri, BUKAN 'settled': keduanya sama-sama "sisa sudah beres", tetapi
            // hanya satu di antaranya yang meninggalkan jurnal. Menyamakannya membuat layar
            // menjanjikan bukti jurnal yang memang tidak pernah ada.
            $adv->update(['settlement_status' => 'card']);

            return $adv;
        });
    }

    private function settle(BsCashAdvance $adv, string $date, string $mode, ?int $userId, ?int $cashAccountId = null): BsCashAdvance
    {
        return DB::transaction(function () use ($adv, $date, $mode, $userId, $cashAccountId): BsCashAdvance {
            if ($adv->settlement_status !== 'pending') {
                throw new BusinessRuleException('Tidak ada sisa panjar yang perlu diselesaikan.', errorCode: 'ADV_NOT_PENDING');
            }
            $settlement = round((float) $adv->settlement, 2);
            if ($mode === 'refund' && $settlement <= 0) {
                throw new BusinessRuleException('Refund hanya untuk sisa panjar berlebih (panjar > realisasi).', errorCode: 'ADV_NOT_REFUND');
            }
            if ($mode === 'reimburse' && $settlement >= 0) {
                throw new BusinessRuleException('Reimburse hanya untuk kekurangan (realisasi > panjar).', errorCode: 'ADV_NOT_REIMBURSE');
            }
            $amount = abs($settlement);

            /* #89/#93: bank yang dipilih user (penerima refund / sumber reimburse).
               Cadangannya berlapis, sebab bon Debit VA model baru TIDAK punya bank sumber bayar
               sama sekali (pembayarannya tidak berjurnal): pakai bank pengeluaran ALOKASI
               terakhir — ke situlah uangnya keluar, jadi ke situ pula paling masuk akal ia
               kembali. Barulah bila keduanya kosong, penggunanya diminta memilih. */
            $cashAccount = $cashAccountId ?: $adv->cash_account_id ?: BsCashAdvanceAllocation::where('cash_advance_id', $adv->id)
                ->orderByDesc('alloc_date')->orderByDesc('id')->value('cash_account_id');
            if (! $cashAccount) {
                throw new BusinessRuleException('Pilih bank/kas untuk penyelesaian panjar.', errorCode: 'ADV_NO_CASH');
            }

            // Sepola dengan realize(): panjar bon Debit VA tersimpan di rekening KARTUNYA,
            // jadi penyelesaiannya juga menyentuh akun itu — bukan Uang Muka Operasional yang
            // tidak pernah menerima panjarnya.
            $akunPanjar = $this->akunPanjar($adv);

            // Cost center bon ikut pada refund/reimburse juga — penyelesaian panjar adalah bagian
            // dari belanja bon yang sama, jadi ia harus terbaca pada cost center yang sama pula.
            $ccBon = $adv->cost_center_id;

            $jl = $mode === 'refund'
                ? [
                    ['account_id' => $cashAccount, 'cost_center_id' => $ccBon, 'debit' => $amount, 'credit' => 0, 'description' => 'Pengembalian sisa panjar '.$adv->holder],
                    ['account_id' => $akunPanjar, 'cost_center_id' => $ccBon, 'debit' => 0, 'credit' => $amount, 'description' => 'Penyelesaian panjar'],
                ]
                : [
                    ['account_id' => $akunPanjar, 'cost_center_id' => $ccBon, 'debit' => $amount, 'credit' => 0, 'description' => 'Penyelesaian panjar'],
                    ['account_id' => $cashAccount, 'cost_center_id' => $ccBon, 'debit' => 0, 'credit' => $amount, 'description' => 'Penggantian kekurangan panjar '.$adv->holder],
                ];

            $journal = $this->journal->post([
                'journal_date' => $date,
                'source' => 'cash',
                'status' => 'posted', // Treasury: refund/reimburse panjar langsung posting → mutasi bank tercatat di Rekening Ledger.
                'reference' => $adv->advance_number,
                'description' => ($mode === 'refund' ? 'Refund' : 'Reimburse').' Bon Sementara '.$adv->advance_number,
                'created_by' => $userId,
            ], $jl);

            $adv->update(['settlement_status' => 'settled', 'settle_journal_id' => $journal->id]);

            return $adv;
        });
    }

    private function nextNumber(string $date): string
    {
        return app(BoNumberService::class)
            ->next('BON', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d = Carbon::parse($date);
        $count = BsCashAdvance::withTrashed()->whereYear('advance_date', $d->year)->count() + 1;

        return sprintf('BON/%s/%04d', $d->format('y').$d->format('m'), $count);
    }
}
