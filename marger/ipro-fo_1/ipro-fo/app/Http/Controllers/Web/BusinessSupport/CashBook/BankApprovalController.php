<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\BankRetentionTerm;
use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BankLoan\Models\KprRetentionRelease;
use App\Domain\BusinessSupport\Actions\ConvertUtjDpToUangMuka;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\Finance\Bank\Models\BankAccount;
use App\Domain\Finance\Tts\Models\Tts;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transaksi Bank — Approval Retensi (sumber: retensi pencairan KPR) &
 * Approval UTJ & DP (sumber: TTS yang sudah diposting ke Kuitansi).
 * Approver memilih akun Debit & Kredit; saat di-approve jurnal GL terposting.
 */
class BankApprovalController extends Controller
{
    /** PPN inklusif standar Indonesia 11% (PPN = nominal * 11/111). */
    private const PPN_RATE_NUM = 11;
    private const PPN_RATE_DEN = 111;

    private function postableAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->orderBy('code')->get(['id', 'code', 'name', 'type']);
    }

    /**
     * #147 — Resolusi akun kredit default untuk Approval Retensi:
     *  - "Uang Muka Penjualan" & "PPN Keluaran" dicari dari akun postable berdasarkan NAMA
     *    (case-insensitive, mengandung frasa). Bila tak ketemu, fallback ke kode di
     *    config('business_support.gl_accounts.*') agar tetap konfigurabel.
     *
     *  - "Piutang Usaha" dipakai saat unit SUDAH Booked Revenue (lihat buildApprovalLines()).
     *
     * @param  \Illuminate\Support\Collection<int,\App\Domain\BusinessSupport\Models\BsAccount>  $accounts
     * @return array{uang_muka_account_id:?int, uang_muka_account:?string, ppn_keluaran_account_id:?int, ppn_keluaran_account:?string, piutang_account_id:?int, piutang_account:?string, ppn_rate_num:int, ppn_rate_den:int}
     */
    private function retensiAccountDefaults(Collection $accounts): array
    {
        $byName = function (string $needle, string $configKey) use ($accounts): ?BsAccount {
            $needle = mb_strtolower($needle);
            $hit = $accounts->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), $needle));
            if ($hit) {
                return $hit;
            }
            $code = config("business_support.gl_accounts.{$configKey}");

            return $code ? $accounts->firstWhere('code', $code) : null;
        };

        $uangMuka = $byName('uang muka penjualan', 'unearned_revenue');
        $ppnOut   = $byName('ppn keluaran', 'ppn_out');
        $piutang  = $byName('piutang usaha', 'receivable');

        return [
            'uang_muka_account_id'    => $uangMuka?->id,
            'uang_muka_account'       => $uangMuka ? $uangMuka->code.' - '.$uangMuka->name : null,
            'ppn_keluaran_account_id' => $ppnOut?->id,
            'ppn_keluaran_account'    => $ppnOut ? $ppnOut->code.' - '.$ppnOut->name : null,
            'piutang_account_id'      => $piutang?->id,
            'piutang_account'         => $piutang ? $piutang->code.' - '.$piutang->name : null,
            'ppn_rate_num'            => self::PPN_RATE_NUM,
            'ppn_rate_den'            => self::PPN_RATE_DEN,
        ];
    }

    // ── Booked Revenue & jalur pencairan (BSN / SELAIN BSN) ───────────────
    //
    // Sumber aturan: "Jurnal Saat Retensi dan Booked Revenue.xlsx" (sheet BOOKED SELAIN BSN &
    // BOOKED BSN). Saat Approval Retensi/Pencairan, bentuk jurnal ditentukan oleh posisi
    // tanggal jurnal terhadap tanggal Booked Revenue unit ybs:
    //
    //   SEBELUM Booked Revenue → Dr Bank / Cr Uang Muka Penjualan (nilai/1,11) + Cr PPN Keluaran (11/111)
    //   SESUDAH Booked Revenue → Dr Bank / Cr Piutang Usaha (nilai penuh, TANPA pecah PPN — PPN sudah
    //                            diakui seluruhnya pada jurnal Booked Revenue)
    //
    // Beda BSN vs SELAIN BSN bukan pada rumusnya, melainkan pada TITIK Booked Revenue-nya:
    // SELAIN BSN booked setelah Topping Off, BSN setelah BAST+AJB — sehingga pada BSN lebih banyak
    // tahap yang masih memakai Uang Muka + PPN. Khusus BSN ada tahap DAJAM PAJAK (potongan) yang
    // jurnalnya KEBALIKAN: Dr Uang Muka Penjualan / Cr Bank.

    /** Mode kredit yang dikenal Approval Retensi/Pencairan. */
    private const CREDIT_MODES = ['split', 'piutang', 'deduct', 'manual'];

    /**
     * Booked Revenue TERBARU (non-batal) per unit — sekali query untuk semua baris.
     *
     * @param  \Illuminate\Support\Collection<int,int>  $unitIds
     * @return \Illuminate\Support\Collection<int,\App\Domain\BusinessSupport\Models\BsBookedRevenue>
     */
    private function bookedRevenueMap(Collection $unitIds): Collection
    {
        $ids = $unitIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        // Urut MENAIK lalu keyBy → yang tersimpan adalah baris terakhir (paling baru) per unit.
        return BsBookedRevenue::whereIn('unit_id', $ids->all())
            ->where('status', '!=', 'cancelled')
            ->orderBy('booking_date')->orderBy('id')
            ->get(['id', 'unit_id', 'br_number', 'booking_date', 'receivable_account_id'])
            ->keyBy('unit_id');
    }

    /** Booked Revenue (non-batal) milik satu unit — dipakai saat posting. */
    private function bookedRevenueOfUnit(?int $unitId): ?BsBookedRevenue
    {
        return $unitId
            ? $this->bookedRevenueMap(collect([$unitId]))->get($unitId)
            : null;
    }

    /**
     * Apakah pada tanggal jurnal tsb unit SUDAH Booked Revenue?
     * Dibandingkan terhadap TANGGAL BOOKING, bukan sekadar "ada/tidak ada record", agar baris lama
     * (retensi yang terjadi sebelum booking dibuat) tetap dijurnal dengan bentuk yang benar.
     */
    private function isBookedOn(?BsBookedRevenue $br, ?string $journalDate): bool
    {
        $bookedAt = $br?->booking_date?->toDateString();

        return $bookedAt !== null && $journalDate !== null && $journalDate !== '' && $journalDate >= $bookedAt;
    }

    /** Jalur pencairan: 'bsn' | 'non_bsn' | null. Disimpan saat Catat Pencairan; fallback tebak dari nama bank. */
    private function schemeOf(?KprRealization $real, ?BankAccount $bank): ?string
    {
        $scheme = $real?->disbursement_scheme;
        if ($scheme === 'bsn' || $scheme === 'non_bsn') {
            return $scheme;
        }

        $name = mb_strtolower(trim((string) ($bank?->bank_name ?? '')));
        if ($name === '') {
            return null;
        }

        // BSN = Bank Syariah Nasional / BTN Syariah.
        return (str_contains($name, 'bsn') || str_contains($name, 'syariah')) ? 'bsn' : 'non_bsn';
    }

    /** Tahap DAJAM PAJAK (SSP/BPHTB) — pada jalur BSN jurnalnya potongan (dibalik). */
    private function isDajamPajak(?string $categoryCode, ?string $categoryName, ?string $note): bool
    {
        $hay = mb_strtolower(trim((string) $categoryCode.' '.(string) $categoryName.' '.(string) $note));

        return str_contains($hay, 'dajam') && str_contains($hay, 'pajak');
    }

    /** Label mode kredit untuk pesan sukses. */
    private function creditModeLabel(string $mode): string
    {
        return match ($mode) {
            'piutang' => 'sudah Booked Revenue → kredit Piutang Usaha',
            'deduct'  => 'retensi DAJAM ditahan (uang keluar) → Dr Uang Muka / Cr Bank',
            'manual'  => 'kredit tunggal manual',
            default   => 'belum Booked Revenue → kredit Uang Muka + PPN',
        };
    }

    /**
     * Mode kredit default untuk sebuah baris approval.
     *
     * BARIS RETENSI DAJAM (penahanan) SELALU 'deduct'. Retensi berarti uang KELUAR dari
     * rekening kita dan ditahan sebagai uang muka pelanggan, jadi jurnalnya kebalikan
     * pencairan: Dr Uang Muka Penjualan / Cr Bank sebesar NILAI PENUH — tanpa pecahan PPN.
     * PPN baru diakui saat porsinya DILEPAS (lihat baris kind='release'), bukan saat ditahan;
     * memecah PPN di sini membuat PPN diakui atas uang yang belum jadi hak perusahaan.
     *
     * Sebelumnya 'deduct' hanya dipakai bila kategorinya kebetulan bernama "DAJAM … PAJAK"
     * pada jalur BSN — kategori seperti "TANAH MATANG 50%" lolos ke 'split' sehingga
     * penahanan tercatat sebagai uang MASUK. Itulah jurnal keliru yang dilaporkan.
     */
    private function defaultCreditMode(bool $booked, ?string $scheme, bool $isDajamPajak, string $kind = ''): string
    {
        if ($kind === 'retensi') {
            // RETENSI DAJAM TIDAK TERPENGARUH BOOKED REVENUE — booked/belum, jurnalnya sama:
            // Dr Uang Muka Penjualan / Cr Bank, nilai penuh, tanpa PPN.
            // Yang dipengaruhi Booked Revenue hanya baris SELAIN retensi DAJAM (pencairan).
            // PPN baru muncul saat porsinya DILEPAS (baris release, mode 'split' 11/111).
            return 'deduct';
        }
        // PELEPASAN DAJAM (bertahap maupun sekaligus) — uang masuk kembali:
        //   SEBELUM Booked Revenue → Dr Bank / Cr Uang Muka Penjualan + Cr PPN Keluaran (11/111)
        //   SESUDAH Booked Revenue → Dr Bank / Cr Piutang Usaha (tanpa PPN; PPN sudah diakui
        //                             seluruhnya pada jurnal Booked Revenue)
        if ($kind === 'release') {
            return $booked ? 'piutang' : 'split';
        }
        if ($booked) {
            return 'piutang';
        }

        return ($scheme === 'bsn' && $isDajamPajak) ? 'deduct' : 'split';
    }

    /**
     * Susun baris jurnal approval (retensi/pencairan) sesuai mode kredit & status Booked Revenue.
     *
     * @param  array<string,mixed>  $v  hasil validate()
     * @return array{error:string}|array{lines:array<int,array<string,mixed>>, mode:string, dpp:float, ppn:float, faktur:bool}
     */
    private function buildApprovalLines(array $v, float $amount, bool $booked, string $suffix, string $desc, string $singleDesc): array
    {
        $amount = round($amount, 2);
        $bankId = (int) $v['debit_account_id'];
        $umId   = (int) ($v['uang_muka_account_id'] ?? 0);
        $ppnId  = (int) ($v['ppn_account_id'] ?? 0);
        $umAmt  = round((float) ($v['uang_muka_amount'] ?? 0), 2);
        $ppnAmt = round((float) ($v['ppn_amount'] ?? 0), 2);
        $crId   = (int) ($v['credit_account_id'] ?? 0);

        // Kompatibilitas request lama (tanpa credit_mode): simpulkan dari isian yang dikirim.
        $mode = $v['credit_mode'] ?? null;
        if (! in_array($mode, self::CREDIT_MODES, true)) {
            $mode = ($umAmt > 0 || $ppnAmt > 0) ? 'split' : ($crId > 0 ? 'manual' : 'split');
        }

        // Rambu akuntansi — mencegah PPN dobel & piutang yang belum lahir.
        if ($mode === 'split' && $booked) {
            return ['error' => 'Unit ini SUDAH Booked Revenue: kredit harus ke Piutang Usaha sebesar nilai penuh. Pecah Uang Muka + PPN hanya untuk yang BELUM Booked Revenue (PPN sudah diakui seluruhnya pada jurnal Booked Revenue).'];
        }
        if ($mode === 'piutang' && ! $booked) {
            return ['error' => 'Unit ini BELUM Booked Revenue: kredit harus dipecah Uang Muka Penjualan + PPN Keluaran (11/111), bukan Piutang Usaha.'];
        }
        // RETENSI / POTONGAN DAJAM — jurnal KEBALIKAN: Dr Uang Muka Penjualan / Cr Bank,
        // sebesar NILAI PENUH tanpa pecahan PPN (uang keluar & ditahan).
        //
        // Larangan "hanya sebelum Booked Revenue" DICABUT: penahanan DAJAM tetap uang keluar
        // walau pendapatannya sudah diakui, jadi memaksanya jadi Dr Bank justru membalik arah
        // kas yang sebenarnya.
        if ($mode === 'deduct') {
            if ($umId <= 0) {
                return ['error' => 'Pilih akun Uang Muka Penjualan untuk retensi/potongan DAJAM.'];
            }
            if ($umId === $bankId) {
                return ['error' => 'Akun Uang Muka Penjualan tidak boleh sama dengan akun Bank.'];
            }

            return [
                'lines' => [
                    ['account_id' => $umId,   'debit' => $amount, 'credit' => 0,       'description' => 'Retensi DAJAM ditahan — '.$desc],
                    ['account_id' => $bankId, 'debit' => 0,       'credit' => $amount, 'description' => 'Retensi DAJAM ditahan — '.$desc],
                ],
                'mode' => $mode, 'dpp' => 0.0, 'ppn' => 0.0, 'faktur' => false,
            ];
        }

        // Sisanya: Dr Bank / Cr ... sebesar nilai baris.
        $creditLines = [];
        if ($mode === 'split') {
            foreach ([[$umId, $umAmt, 'Uang Muka Penjualan'.$suffix], [$ppnId, $ppnAmt, 'PPN Keluaran'.$suffix]] as [$accId, $amt, $lineDesc]) {
                if ($accId > 0 && $amt > 0) {
                    $creditLines[] = ['account_id' => $accId, 'amount' => $amt, 'description' => $lineDesc];
                }
            }
            if (! $creditLines) {
                return ['error' => 'Tentukan akun & nominal Uang Muka Penjualan / PPN Keluaran.'];
            }
        } else { // 'piutang' | 'manual' — kredit tunggal sebesar nilai penuh.
            if ($crId <= 0) {
                return ['error' => $mode === 'piutang'
                    ? 'Pilih akun Piutang Usaha untuk kredit.'
                    : 'Tentukan minimal satu akun & nominal kredit (Uang Muka / PPN, atau akun kredit tunggal).'];
            }
            $creditLines[] = ['account_id' => $crId, 'amount' => $amount, 'description' => ($mode === 'piutang' ? 'Pelunasan Piutang Usaha'.$suffix : $singleDesc)];
        }

        $totalCredit = round(array_sum(array_column($creditLines, 'amount')), 2);
        if ($totalCredit !== $amount) {
            return ['error' => 'Total kredit ('.number_format($totalCredit, 2).') harus sama dengan nilai ('.number_format($amount, 2).').'];
        }
        foreach ($creditLines as $cl) {
            if ($bankId === $cl['account_id']) {
                return ['error' => 'Akun Debit tidak boleh sama dengan akun Kredit.'];
            }
        }

        $lines = [['account_id' => $bankId, 'debit' => $amount, 'credit' => 0, 'description' => $desc]];
        foreach ($creditLines as $cl) {
            $lines[] = ['account_id' => $cl['account_id'], 'debit' => 0, 'credit' => $cl['amount'], 'description' => $cl['description']];
        }

        // Faktur Pajak Keluaran hanya untuk penerimaan yang MEMANG memicu PPN (sebelum Booked
        // Revenue). Setelah Booked Revenue penerimaan = pelunasan piutang; fakturnya sudah terbit
        // di jurnal Booked Revenue, jadi tidak dibuat entri baru agar tidak dobel lapor.
        return [
            'lines'  => $lines,
            'mode'   => $mode,
            'dpp'    => $mode === 'split' ? $umAmt : ($mode === 'manual' ? $amount : 0.0),
            'ppn'    => $mode === 'split' ? $ppnAmt : 0.0,
            'faktur' => $mode !== 'piutang',
        ];
    }

    /**
     * #147 — Petakan rekening bank tempat akad kredit (kpr_realizations.bank_account_id)
     * ke akun GL Bank yang cocok. Strategi pencocokan (paling andal → fallback):
     *   1) nomor rekening bank muncul di NAMA akun GL,
     *   2) nama bank muncul di NAMA akun GL (di antara akun aset bertema kas/bank),
     *   3) fallback ke akun bank perusahaan default config('...gl_accounts.cash').
     * Hasil tetap EDITABLE oleh approver di UI.
     *
     * @param  \Illuminate\Support\Collection<int,\App\Domain\BusinessSupport\Models\BsAccount>  $accounts
     */
    private function mapBankAccountToGl(?BankAccount $bank, Collection $accounts): ?BsAccount
    {
        if ($bank) {
            $num = preg_replace('/\D+/', '', (string) $bank->account_number);
            if ($num !== null && $num !== '') {
                $hit = $accounts->first(fn ($a) => str_contains(preg_replace('/\D+/', '', (string) $a->name) ?? '', $num));
                if ($hit) {
                    return $hit;
                }
            }

            $bankName = mb_strtolower(trim((string) $bank->bank_name));
            if ($bankName !== '') {
                // Prioritaskan akun aset bertema kas/bank agar tidak salah pasang.
                $candidates = $accounts->filter(fn ($a) => $a->type === 'asset'
                    && (str_contains(mb_strtolower((string) $a->name), 'bank') || str_contains(mb_strtolower((string) $a->name), 'kas')));
                $pool = $candidates->isNotEmpty() ? $candidates : $accounts;
                $hit = $pool->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), $bankName));
                if ($hit) {
                    return $hit;
                }
            }
        }

        $cashCode = config('business_support.gl_accounts.cash');

        return $cashCode ? $accounts->firstWhere('code', $cashCode) : null;
    }

    /**
     * COA Bank yang di-link-kan pada Set Up KPR → Kategori Bank (tab Retensi).
     *
     * Dipetakan per (bank_partner_id, retention_category_id). Selama ini akun Debit hanya
     * DITEBAK dari nomor/nama rekening (mapBankAccountToGl()); dengan tautan ini akun Debit
     * di Approval Retensi/Pencairan sudah pasti mengikuti setup banknya.
     *
     * @param  \Illuminate\Support\Collection<int,int|null>  $bankPartnerIds
     * @return array{byPair: array<string,int>, byBank: array<int,int>}
     */
    private function bankTermAccountMap(Collection $bankPartnerIds): array
    {
        $ids = $bankPartnerIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return ['byPair' => [], 'byBank' => []];
        }

        $terms = BankRetentionTerm::whereIn('bank_partner_id', $ids->all())
            ->whereNotNull('gl_account_id')
            ->get(['bank_partner_id', 'retention_category_id', 'gl_account_id']);

        $byPair = [];
        foreach ($terms as $t) {
            $byPair[$t->bank_partner_id.'|'.$t->retention_category_id] = (int) $t->gl_account_id;
        }

        // Cadangan per bank untuk baris TANPA kategori (mis. pencairan umum): hanya dipakai bila
        // SELURUH kategori bank itu menunjuk COA yang sama — kalau berbeda-beda, menebak salah
        // satunya justru menyesatkan, jadi biarkan jatuh ke pemetaan nama/nomor rekening.
        $byBank = [];
        foreach ($terms->groupBy('bank_partner_id') as $bankId => $group) {
            $distinct = $group->pluck('gl_account_id')->unique();
            if ($distinct->count() === 1) {
                $byBank[(int) $bankId] = (int) $distinct->first();
            }
        }

        return ['byPair' => $byPair, 'byBank' => $byBank];
    }

    /** @param  array{byPair: array<string,int>, byBank: array<int,int>}  $map */
    private function bankTermAccountFor(array $map, ?int $bankPartnerId, ?int $categoryId): ?int
    {
        if (! $bankPartnerId) {
            return null;
        }

        return $map['byPair'][$bankPartnerId.'|'.$categoryId] ?? $map['byBank'][$bankPartnerId] ?? null;
    }

    private function postJournal(Request $request, JournalService $svc, array $v, float $amount, string $ref, string $desc): \App\Domain\BusinessSupport\Models\BsJournal
    {
        return $svc->post([
            'journal_date' => $v['journal_date'],
            'source'       => 'cash',
            'status'       => 'posted',
            'reference'    => $ref,
            'description'  => $desc,
            'created_by'   => $request->user()->id,
        ], [
            ['account_id' => (int) $v['debit_account_id'],  'debit' => $amount, 'credit' => 0,       'description' => $desc],
            ['account_id' => (int) $v['credit_account_id'], 'debit' => 0,       'credit' => $amount, 'description' => $desc],
        ]);
    }

    private function validateJournalInput(Request $request): array
    {
        return $request->validate([
            'journal_date'      => ['required', 'date'],
            'debit_account_id'  => ['required', 'integer', 'exists:bs_accounts,id', 'different:credit_account_id'],
            'credit_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);
    }

    /** Sufiks keterangan baris jurnal: " · {customer} · Unit {unit_code}" (hanya bagian yang tersedia). */
    private function descSuffix(?string $customer, ?string $unitCode): string
    {
        $parts = [];
        if ($customer !== null && trim($customer) !== '') {
            $parts[] = trim($customer);
        }
        if ($unitCode !== null && trim($unitCode) !== '') {
            $parts[] = 'Unit '.trim($unitCode);
        }

        return $parts ? ' · '.implode(' · ', $parts) : '';
    }

    /**
     * Catat/segarkan entri Faktur Pajak Keluaran dari approval (retensi/utj_dp).
     * Idempoten via (source_type, source_id) — muncul di Verifikasi Faktur Pajak (PPN Keluaran).
     *  - retensi → langsung aktif (boleh isi nomor FP).
     *  - utj_dp  → belum aktif; aktif setelah "Konversi ke Uang Muka Penjualan".
     */
    private function recordFakturKeluaranEntry(Request $request, string $type, int $sourceId, ?int $customerId, ?int $bookingId, string $docDate, float $dpp, float $ppn, float $total, int $journalId, bool $active, ?string $keterangan = null): void
    {
        BsFakturKeluaranEntry::updateOrCreate(
            ['source_type' => $type, 'source_id' => $sourceId],
            [
                // $keterangan diisi pemanggil bila asalnya perlu disebut (mis. dari Approval DP).
                'keterangan'  => $keterangan ?? match ($type) {
                    'retensi'         => 'Retensi',
                    'retensi_release' => 'Pelepasan Retensi',
                    'pencairan'       => 'Pencairan KPR',
                    default           => 'UTJ & DP',
                },
                'customer_id' => $customerId,
                'booking_id'  => $bookingId,
                'doc_date'    => $docDate,
                'dpp'         => round($dpp, 2),
                'ppn'         => round($ppn, 2),
                'total'       => round($total, 2),
                'is_active'   => $active,
                'journal_id'  => $journalId,
                'created_by'  => $request->user()->id,
            ],
        );
    }

    // ── Approval Retensi (retensi pencairan KPR) ──────────────────────────
    public function retensiIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $accounts = $this->postableAccounts();
        $defaults = $this->retensiAccountDefaults($accounts);

        $retentions = KprRetention::query()
            ->with([
                'realization.application.customer:id,full_name',
                // #162 — UNIT (via booking) & tanggal akad untuk ditampilkan & default tgl jurnal.
                'realization.application.booking.unit.unitType:id,code,name',
                'realization.application.akad:id,kpr_application_id,akad_at',
                // Kategori retensi dipakai untuk mengenali tahap DAJAM PAJAK (jalur BSN).
                'realization.category:id,code,name,is_dajam',
                'releases.journal.lines.account:id,code,name',
                'journal.lines.account:id,code,name',
            ])
            ->orderByRaw('journal_id IS NULL DESC') // belum dijurnal (pending) di atas
            ->latest('id')
            ->get();

        // #147 — batch-load master rekening bank (kpr_realizations.bank_account_id) → GL bank.
        $bankIds = $retentions->pluck('realization.bank_account_id')->filter()->unique()->values();
        $banks   = $bankIds->isNotEmpty()
            ? BankAccount::whereIn('id', $bankIds)->get()->keyBy('id')
            : collect();

        // Booked Revenue per unit — penentu bentuk jurnal (Uang Muka + PPN vs Piutang Usaha).
        $brMap = $this->bookedRevenueMap($retentions->pluck('realization.application.booking.unit_id'));
        // COA Bank dari Set Up KPR → Kategori Bank — penentu akun Debit default.
        $termMap = $this->bankTermAccountMap($retentions->pluck('realization.application.bank_partner_id'));

        $retRows = $retentions->map(function ($r) use ($accounts, $banks, $defaults, $brMap, $termMap) {
            $j = $r->journal;
            $debit  = $j?->lines->first(fn ($l) => (float) $l->debit > 0);
            $credit = $j?->lines->first(fn ($l) => (float) $l->credit > 0);

            // Default akun DEBIT = COA Bank dari Set Up Kategori Bank (bila di-link-kan);
            // bila belum, jatuh ke tebakan dari nomor/nama rekening akad. Tetap editable di UI.
            $bankId   = $r->realization?->bank_account_id;
            $termAccId = $this->bankTermAccountFor($termMap, $r->realization?->application?->bank_partner_id, $r->realization?->retention_category_id);
            $termAcc  = $termAccId ? $accounts->firstWhere('id', $termAccId) : null;
            $bankGl   = $termAcc ?: $this->mapBankAccountToGl($bankId ? $banks->get($bankId) : null, $accounts);
            $amount   = (float) $r->amount;
            // PPN inklusif 11%: PPN Keluaran = round(amount * 11/111, 2); Uang Muka = sisa.
            $ppn      = round($amount * self::PPN_RATE_NUM / self::PPN_RATE_DEN, 2);
            $uangMuka = round($amount - $ppn, 2);

            // #162 — UNIT & tanggal akad.
            $unit     = $r->realization?->application?->booking?->unit;
            $unitType = $unit?->unitType;
            $unitInfo = $unitType ? trim((string) ($unitType->code ?: $unitType->name)) : null;
            $akadDate = optional($r->realization?->application?->akad?->akad_at)->toDateString();
            // Status akad dari Proses KPR; baris ber-status 'batal' disaring keluar (→ Rincian Batal Akad).
            $akadStat = $this->akadStatusFrom($r->realization?->application?->status?->value, $akadDate);

            // Booked Revenue & jalur pencairan → bentuk jurnal default baris ini.
            $br          = $brMap->get($r->realization?->application?->booking?->unit_id);
            $defaultDate = $akadDate ?? optional($r->released_at ?? $r->realization?->disbursed_at)->toDateString();
            $booked      = $this->isBookedOn($br, $defaultDate);
            $scheme      = $this->schemeOf($r->realization, $bankId ? $banks->get($bankId) : null);
            $cat         = $r->realization?->category;
            $dajamPajak  = $this->isDajamPajak($cat?->code, $cat?->name, $r->claim_reason);
            $piutangId   = $br?->receivable_account_id ?? $defaults['piutang_account_id'];
            $piutangAcc  = $piutangId ? $accounts->firstWhere('id', (int) $piutangId) : null;

            return [
                'id'                => $r->id,
                'kind'              => 'retensi',
                'akad_status'       => $akadStat['status'],
                'row_key'           => 'retensi-'.$r->id,
                'amount'            => $amount,
                'status'            => $r->status,
                'released_at'       => optional($r->released_at)->toDateString(),
                // Default tgl jurnal utk yg belum dijurnal = tgl akad (fallback tgl retensi/pencairan).
                'retention_date'    => optional($r->released_at ?? $r->realization?->disbursed_at)->toDateString(),
                'akad_date'         => $akadDate,
                'kpr_number'        => $r->realization?->application?->application_number,
                // #162/#163 — kode unit (untuk filter "Cari Unit") & label info tipe.
                'unit_code'         => $unit?->unit_code,
                'unit_info'         => $unit ? trim((string) $unit->unit_code.($unitInfo ? ' · '.$unitInfo : '')) : null,
                'customer'          => $r->realization?->application?->customer?->full_name,
                'note'              => $r->claim_reason,
                // Status penolakan (mirror UTJ & DP).
                'rejected'          => $r->approval_status === 'rejected',
                'reject_reason'     => $r->reject_reason,
                'rejected_at'       => optional($r->updated_at)->toDateString(),
                'journaled'         => (bool) $r->journal_id,
                'journal_id'        => $r->journal_id,
                'journal_number'    => $j?->journal_number,
                'journal_date'      => optional($j?->journal_date)->toDateString(),
                'debit_account_id'  => $debit?->account_id,
                'debit_account'     => $debit?->account ? $debit->account->code.' - '.$debit->account->name : null,
                'credit_account_id' => $credit?->account_id,
                'credit_account'    => $credit?->account ? $credit->account->code.' - '.$credit->account->name : null,
                // #147 — default per-baris (editable):
                'default_debit_account_id' => $bankGl?->id,
                'default_debit_account'    => $bankGl ? $bankGl->code.' - '.$bankGl->name : null,
                // 'bank_setup' = COA hasil Set Up Kategori Bank · 'auto' = tebakan nomor/nama rekening.
                'debit_source'             => $termAcc ? 'bank_setup' : ($bankGl ? 'auto' : null),
                'bank_account_label'       => ($bankId && $banks->get($bankId))
                    ? trim(($banks->get($bankId)->bank_name ?? '').' '.($banks->get($bankId)->account_number ?? ''))
                    : null,
                'default_ppn'              => $ppn,
                'default_uang_muka'        => $uangMuka,
                // Booked Revenue & jalur pencairan (penentu bentuk jurnal).
                'scheme'                   => $scheme,
                'is_dajam_pajak'           => $dajamPajak,
                'br_number'                => $br?->br_number,
                'br_booking_date'          => $br?->booking_date?->toDateString(),
                'booked'                   => $booked,
                // Penahanan DAJAM = uang KELUAR → ditandai merah di layar.
                'is_retensi_dajam'         => true,
                'default_credit_mode'      => $this->defaultCreditMode($booked, $scheme, $dajamPajak, 'retensi'),
                'default_piutang_account_id' => $piutangAcc?->id,
                'default_piutang_account'    => $piutangAcc ? $piutangAcc->code.' - '.$piutangAcc->name : null,
            ];
        });

        // ── Baris PELEPASAN DAJAM — SATU BARIS PER TRANCHE ────────────────────────────────
        // Retensi yang dilepas bertahap menghasilkan beberapa baris; masing-masing dijurnal &
        // disetujui SENDIRI-SENDIRI (ketentuan pemilik proses). Jurnalnya menempel di
        // kpr_retention_releases.journal_id, terpisah dari jurnal pencadangannya.
        $releaseRows = collect();
        foreach ($retentions as $ret) {
            $rz = $ret->realization;
            $bankId    = $rz?->bank_account_id;
            $termAccId = $this->bankTermAccountFor($termMap, $rz?->application?->bank_partner_id, $rz?->retention_category_id);
            $termAcc   = $termAccId ? $accounts->firstWhere('id', $termAccId) : null;
            $bankGl    = $termAcc ?: $this->mapBankAccountToGl($bankId ? $banks->get($bankId) : null, $accounts);
            $unit      = $rz?->application?->booking?->unit;
            $unitType  = $unit?->unitType;
            $akadDate  = optional($rz?->application?->akad?->akad_at)->toDateString();
            $akadStat  = $this->akadStatusFrom($rz?->application?->status?->value, $akadDate);
            $br        = $brMap->get($rz?->application?->booking?->unit_id);
            $scheme    = $this->schemeOf($rz, $bankId ? $banks->get($bankId) : null);
            $piutangId = $br?->receivable_account_id ?? $defaults['piutang_account_id'];
            $piutangAcc = $piutangId ? $accounts->firstWhere('id', (int) $piutangId) : null;

            $trancheTotal = $ret->releases->count();
            $retAmount    = round((float) $ret->amount, 2);
            $cumulative   = 0.0;

            foreach ($ret->releases as $i => $rel) {
                $amount  = round((float) $rel->amount, 2);
                $cumulative = round($cumulative + $amount, 2);
                $tgl     = optional($rel->released_at)->toDateString();
                // Bentuk jurnal tranche ditentukan Booked Revenue PADA TANGGAL PELEPASANNYA.
                $booked  = $this->isBookedOn($br, $tgl ?? $akadDate);
                $ppn     = round($amount * self::PPN_RATE_NUM / self::PPN_RATE_DEN, 2);
                $j       = $rel->journal;
                $debit   = $j?->lines->first(fn ($l) => (float) $l->debit > 0);
                $credit  = $j?->lines->first(fn ($l) => (float) $l->credit > 0);

                $releaseRows->push([
                    'id'                => $rel->id,
                    'kind'              => 'release',
                    'akad_status'       => $akadStat['status'],
                    'row_key'           => 'release-'.$rel->id,
                    'amount'            => $amount,
                    'status'            => 'released',
                    'released_at'       => $tgl,
                    'retention_date'    => $tgl,
                    'akad_date'         => $akadDate,
                    'kpr_number'        => $rz?->application?->application_number,
                    'unit_code'         => $unit?->unit_code,
                    'unit_info'         => $unitType ? trim((string) ($unitType->code ?: $unitType->name)) : null,
                    'customer'          => $rz?->application?->customer?->full_name,
                    'note'              => trim('Pelepasan DAJAM'.($rel->reason ? ' — '.$rel->reason : '')),
                    'rejected'          => $rel->approval_status === 'rejected',
                    'reject_reason'     => $rel->reject_reason,
                    'rejected_at'       => optional($rel->rejected_at)->toDateTimeString(),
                    'journaled'         => (bool) $rel->journal_id,
                    'journal_id'        => $rel->journal_id,
                    'journal_number'    => $j?->journal_number,
                    'journal_date'      => optional($j?->journal_date)->toDateString(),
                    'debit_account_id'  => $debit?->account_id,
                    'debit_account'     => $debit?->account ? $debit->account->code.' - '.$debit->account->name : null,
                    'credit_account_id' => $credit?->account_id,
                    'credit_account'    => $credit?->account ? $credit->account->code.' - '.$credit->account->name : null,
                    'default_debit_account_id' => $bankGl?->id,
                    'default_debit_account'    => $bankGl ? $bankGl->code.' - '.$bankGl->name : null,
                    'bank_account_label'       => $bankId ? optional($banks->get($bankId))->account_name : null,
                    'debit_source'      => $termAcc ? 'bank_setup' : ($bankGl ? 'auto' : null),
                    'default_ppn'       => $ppn,
                    'default_uang_muka' => round($amount - $ppn, 2),
                    'scheme'            => $scheme,
                    'is_dajam_pajak'    => false,
                    'is_retensi_dajam'  => false,
                    'br_number'         => $br?->br_number,
                    'br_booking_date'   => $br?->booking_date?->toDateString(),
                    'booked'            => $booked,
                    'default_credit_mode'        => $this->defaultCreditMode($booked, $scheme, false, 'release'),
                    'default_piutang_account_id' => $piutangAcc?->id,
                    'default_piutang_account'    => $piutangAcc ? $piutangAcc->code.' - '.$piutangAcc->name : null,
                    // Konteks induk — dipakai layar untuk menyusun tranche sebagai anak dari
                    // baris pencadangannya (indent + progres "sudah dilepas dari total retensi").
                    'parent_key'        => 'retensi-'.$ret->id,
                    'tranche_index'     => $i + 1,
                    'tranche_count'     => $trancheTotal,
                    'retention_amount'  => $retAmount,
                    'released_cum'      => $cumulative,
                    'retention_sisa'    => round(max($retAmount - $cumulative, 0), 2),
                ]);
            }
        }

        // ── Baris PENCAIRAN (net = disbursed − retention) — setiap input pencairan KPR ikut masuk
        //    approval, dijurnal DR Bank / CR Uang Muka Penjualan + PPN (sama seperti retensi). Net TTS
        //    pencairan hanya subledger (status 'verified', tanpa jurnal GL) → tidak double-count. ──
        $reals = KprRealization::query()
            ->whereRaw('(disbursed_amount - COALESCE(retention_amount, 0)) > 0.009')
            ->with([
                'application.customer:id,full_name',
                'application.booking.unit.unitType:id,code,name',
                'application.akad:id,kpr_application_id,akad_at',
                'category:id,code,name',
                'journal.lines.account:id,code,name',
            ])
            ->get();

        $rBankIds = $reals->pluck('bank_account_id')->filter()->unique()->values();
        $rBanks   = $rBankIds->isNotEmpty() ? BankAccount::whereIn('id', $rBankIds)->get()->keyBy('id') : collect();
        $rBrMap   = $this->bookedRevenueMap($reals->pluck('application.booking.unit_id'));
        $rTermMap = $this->bankTermAccountMap($reals->pluck('application.bank_partner_id'));

        $pencRows = $reals->map(function ($r) use ($accounts, $rBanks, $defaults, $rBrMap, $rTermMap) {
            $net    = round((float) $r->disbursed_amount - (float) $r->retention_amount, 2);
            $j      = $r->journal;
            $debit  = $j?->lines->first(fn ($l) => (float) $l->debit > 0);
            $credit = $j?->lines->first(fn ($l) => (float) $l->credit > 0);

            $bankId   = $r->bank_account_id;
            // COA Bank dari Set Up Kategori Bank lebih diutamakan daripada tebakan rekening.
            $termAccId = $this->bankTermAccountFor($rTermMap, $r->application?->bank_partner_id, $r->retention_category_id);
            $termAcc  = $termAccId ? $accounts->firstWhere('id', $termAccId) : null;
            $bankGl   = $termAcc ?: $this->mapBankAccountToGl($bankId ? $rBanks->get($bankId) : null, $accounts);
            $ppn      = round($net * self::PPN_RATE_NUM / self::PPN_RATE_DEN, 2);
            $uangMuka = round($net - $ppn, 2);

            $unit     = $r->application?->booking?->unit;
            $unitType = $unit?->unitType;
            $unitInfo = $unitType ? trim((string) ($unitType->code ?: $unitType->name)) : null;
            $akadDate = optional($r->application?->akad?->akad_at)->toDateString();
            $kategori = $r->category?->name;
            // Status akad dari Proses KPR; baris ber-status 'batal' disaring keluar (→ Rincian Batal Akad).
            $akadStat = $this->akadStatusFrom($r->application?->status?->value, $akadDate);

            // Booked Revenue & jalur pencairan → bentuk jurnal default baris ini.
            $br          = $rBrMap->get($r->application?->booking?->unit_id);
            $defaultDate = $akadDate ?? optional($r->disbursed_at)->toDateString();
            $booked      = $this->isBookedOn($br, $defaultDate);
            $scheme      = $this->schemeOf($r, $bankId ? $rBanks->get($bankId) : null);
            $dajamPajak  = $this->isDajamPajak($r->category?->code, $kategori, $r->retention_note);
            $piutangId   = $br?->receivable_account_id ?? $defaults['piutang_account_id'];
            $piutangAcc  = $piutangId ? $accounts->firstWhere('id', (int) $piutangId) : null;

            return [
                'id'                => $r->id,
                'kind'              => 'pencairan',
                'akad_status'       => $akadStat['status'],
                'row_key'           => 'pencairan-'.$r->id,
                'amount'            => $net,
                'status'            => 'pencairan',
                'released_at'       => null,
                // Default tgl jurnal utk pencairan = tgl akad (fallback tgl cair).
                'retention_date'    => optional($r->disbursed_at)->toDateString(),
                'akad_date'         => $akadDate,
                'kpr_number'        => $r->application?->application_number,
                'unit_code'         => $unit?->unit_code,
                'unit_info'         => $unit ? trim((string) $unit->unit_code.($unitInfo ? ' · '.$unitInfo : '')) : null,
                'customer'          => $r->application?->customer?->full_name,
                // Keterangan pencairan = kategori retensi (mis. TANAH MATANG 50) / catatan pencairan.
                'note'              => $kategori ?: $r->retention_note,
                'rejected'          => $r->approval_status === 'rejected',
                'reject_reason'     => $r->reject_reason,
                'rejected_at'       => optional($r->updated_at)->toDateString(),
                'journaled'         => (bool) $r->journal_id,
                'journal_id'        => $r->journal_id,
                'journal_number'    => $j?->journal_number,
                'journal_date'      => optional($j?->journal_date)->toDateString(),
                'debit_account_id'  => $debit?->account_id,
                'debit_account'     => $debit?->account ? $debit->account->code.' - '.$debit->account->name : null,
                'credit_account_id' => $credit?->account_id,
                'credit_account'    => $credit?->account ? $credit->account->code.' - '.$credit->account->name : null,
                'default_debit_account_id' => $bankGl?->id,
                'default_debit_account'    => $bankGl ? $bankGl->code.' - '.$bankGl->name : null,
                'debit_source'             => $termAcc ? 'bank_setup' : ($bankGl ? 'auto' : null),
                'bank_account_label'       => ($bankId && $rBanks->get($bankId))
                    ? trim(($rBanks->get($bankId)->bank_name ?? '').' '.($rBanks->get($bankId)->account_number ?? ''))
                    : null,
                'default_ppn'              => $ppn,
                'default_uang_muka'        => $uangMuka,
                // Booked Revenue & jalur pencairan (penentu bentuk jurnal).
                'scheme'                   => $scheme,
                'is_dajam_pajak'           => $dajamPajak,
                'br_number'                => $br?->br_number,
                'br_booking_date'          => $br?->booking_date?->toDateString(),
                'booked'                   => $booked,
                'is_retensi_dajam'         => false,
                'default_credit_mode'      => $this->defaultCreditMode($booked, $scheme, $dajamPajak, 'pencairan'),
                'default_piutang_account_id' => $piutangAcc?->id,
                'default_piutang_account'    => $piutangAcc ? $piutangAcc->code.' - '.$piutangAcc->name : null,
            ];
        });

        // Gabung retensi + pencairan; belum dijurnal (pending) di atas, lalu id terbaru.
        // KPR yang BATAL AKAD dipindahkan ke modul "Rincian Batal Akad" — tak boleh ikut
        // diapprove di sini. Yang SUDAH terlanjur dijurnal tetap ditampilkan sebagai riwayat.
        //
        // DAJAM yang masih 'held' (belum dilepas sama sekali) juga belum boleh diapprove: uangnya
        // memang masih ditahan, belum ada yang cair. Baru muncul setelah di-Release — status
        // 'partial' (sebagian dilepas) atau 'released' (lepas penuh). Sama seperti aturan batal
        // akad, yang telanjur dijurnal tetap tampil sebagai riwayat agar jurnal tidak hilang
        // dari layar.
        $rows = $retRows->concat($releaseRows)->concat($pencRows)
            ->reject(fn ($x) => $x['akad_status'] === 'batal' && ! $x['journaled'])
            ->reject(fn ($x) => $x['kind'] === 'retensi' && $x['status'] === 'held' && ! $x['journaled'])
            ->sortBy(fn ($x) => ($x['journaled'] ? 1_000_000_000_000 : 0) - $x['id'])
            ->values();

        // Pra-filter ke customer tertentu saat datang dari auto-redirect "KPR Lunas" (?customer_id=).
        // ID di URL (bukan nama) → privasi terjaga; nama di-resolve di server utk set filter awal.
        $initialCustomer = null;
        if ($cid = (int) $request->integer('customer_id')) {
            $initialCustomer = optional($retentions->first(
                fn ($r) => (int) ($r->realization?->application?->customer_id ?? 0) === $cid
            ))?->realization?->application?->customer?->full_name
                ?? optional($reals->first(
                    fn ($r) => (int) ($r->application?->customer_id ?? 0) === $cid
                ))?->application?->customer?->full_name;
        }

        return Inertia::render('BusinessSupport/CashBook/ApprovalRetensi', [
            'rows'            => $rows,
            'accounts'        => $accounts,
            'accountDefaults' => $defaults,
            'initialCustomer' => $initialCustomer,
        ]);
    }

    public function retensiPost(Request $request, KprRetention $retention, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($retention->journal_id) {
            return back()->with('error', 'Retensi ini sudah dijurnal.');
        }
        // #170 — retensi yang DITOLAK tidak boleh langsung dijurnal/disetujui (nominalnya sedang
        // dikembalikan ke Outstanding pencairan KPR). Harus dipulihkan dulu via "Pulihkan".
        if ($retention->approval_status === 'rejected') {
            return back()->with('error', 'Retensi ini ditolak — pulihkan dulu sebelum disetujui/dijurnal.');
        }
        $amount = (float) $retention->amount;
        if ($amount <= 0) {
            return back()->with('error', 'Nominal retensi tidak valid.');
        }

        // Keterangan baris jurnal: sertakan nama customer & nomor unit rumah.
        $retention->loadMissing([
            'realization.application.customer:id,full_name',
            'realization.application.booking.unit:id,unit_code',
        ]);
        $app    = $retention->realization?->application;
        $ref    = $app?->application_number ?? "RETENSI-{$retention->id}";
        $suffix = $this->descSuffix($app?->customer?->full_name, $app?->booking?->unit?->unit_code);
        $desc   = "Approval Retensi KPR {$ref}".$suffix;

        /**
         * #147 — Posting fleksibel:
         *  - debit_account_id = akun Bank (total).
         *  - Kredit bisa DUA baris: Uang Muka Penjualan + PPN Keluaran (split inklusif 11%),
         *    atau satu baris kredit klasik (credit_account_id) bila approver memilih demikian.
         * Semua nilai editable; jurnal HARUS balance (debit = total kredit).
         *
         * credit_mode menentukan bentuk jurnal sesuai status Booked Revenue unit — lihat
         * buildApprovalLines(): split (belum booked) · piutang (sudah booked) · deduct (DAJAM
         * Pajak jalur BSN, jurnal dibalik) · manual (kredit tunggal bebas).
         */
        $v = $request->validate([
            'journal_date'            => ['required', 'date'],
            'debit_account_id'        => ['required', 'integer', 'exists:bs_accounts,id'],
            'credit_mode'             => ['nullable', 'string', Rule::in(self::CREDIT_MODES)],
            // Split (mode default). Nullable agar mode kredit-tunggal tetap didukung.
            'uang_muka_account_id'    => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'uang_muka_amount'        => ['nullable', 'numeric', 'min:0'],
            'ppn_account_id'          => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'ppn_amount'              => ['nullable', 'numeric', 'min:0'],
            // Mode kredit-tunggal (Piutang Usaha setelah Booked Revenue / override manual).
            'credit_account_id'       => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        // Status Booked Revenue unit pada TANGGAL JURNAL yang dipilih → menentukan bentuk jurnal.
        $booked = $this->isBookedOn(
            $this->bookedRevenueOfUnit($app?->booking?->unit_id),
            (string) $v['journal_date'],
        );

        $built = $this->buildApprovalLines($v, $amount, $booked, $suffix, $desc, 'Retensi pencairan KPR'.$suffix);
        if (isset($built['error'])) {
            return back()->with('error', $built['error']);
        }
        $lines = $built['lines'];

        try {
            $journal = $svc->post([
                'journal_date' => $v['journal_date'],
                'source'       => 'cash',
                'status'       => 'posted',
                'reference'    => $ref,
                'description'  => $desc,
                'created_by'   => $request->user()->id,
            ], $lines);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Setujui sekaligus bersihkan tanda penolakan (bila sebelumnya pernah ditolak lalu dijurnal).
        $retention->update(['journal_id' => $journal->id, 'approval_status' => null, 'reject_reason' => null]);

        // Selain GL: catat entri Faktur Pajak Keluaran (keterangan Retensi) — langsung aktif untuk
        // diisi Nomor Faktur Pajak di Verifikasi Faktur Pajak. DPP = Uang Muka, PPN = split 11%.
        // Dilewati saat kredit ke Piutang Usaha (sudah Booked Revenue): PPN-nya sudah terbit pada
        // jurnal Booked Revenue, sehingga entri baru akan dobel lapor.
        if ($built['faktur']) {
            $this->recordFakturKeluaranEntry($request, 'retensi', $retention->id, $app?->customer_id, $app?->booking_id, $v['journal_date'], $built['dpp'], $built['ppn'], $amount, $journal->id, true);
        }

        return back()->with('success', "Retensi disetujui & jurnal {$journal->journal_number} terposting (".$this->creditModeLabel($built['mode']).').');
    }

    /**
     * Setujui & jurnal SATU TRANCHE pelepasan DAJAM.
     *
     * Sengaja terpisah dari retensiPost(): tiap pelepasan punya jurnal & approval sendiri,
     * sehingga pelepasan bertahap tidak lagi saling menimpa. Bentuk jurnalnya mengikuti
     * Booked Revenue PADA TANGGAL JURNAL tranche ini:
     *   belum booked → Dr Bank / Cr Uang Muka + Cr PPN Keluaran (11/111)
     *   sudah booked → Dr Bank / Cr Piutang Usaha (tanpa PPN)
     */
    public function releasePost(Request $request, KprRetentionRelease $release, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($release->journal_id) {
            return back()->with('error', 'Pelepasan DAJAM ini sudah dijurnal.');
        }
        if ($release->approval_status === 'rejected') {
            return back()->with('error', 'Pelepasan ini ditolak — pulihkan dulu sebelum disetujui/dijurnal.');
        }
        $amount = round((float) $release->amount, 2);
        if ($amount <= 0) {
            return back()->with('error', 'Nominal pelepasan tidak valid.');
        }

        $release->loadMissing([
            'retention.realization.application.customer:id,full_name',
            'retention.realization.application.booking.unit:id,unit_code',
        ]);
        $app    = $release->retention?->realization?->application;
        $ref    = $app?->application_number ?? "RELEASE-{$release->id}";
        $suffix = $this->descSuffix($app?->customer?->full_name, $app?->booking?->unit?->unit_code);
        $desc   = "Pelepasan DAJAM KPR {$ref}".$suffix;

        $v = $request->validate([
            'journal_date'         => ['required', 'date'],
            'debit_account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'credit_mode'          => ['nullable', 'string', Rule::in(self::CREDIT_MODES)],
            'uang_muka_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'uang_muka_amount'     => ['nullable', 'numeric', 'min:0'],
            'ppn_account_id'       => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'ppn_amount'           => ['nullable', 'numeric', 'min:0'],
            'credit_account_id'    => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        $booked = $this->isBookedOn(
            $this->bookedRevenueOfUnit($app?->booking?->unit_id),
            (string) $v['journal_date'],
        );

        // Pelepasan = uang MASUK; 'deduct' (jurnal dibalik) tidak berlaku di sini.
        if (($v['credit_mode'] ?? null) === 'deduct') {
            return back()->with('error', 'Pelepasan DAJAM adalah uang masuk — mode potongan tidak berlaku.');
        }

        $built = $this->buildApprovalLines($v, $amount, $booked, $suffix, $desc, 'Pelepasan DAJAM'.$suffix);
        if (isset($built['error'])) {
            return back()->with('error', $built['error']);
        }

        try {
            $journal = $svc->post([
                'journal_date' => $v['journal_date'],
                'source'       => 'cash',
                'status'       => 'posted',
                'reference'    => $ref,
                'description'  => $desc,
                'created_by'   => $request->user()->id,
            ], $built['lines']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $release->update(['journal_id' => $journal->id, 'approval_status' => null, 'reject_reason' => null, 'rejected_at' => null]);

        // PPN pelepasan wajib dilaporkan — hanya bila memang ada PPN (belum Booked Revenue).
        // source_type TERSENDIRI ('retensi_release', source_id = id tranche): tiap pelepasan punya
        // entri faktur sendiri, tidak saling menimpa, dan tidak bentrok id dengan induk retensinya.
        if ($built['faktur']) {
            $this->recordFakturKeluaranEntry($request, 'retensi_release', $release->id, $app?->customer_id, $app?->booking_id, $v['journal_date'], $built['dpp'], $built['ppn'], $amount, $journal->id, true);
        }

        return back()->with('success', "Pelepasan DAJAM disetujui & jurnal {$journal->journal_number} terposting (".$this->creditModeLabel($built['mode']).').');
    }

    /** Tolak SATU TRANCHE pelepasan DAJAM (belum dijurnal). */
    public function releaseReject(Request $request, KprRetentionRelease $release): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($release->journal_id) {
            return back()->with('error', 'Pelepasan ini sudah dijurnal — tidak bisa ditolak.');
        }
        $v = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $release->update(['approval_status' => 'rejected', 'reject_reason' => $v['reason'], 'rejected_at' => now()]);

        return back()->with('success', 'Pelepasan DAJAM ditolak.');
    }

    /** Pulihkan pelepasan DAJAM yang ditolak → kembali menunggu approval. */
    public function releaseRestore(Request $request, KprRetentionRelease $release): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        $release->update(['approval_status' => null, 'reject_reason' => null, 'rejected_at' => null]);

        return back()->with('success', 'Pelepasan DAJAM dipulihkan — menunggu approval kembali.');
    }

    /** Tolak approval Retensi (belum dijurnal) → approval_status 'rejected' + alasan. */
    public function retensiReject(Request $request, KprRetention $retention): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($retention->journal_id) {
            return back()->with('error', 'Retensi ini sudah dijurnal, tidak bisa ditolak.');
        }
        $v = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $retention->update([
            'approval_status' => 'rejected',
            'reject_reason'   => trim((string) ($v['reason'] ?? '')) ?: $retention->reject_reason,
        ]);

        // Kembalikan Proses KPR ke tahap PENCAIRAN: jika KPR sudah LUNAS (active) karena pencairan
        // (termasuk retensi ini), revert ke Disbursed agar user bisa input ulang pencairan untuk nominal
        // yang ditolak. Status diubah langsung (di luar guard transisi, seperti reversal pencairan).
        // #170 — Selain itu, retensi yang ditolak dihitung DITAHAN PENUH lagi di Proses KPR sehingga
        // nominal yang sempat dilepas otomatis KEMBALI ke "Outstanding Pencairan" KPR ybs (lihat Show.vue).
        $app = $retention->realization?->application;
        if ($app && $app->status === KprStatus::Active) {
            $app->update(['status' => KprStatus::Disbursed]);
        }

        // #170 — info nominal yang dikembalikan ke outstanding (porsi yang sebelumnya sempat dilepas).
        $released = round((float) $retention->released_amount, 2);
        $backNote = $released > 0.009
            ? ' Nominal yang sempat dilepas (Rp '.number_format($released, 2, ',', '.').') dikembalikan ke Outstanding pencairan KPR.'
            : '';

        return back()->with('success', 'Retensi ditolak.'.$backNote.' Proses KPR dikembalikan ke tahap Pencairan untuk input ulang nominal yang ditolak.');
    }

    /** Pulihkan Retensi yang ditolak → kembali menunggu approval. Alasan dipertahankan sbg riwayat. */
    public function retensiRestore(Request $request, KprRetention $retention): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($retention->approval_status !== 'rejected') {
            return back()->with('error', 'Hanya retensi berstatus ditolak yang bisa dipulihkan.');
        }
        $retention->update(['approval_status' => null]);

        // Simetris dengan reject: bila pencairan sudah penuh, naikkan kembali KPR ke LUNAS (active).
        $app = $retention->realization?->application;
        if ($app && $app->status === KprStatus::Disbursed
            && (float) $app->plafon_approved > 0
            && (float) $app->realizations()->sum('disbursed_amount') + 0.01 >= (float) $app->plafon_approved) {
            $app->update(['status' => KprStatus::Active]);
        }

        return back()->with('success', 'Retensi dipulihkan ke daftar menunggu approval.');
    }

    // ── Approval PENCAIRAN (net pencairan KPR = disbursed − retention) ─────
    // Setiap realisasi pencairan bisa disetujui/dijurnal di sini (DR Bank / CR Uang Muka + PPN),
    // persis alur retensi. Sumber Faktur Pajak Keluaran: source_type 'pencairan' (langsung aktif).

    public function realizationPost(Request $request, KprRealization $realization, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($realization->journal_id) {
            return back()->with('error', 'Pencairan ini sudah dijurnal.');
        }
        if ($realization->approval_status === 'rejected') {
            return back()->with('error', 'Pencairan ini ditolak — pulihkan dulu sebelum disetujui/dijurnal.');
        }
        $amount = round((float) $realization->disbursed_amount - (float) $realization->retention_amount, 2);
        if ($amount <= 0) {
            return back()->with('error', 'Nominal pencairan (net) tidak valid.');
        }

        $realization->loadMissing([
            'application.customer:id,full_name',
            'application.booking.unit:id,unit_code',
        ]);
        $app    = $realization->application;
        $ref    = $app?->application_number ?? "PENCAIRAN-{$realization->id}";
        $suffix = $this->descSuffix($app?->customer?->full_name, $app?->booking?->unit?->unit_code);
        $desc   = "Approval Pencairan KPR {$ref}".$suffix;

        $v = $request->validate([
            'journal_date'         => ['required', 'date'],
            'debit_account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'credit_mode'          => ['nullable', 'string', Rule::in(self::CREDIT_MODES)],
            'uang_muka_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'uang_muka_amount'     => ['nullable', 'numeric', 'min:0'],
            'ppn_account_id'       => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'ppn_amount'           => ['nullable', 'numeric', 'min:0'],
            'credit_account_id'    => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        // Bentuk jurnal mengikuti status Booked Revenue unit pada tanggal jurnal — sama seperti retensiPost.
        $booked = $this->isBookedOn(
            $this->bookedRevenueOfUnit($app?->booking?->unit_id),
            (string) $v['journal_date'],
        );

        $built = $this->buildApprovalLines($v, $amount, $booked, $suffix, $desc, 'Pencairan KPR'.$suffix);
        if (isset($built['error'])) {
            return back()->with('error', $built['error']);
        }
        $lines = $built['lines'];

        try {
            $journal = $svc->post([
                'journal_date' => $v['journal_date'],
                'source'       => 'cash',
                'status'       => 'posted',
                'reference'    => $ref,
                'description'  => $desc,
                'created_by'   => $request->user()->id,
            ], $lines);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $realization->update(['journal_id' => $journal->id, 'approval_status' => null, 'reject_reason' => null]);

        // Faktur Pajak Keluaran (keterangan 'Pencairan KPR') — langsung aktif, DPP = Uang Muka, PPN = split 11%.
        // Dilewati saat kredit ke Piutang Usaha (sudah Booked Revenue) — PPN sudah terbit saat Booked.
        if ($built['faktur']) {
            $this->recordFakturKeluaranEntry($request, 'pencairan', $realization->id, $app?->customer_id, $app?->booking_id, $v['journal_date'], $built['dpp'], $built['ppn'], $amount, $journal->id, true);
        }

        return back()->with('success', "Pencairan disetujui & jurnal {$journal->journal_number} terposting (".$this->creditModeLabel($built['mode']).').');
    }

    /** Tolak approval Pencairan (belum dijurnal) → approval_status 'rejected' + alasan. */
    public function realizationReject(Request $request, KprRealization $realization): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($realization->journal_id) {
            return back()->with('error', 'Pencairan ini sudah dijurnal, tidak bisa ditolak.');
        }
        $v = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $realization->update([
            'approval_status' => 'rejected',
            'reject_reason'   => trim((string) ($v['reason'] ?? '')) ?: $realization->reject_reason,
        ]);

        // Pencairan (realisasi) & alokasi TTS ke booking tetap — hanya persetujuan jurnal yang ditolak.
        return back()->with('success', 'Pencairan ditolak (tidak dijurnal). Realisasi & alokasi tetap; hanya persetujuan jurnal yang ditolak.');
    }

    /** Pulihkan Pencairan yang ditolak → kembali menunggu approval. */
    public function realizationRestore(Request $request, KprRealization $realization): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($realization->approval_status !== 'rejected') {
            return back()->with('error', 'Hanya pencairan berstatus ditolak yang bisa dipulihkan.');
        }
        $realization->update(['approval_status' => null]);

        return back()->with('success', 'Pencairan dipulihkan ke daftar menunggu approval.');
    }

    // ── Approval UTJ & DP (TTS yang sudah diposting ke Kuitansi) ──────────
    /**
     * Approval DP — penerimaan yang berasal dari "Pelunasan DP, Angsuran & Tambah DP" (FO).
     *
     * Mesinnya sama persis dengan Approval UTJ; yang membedakan hanya SUMBER barisnya
     * (tts_receipts.approval_source = 'dp'), sehingga dua submodul ini tidak pernah saling
     * menampilkan baris yang sama dan tidak ada penerimaan yang dijurnal dua kali.
     */
    public function dpIndex(Request $request): Response
    {
        return $this->utjdpIndex($request, Tts::SOURCE_DP);
    }

    public function dpPost(Request $request, Tts $tts, JournalService $svc): RedirectResponse
    {
        return $this->utjdpPost($request, $tts, $svc, Tts::SOURCE_DP);
    }

    public function dpReject(Request $request, Tts $tts): RedirectResponse
    {
        return $this->utjdpReject($request, $tts);
    }

    public function dpRestore(Request $request, Tts $tts): RedirectResponse
    {
        return $this->utjdpRestore($request, $tts);
    }

    /**
     * @param  ?string  $source  null = Approval UTJ (semua yang BUKAN 'dp') · 'dp' = Approval DP
     */
    public function utjdpIndex(Request $request, ?string $source = null): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // Pemisah dua submodul: Approval UTJ mengambil sisanya (termasuk data lama yang
        // approval_source-nya masih NULL), Approval DP hanya yang bertanda 'dp'.
        $bySource = fn ($q) => $source === Tts::SOURCE_DP
            ? $q->where('approval_source', Tts::SOURCE_DP)
            : $q->where(fn ($w) => $w->whereNull('approval_source')->orWhere('approval_source', '!=', Tts::SOURCE_DP));

        // Tab: 'pending' = posted BELUM dijurnal (menunggu approval); 'tanpa_unit' =
        // sudah dijurnal TAPI tanpa unit (journal_id ada, booking_id null — mis. Transfer
        // Anonim yg sudah di-posting tapi belum di-assign unit); 'rejected' = ditolak.
        $tab = in_array($request->input('tab'), ['rejected', 'tanpa_unit'], true)
            ? $request->input('tab') : 'pending';
        // Cegah double-count: TTS net-pencairan KPR (reference_no 'KPR-DISB-…') sudah dijurnal
        // lewat Approval Retensi (baris Pencairan). Kalau TTS-nya kebetulan di-posting manual di
        // modul TTS, JANGAN tampilkan di sini agar Bank tidak terdebit dua kali (DR Bank ganda).
        $notKprDisb = fn ($q) => $q->where(fn ($w) => $w->whereNull('reference_no')->orWhere('reference_no', 'not like', 'KPR-DISB-%'));

        $counts = [
            'pending'    => Tts::where($bySource)->where('status', 'posted')->whereNull('journal_id')->where($notKprDisb)->count(),
            'tanpa_unit' => Tts::where($bySource)->where('status', 'posted')->whereNotNull('journal_id')->whereNull('booking_id')->count(),
            'rejected'   => Tts::where($bySource)->where('status', 'rejected')->count(),
        ];

        $accounts = $this->postableAccounts();
        // Default kredit UTJ/DP customer = akun UTANG USAHA CUSTOMER (BUKAN supplier), editable di UI.
        // Cari akun customer secara spesifik (tangani ejaan "Utang"/"Hutang") → config payable_customer
        // (20101010102) → terakhir baru config payable (supplier) bila akun customer benar-benar tak ada.
        $payableGl = $accounts->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), 'utang usaha customer')
                || str_contains(mb_strtolower((string) $a->name), 'hutang usaha customer'))
            ?? ($accounts->firstWhere('code', config('business_support.gl_accounts.payable_customer')))
            ?? ($accounts->firstWhere('code', config('business_support.gl_accounts.payable')));

        $tts = Tts::query()
            ->where($bySource)
            ->when($tab === 'rejected', fn ($q) => $q->where('status', 'rejected'))
            ->when($tab === 'tanpa_unit', fn ($q) => $q->where('status', 'posted')->whereNotNull('journal_id')->whereNull('booking_id'))
            ->when($tab === 'pending', fn ($q) => $q->where('status', 'posted')->whereNull('journal_id')->where($notKprDisb))
            ->with([
                'customer:id,full_name',
                // #164 — UNIT yang dibeli (via booking) untuk ditampilkan.
                'booking:id,booking_number,unit_id',
                'booking.unit.unitType:id,code,name',
                'journal.lines.account:id,code,name',
            ])
            ->orderByRaw('journal_id IS NULL DESC') // belum dijurnal (pending) di atas
            ->latest('id')
            ->get();

        // #164 — batch-load master bank (tts.bank_account_id) → akun GL Bank default debit.
        $bankIds = $tts->pluck('bank_account_id')->filter()->unique()->values();
        $banks   = $bankIds->isNotEmpty() ? BankAccount::whereIn('id', $bankIds)->get()->keyBy('id') : collect();

        $rows = $tts->map(function ($t) use ($accounts, $banks, $payableGl) {
            $j = $t->journal;
            $debit  = $j?->lines->first(fn ($l) => (float) $l->debit > 0);
            $credit = $j?->lines->first(fn ($l) => (float) $l->credit > 0);

            // Default DEBIT = akun GL bank tempat penerimaan (tts.bank_account_id), editable.
            $bankId = $t->bank_account_id;
            $bankGl = $this->mapBankAccountToGl($bankId ? $banks->get($bankId) : null, $accounts);

            $unit     = $t->booking?->unit;
            $unitType = $unit?->unitType;
            $unitInfo = $unitType ? trim((string) ($unitType->code ?: $unitType->name)) : null;

            return [
                'id'                => $t->id,
                'tts_number'        => $t->tts_number,
                'amount'            => (float) $t->amount,
                'received_date'     => optional($t->received_date)->toDateString(),
                'payment_method'    => $t->payment_method,
                'reference_no'      => $t->reference_no,
                'customer'          => $t->customer?->full_name,
                'booking'           => $t->booking?->booking_number,
                // #164 — unit yang dibeli.
                'unit_code'         => $unit?->unit_code,
                'unit_info'         => $unit ? trim((string) $unit->unit_code.($unitInfo ? ' · '.$unitInfo : '')) : null,
                'journaled'         => (bool) $t->journal_id,
                'journal_id'        => $t->journal_id,
                'journal_number'    => $j?->journal_number,
                'journal_date'      => optional($j?->journal_date)->toDateString(),
                'debit_account_id'  => $debit?->account_id,
                'debit_account'     => $debit?->account ? $debit->account->code.' - '.$debit->account->name : null,
                'credit_account_id' => $credit?->account_id,
                'credit_account'    => $credit?->account ? $credit->account->code.' - '.$credit->account->name : null,
                // #164 — default per-baris (editable):
                'default_debit_account_id'  => $bankGl?->id,
                'default_debit_account'     => $bankGl ? $bankGl->code.' - '.$bankGl->name : null,
                'default_credit_account_id' => $payableGl?->id,
                'default_credit_account'    => $payableGl ? $payableGl->code.' - '.$payableGl->name : null,
                'bank_account_label'        => ($bankId && $banks->get($bankId))
                    ? trim(($banks->get($bankId)->bank_name ?? '').' '.($banks->get($bankId)->account_number ?? ''))
                    : null,
                // Tab "Ditolak": alasan (notes) & perkiraan tanggal ditolak (updated_at).
                'reject_reason'             => $t->notes,
                'rejected_at'               => optional($t->updated_at)->toDateString(),
            ];
        });

        $isDp = $source === Tts::SOURCE_DP;

        return Inertia::render('BusinessSupport/CashBook/ApprovalUtjDp', [
            'rows'     => $rows,
            'accounts' => $accounts,
            'tab'      => $tab,
            'counts'   => $counts,
            // Satu halaman dipakai dua submodul; judul, penjelasan & endpoint mengikuti sumbernya.
            'variant'  => $isDp ? 'dp' : 'utj',
            'title'    => $isDp ? 'Approval DP' : 'Approval UTJ',
            'subtitle' => $isDp
                ? 'Penerimaan dari Pelunasan DP, Angsuran & Tambah DP (FO) — masuk ke sini otomatis begitu "Terima Pembayaran" diklik. Setujui & Posting akan meneruskannya ke Approval UM Penjualan.'
                : 'Penerimaan UTJ, transfer anonim & kwitansi umum. Setujui & Posting akan meneruskannya ke Approval UM Penjualan.',
            'routeBase' => $isDp ? 'bs.cashbook.approval-dp' : 'bs.cashbook.approval-utjdp',
            'indexRoute' => $isDp ? 'bs.cashbook.approval-dp' : 'bs.cashbook.approval-utjdp',
        ]);
    }

    /** #164 — Tolak approval UTJ/DP (TTS belum dijurnal) → status rejected + alasan. */
    public function utjdpReject(Request $request, Tts $tts): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($tts->journal_id) {
            return back()->with('error', 'TTS ini sudah dijurnal, tidak bisa ditolak.');
        }
        $v = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $tts->update([
            'status' => 'rejected',
            'notes'  => trim((string) ($v['reason'] ?? '')) ?: $tts->notes,
        ]);

        return back()->with('success', "UTJ/DP TTS {$tts->tts_number} ditolak.");
    }

    /** Pulihkan TTS UTJ/DP yang ditolak → kembali ke daftar Menunggu Approval (status posted). Notes/alasan dipertahankan sbg riwayat. */
    public function utjdpRestore(Request $request, Tts $tts): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($tts->status !== 'rejected') {
            return back()->with('error', 'Hanya TTS berstatus ditolak yang bisa dipulihkan.');
        }
        $tts->update(['status' => 'posted']);

        return back()->with('success', "TTS {$tts->tts_number} dipulihkan ke daftar menunggu approval.");
    }

    /** @param  ?string  $source  'dp' bila disetujui dari submodul Approval DP. */
    public function utjdpPost(Request $request, Tts $tts, JournalService $svc, ?string $source = null): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        if ($tts->journal_id) {
            return back()->with('error', 'TTS ini sudah dijurnal.');
        }
        $amount = (float) $tts->amount;
        if ($amount <= 0) {
            return back()->with('error', 'Nominal TTS tidak valid.');
        }

        $v = $this->validateJournalInput($request);

        $isDp = $source === Tts::SOURCE_DP || $tts->approval_source === Tts::SOURCE_DP;

        // Keterangan baris jurnal: sertakan nama customer & nomor unit rumah.
        $tts->loadMissing(['customer:id,full_name', 'booking.unit:id,unit_code']);
        $label = $isDp ? 'Approval DP' : 'Approval UTJ';
        $desc = "{$label} TTS {$tts->tts_number}".$this->descSuffix($tts->customer?->full_name, $tts->booking?->unit?->unit_code);

        try {
            $journal = $this->postJournal($request, $svc, $v, $amount, $tts->tts_number, $desc);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $tts->update(['journal_id' => $journal->id]);

        // Selain GL: catat entri Faktur Pajak Keluaran (keterangan UTJ & DP) — BELUM aktif; nomor
        // Faktur Pajak baru bisa diisi setelah "Konversi ke Uang Muka Penjualan". DPP/PPN diisi 0
        // dulu (dana masih di Hutang Usaha); di-split saat konversi.
        // source_type tetap 'utj_dp' supaya barisnya masuk daftar Approval UM Penjualan yang sama;
        // ASAL-nya dibedakan lewat keterangan, sehingga di sana terbaca datang dari Approval DP.
        $this->recordFakturKeluaranEntry($request, 'utj_dp', $tts->id, $tts->customer_id, $tts->booking_id, $v['journal_date'], 0.0, 0.0, $amount, $journal->id, false,
            $isDp ? 'DP / Angsuran / TDP (Approval DP)' : null);

        return back()->with('success', "{$label} disetujui & jurnal {$journal->journal_number} terposting — lanjut ke Approval UM Penjualan.");
    }

    /* ══════════════════ APPROVAL UM PENJUALAN ══════════════════
     * Lanjutan Approval UTJ & DP: entri yang SUDAH "Setujui & Posting" di UTJ & DP
     * (Dr Bank / Cr Hutang Usaha) menunggu dikonversi ke Uang Muka Penjualan
     * (Dr Hutang Usaha / Cr Uang Muka + PPN Keluaran 11% inklusif).
     *
     * Sumber daftar = bs_faktur_keluaran_entries source_type='utj_dp' — baris ini dibuat
     * oleh utjdpPost() (lihat recordFakturKeluaranEntry di akhir method tsb), jadi isinya
     * OTOMATIS hanya TTS yang sudah lolos Setujui & Posting UTJ & DP.
     * Posting memakai action ConvertUtjDpToUangMuka — sama persis dengan tombol "Konversi
     * ke Uang Muka" di Verifikasi Faktur Pajak, sehingga jurnal identik & tak bisa dobel.
     */
    public function umIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $tab = in_array($request->query('tab'), ['selesai'], true) ? $request->query('tab') : 'pending';

        $base = fn () => BsFakturKeluaranEntry::query()->where('source_type', 'utj_dp');
        $counts = [
            'pending' => (clone $base())->whereNull('converted_at')->count(),
            'selesai' => (clone $base())->whereNotNull('converted_at')->count(),
        ];

        $entries = $base()
            ->when($tab === 'pending', fn ($q) => $q->whereNull('converted_at'))
            ->when($tab === 'selesai', fn ($q) => $q->whereNotNull('converted_at'))
            ->with([
                'customer:id,full_name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
                'convertJournal:id,journal_number,journal_date',
            ])
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $akad = $this->akadStatusByBooking($entries->pluck('booking_id')->filter()->unique()->values()->all());

        // KPR yang BATAL AKAD dipindahkan ke modul "Rincian Batal Akad" — tak ikut antre approval
        // di sini. Yang sudah terlanjur diposting tetap tampil di tab "Sudah Diposting" (riwayat).
        $entries = $entries->reject(
            fn (BsFakturKeluaranEntry $e) => $e->converted_at === null
                && $e->booking_id
                && (($akad[$e->booking_id]['status'] ?? null) === 'batal')
        )->values();

        $rows = $entries->map(function (BsFakturKeluaranEntry $e) use ($akad) {
            $total = round((float) $e->total, 2);
            // Pratinjau jurnal (PPN inklusif 11%) — angka final tetap dihitung ulang di action saat posting.
            $ppn = round($total * 11 / 111, 2);
            $a = $e->booking_id ? ($akad[$e->booking_id] ?? null) : null;

            return [
                'id'            => $e->id,
                'keterangan'    => $e->keterangan,
                'customer'      => $e->customer?->full_name,
                'unit'          => $e->booking?->unit?->unit_code,
                'booking'       => $e->booking?->booking_number,
                'doc_date'      => optional($e->doc_date)->toDateString(),
                'total'         => $total,
                'dpp_preview'   => round($total - $ppn, 2),
                'ppn_preview'   => $ppn,
                'converted_at'  => optional($e->converted_at)->toDateString(),
                'journal_number' => $e->convertJournal?->journal_number,
                // Status akad dari Proses KPR: sudah | belum | batal | null (tanpa booking → tak bisa ditelusuri)
                'akad_status'   => $a['status'] ?? null,
                'akad_at'       => $a['akad_at'] ?? null,
            ];
        });

        return Inertia::render('BusinessSupport/CashBook/ApprovalUmPenjualan', [
            'rows'   => $rows,
            'tab'    => $tab,
            'counts' => $counts,
        ]);
    }

    public function umPost(Request $request, BsFakturKeluaranEntry $entry, ConvertUtjDpToUangMuka $action): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        try {
            $journal = $action->execute($entry, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "UM Penjualan disetujui & jurnal {$journal->journal_number} terposting.");
    }

    /**
     * Status akad per booking, dari Proses KPR (aplikasi TERBARU per booking).
     *
     * Aturan (disepakati):
     *  - 'batal' → KPR ditolak/dibatalkan (didahulukan, walau akadnya sempat terjadi)
     *  - 'sudah' → status Pencairan/Lunas, ATAU tahap Akad DAN record akad memang ada
     *  - 'belum' → selain itu
     * Cek "record akad ada" saja TIDAK cukup: aksi "← Tahap Sebelumnya" menyisakan record
     * kpr_akads meski status mundur; sebaliknya status 'akad' bisa tercapai tanpa record akad.
     *
     * @param  array<int,int>  $bookingIds
     * @return array<int, array{status:string, akad_at:?string}>
     */
    private function akadStatusByBooking(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }
        // Aplikasi KPR terbaru per booking (id terbesar menang; hormati soft delete).
        $apps = \Illuminate\Support\Facades\DB::table('kpr_applications')
            ->whereIn('booking_id', $bookingIds)->whereNull('deleted_at')
            ->orderBy('booking_id')->orderByDesc('id')
            ->get(['id', 'booking_id', 'status']);
        $latest = [];
        foreach ($apps as $a) {
            $latest[$a->booking_id] ??= $a;
        }
        if ($latest === []) {
            return [];
        }
        // Record akad (kpr_akads tak punya tenant_id/soft delete → di-scope lewat id aplikasi di atas).
        $akads = \Illuminate\Support\Facades\DB::table('kpr_akads')
            ->whereIn('kpr_application_id', array_map(fn ($a) => $a->id, $latest))
            ->orderBy('kpr_application_id')->orderByDesc('id')
            ->get(['kpr_application_id', 'akad_at']);
        $akadBy = [];
        foreach ($akads as $k) {
            $akadBy[$k->kpr_application_id] ??= $k->akad_at;
        }

        $out = [];
        foreach ($latest as $bookingId => $a) {
            $out[$bookingId] = $this->akadStatusFrom($a->status, $akadBy[$a->id] ?? null);
        }

        return $out;
    }

    /**
     * Aturan status akad (fungsi murni) — SUMBER TUNGGAL, dipakai Approval Retensi,
     * Approval UM Penjualan, dan Rincian Batal Akad agar ketiganya tak mungkin berbeda.
     *
     * @param  ?string  $statusValue  nilai kolom kpr_applications.status
     * @param  ?string  $akadAt       kpr_akads.akad_at (bila ada)
     * @return array{status:string, akad_at:?string}
     */
    private function akadStatusFrom(?string $statusValue, ?string $akadAt): array
    {
        $status = match (true) {
            // Batal DIDAHULUKAN: KPR batal/ditolak, walau akadnya sempat terjadi.
            in_array($statusValue, [KprStatus::Rejected->value, KprStatus::Cancelled->value], true) => 'batal',
            in_array($statusValue, [KprStatus::Disbursed->value, KprStatus::Active->value], true)   => 'sudah',
            // Tahap Akad saja belum cukup — record akad harus ada (status 'akad' bisa tercapai tanpa record).
            $statusValue === KprStatus::AkadDone->value && $akadAt !== null                          => 'sudah',
            default                                                                                  => 'belum',
        };

        return ['status' => $status, 'akad_at' => $akadAt ? substr((string) $akadAt, 0, 10) : null];
    }
}
