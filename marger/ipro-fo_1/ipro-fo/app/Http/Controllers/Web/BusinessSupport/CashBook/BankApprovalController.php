<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\Finance\Bank\Models\BankAccount;
use App\Domain\Finance\Tts\Models\Tts;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * @param  \Illuminate\Support\Collection<int,\App\Domain\BusinessSupport\Models\BsAccount>  $accounts
     * @return array{uang_muka_account_id:?int, uang_muka_account:?string, ppn_keluaran_account_id:?int, ppn_keluaran_account:?string, ppn_rate_num:int, ppn_rate_den:int}
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

        return [
            'uang_muka_account_id'    => $uangMuka?->id,
            'uang_muka_account'       => $uangMuka ? $uangMuka->code.' - '.$uangMuka->name : null,
            'ppn_keluaran_account_id' => $ppnOut?->id,
            'ppn_keluaran_account'    => $ppnOut ? $ppnOut->code.' - '.$ppnOut->name : null,
            'ppn_rate_num'            => self::PPN_RATE_NUM,
            'ppn_rate_den'            => self::PPN_RATE_DEN,
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
    private function recordFakturKeluaranEntry(Request $request, string $type, int $sourceId, ?int $customerId, ?int $bookingId, string $docDate, float $dpp, float $ppn, float $total, int $journalId, bool $active): void
    {
        BsFakturKeluaranEntry::updateOrCreate(
            ['source_type' => $type, 'source_id' => $sourceId],
            [
                'keterangan'  => $type === 'retensi' ? 'Retensi' : ($type === 'pencairan' ? 'Pencairan KPR' : 'UTJ & DP'),
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

        $retRows = $retentions->map(function ($r) use ($accounts, $banks, $defaults) {
            $j = $r->journal;
            $debit  = $j?->lines->first(fn ($l) => (float) $l->debit > 0);
            $credit = $j?->lines->first(fn ($l) => (float) $l->credit > 0);

            // Default akun DEBIT = akun GL Bank tempat akad kredit (editable di UI).
            $bankId   = $r->realization?->bank_account_id;
            $bankGl   = $this->mapBankAccountToGl($bankId ? $banks->get($bankId) : null, $accounts);
            $amount   = (float) $r->amount;
            // PPN inklusif 11%: PPN Keluaran = round(amount * 11/111, 2); Uang Muka = sisa.
            $ppn      = round($amount * self::PPN_RATE_NUM / self::PPN_RATE_DEN, 2);
            $uangMuka = round($amount - $ppn, 2);

            // #162 — UNIT & tanggal akad.
            $unit     = $r->realization?->application?->booking?->unit;
            $unitType = $unit?->unitType;
            $unitInfo = $unitType ? trim((string) ($unitType->code ?: $unitType->name)) : null;
            $akadDate = optional($r->realization?->application?->akad?->akad_at)->toDateString();

            return [
                'id'                => $r->id,
                'kind'              => 'retensi',
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
                'bank_account_label'       => ($bankId && $banks->get($bankId))
                    ? trim(($banks->get($bankId)->bank_name ?? '').' '.($banks->get($bankId)->account_number ?? ''))
                    : null,
                'default_ppn'              => $ppn,
                'default_uang_muka'        => $uangMuka,
            ];
        });

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

        $pencRows = $reals->map(function ($r) use ($accounts, $rBanks) {
            $net    = round((float) $r->disbursed_amount - (float) $r->retention_amount, 2);
            $j      = $r->journal;
            $debit  = $j?->lines->first(fn ($l) => (float) $l->debit > 0);
            $credit = $j?->lines->first(fn ($l) => (float) $l->credit > 0);

            $bankId   = $r->bank_account_id;
            $bankGl   = $this->mapBankAccountToGl($bankId ? $rBanks->get($bankId) : null, $accounts);
            $ppn      = round($net * self::PPN_RATE_NUM / self::PPN_RATE_DEN, 2);
            $uangMuka = round($net - $ppn, 2);

            $unit     = $r->application?->booking?->unit;
            $unitType = $unit?->unitType;
            $unitInfo = $unitType ? trim((string) ($unitType->code ?: $unitType->name)) : null;
            $akadDate = optional($r->application?->akad?->akad_at)->toDateString();
            $kategori = $r->category?->name;

            return [
                'id'                => $r->id,
                'kind'              => 'pencairan',
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
                'bank_account_label'       => ($bankId && $rBanks->get($bankId))
                    ? trim(($rBanks->get($bankId)->bank_name ?? '').' '.($rBanks->get($bankId)->account_number ?? ''))
                    : null,
                'default_ppn'              => $ppn,
                'default_uang_muka'        => $uangMuka,
            ];
        });

        // Gabung retensi + pencairan; belum dijurnal (pending) di atas, lalu id terbaru.
        $rows = $retRows->concat($pencRows)
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
         */
        $v = $request->validate([
            'journal_date'            => ['required', 'date'],
            'debit_account_id'        => ['required', 'integer', 'exists:bs_accounts,id'],
            // Split (mode default). Nullable agar mode kredit-tunggal tetap didukung.
            'uang_muka_account_id'    => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'uang_muka_amount'        => ['nullable', 'numeric', 'min:0'],
            'ppn_account_id'          => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'ppn_amount'              => ['nullable', 'numeric', 'min:0'],
            // Mode kredit-tunggal (fallback / override manual).
            'credit_account_id'       => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        // Susun baris kredit dari input yang terisi (amount > 0 & akun dipilih).
        $creditLines = [];
        $addCredit = function (?int $accId, $amt, string $desc) use (&$creditLines): void {
            $accId = $accId ? (int) $accId : 0;
            $amt   = round((float) $amt, 2);
            if ($accId > 0 && $amt > 0) {
                $creditLines[] = ['account_id' => $accId, 'amount' => $amt, 'description' => $desc];
            }
        };
        $addCredit($v['uang_muka_account_id'] ?? null, $v['uang_muka_amount'] ?? 0, 'Uang Muka Penjualan'.$suffix);
        $addCredit($v['ppn_account_id'] ?? null, $v['ppn_amount'] ?? 0, 'PPN Keluaran'.$suffix);
        // Bila split kosong, pakai kredit tunggal sebesar total retensi.
        if (! $creditLines && ! empty($v['credit_account_id'])) {
            $addCredit((int) $v['credit_account_id'], $amount, 'Retensi pencairan KPR'.$suffix);
        }

        if (! $creditLines) {
            return back()->with('error', 'Tentukan minimal satu akun & nominal kredit (Uang Muka / PPN, atau akun kredit tunggal).');
        }

        $totalCredit = round(array_sum(array_column($creditLines, 'amount')), 2);
        if ($totalCredit !== round($amount, 2)) {
            return back()->with('error', 'Total kredit ('.number_format($totalCredit, 2).') harus sama dengan nominal retensi ('.number_format($amount, 2).').');
        }

        // Akun debit tidak boleh bertabrakan dengan akun kredit manapun.
        foreach ($creditLines as $cl) {
            if ((int) $v['debit_account_id'] === $cl['account_id']) {
                return back()->with('error', 'Akun Debit tidak boleh sama dengan akun Kredit.');
            }
        }

        $lines = [
            ['account_id' => (int) $v['debit_account_id'], 'debit' => round($amount, 2), 'credit' => 0, 'description' => $desc],
        ];
        foreach ($creditLines as $cl) {
            $lines[] = ['account_id' => $cl['account_id'], 'debit' => 0, 'credit' => $cl['amount'], 'description' => $cl['description']];
        }

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
        $entryPpn = round((float) ($v['ppn_amount'] ?? 0), 2);
        $entryDpp = round((float) ($v['uang_muka_amount'] ?? 0), 2);
        if ($entryDpp <= 0 && $entryPpn <= 0) {
            $entryDpp = round($amount, 2); // mode kredit-tunggal: seluruh nominal dianggap DPP
        }
        $this->recordFakturKeluaranEntry($request, 'retensi', $retention->id, $app?->customer_id, $app?->booking_id, $v['journal_date'], $entryDpp, $entryPpn, $amount, $journal->id, true);

        return back()->with('success', "Retensi disetujui & jurnal {$journal->journal_number} terposting.");
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
            'uang_muka_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'uang_muka_amount'     => ['nullable', 'numeric', 'min:0'],
            'ppn_account_id'       => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'ppn_amount'           => ['nullable', 'numeric', 'min:0'],
            'credit_account_id'    => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        // Susun baris kredit (Uang Muka + PPN split, atau kredit tunggal) — sama seperti retensiPost.
        $creditLines = [];
        $addCredit = function (?int $accId, $amt, string $desc) use (&$creditLines): void {
            $accId = $accId ? (int) $accId : 0;
            $amt   = round((float) $amt, 2);
            if ($accId > 0 && $amt > 0) {
                $creditLines[] = ['account_id' => $accId, 'amount' => $amt, 'description' => $desc];
            }
        };
        $addCredit($v['uang_muka_account_id'] ?? null, $v['uang_muka_amount'] ?? 0, 'Uang Muka Penjualan'.$suffix);
        $addCredit($v['ppn_account_id'] ?? null, $v['ppn_amount'] ?? 0, 'PPN Keluaran'.$suffix);
        if (! $creditLines && ! empty($v['credit_account_id'])) {
            $addCredit((int) $v['credit_account_id'], $amount, 'Pencairan KPR'.$suffix);
        }
        if (! $creditLines) {
            return back()->with('error', 'Tentukan minimal satu akun & nominal kredit (Uang Muka / PPN, atau akun kredit tunggal).');
        }

        $totalCredit = round(array_sum(array_column($creditLines, 'amount')), 2);
        if ($totalCredit !== round($amount, 2)) {
            return back()->with('error', 'Total kredit ('.number_format($totalCredit, 2).') harus sama dengan nominal pencairan net ('.number_format($amount, 2).').');
        }
        foreach ($creditLines as $cl) {
            if ((int) $v['debit_account_id'] === $cl['account_id']) {
                return back()->with('error', 'Akun Debit tidak boleh sama dengan akun Kredit.');
            }
        }

        $lines = [
            ['account_id' => (int) $v['debit_account_id'], 'debit' => round($amount, 2), 'credit' => 0, 'description' => $desc],
        ];
        foreach ($creditLines as $cl) {
            $lines[] = ['account_id' => $cl['account_id'], 'debit' => 0, 'credit' => $cl['amount'], 'description' => $cl['description']];
        }

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
        $entryPpn = round((float) ($v['ppn_amount'] ?? 0), 2);
        $entryDpp = round((float) ($v['uang_muka_amount'] ?? 0), 2);
        if ($entryDpp <= 0 && $entryPpn <= 0) {
            $entryDpp = round($amount, 2);
        }
        $this->recordFakturKeluaranEntry($request, 'pencairan', $realization->id, $app?->customer_id, $app?->booking_id, $v['journal_date'], $entryDpp, $entryPpn, $amount, $journal->id, true);

        return back()->with('success', "Pencairan disetujui & jurnal {$journal->journal_number} terposting.");
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
    public function utjdpIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

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
            'pending'    => Tts::where('status', 'posted')->whereNull('journal_id')->where($notKprDisb)->count(),
            'tanpa_unit' => Tts::where('status', 'posted')->whereNotNull('journal_id')->whereNull('booking_id')->count(),
            'rejected'   => Tts::where('status', 'rejected')->count(),
        ];

        $accounts = $this->postableAccounts();
        // #164 — default kredit = akun Hutang Usaha customer (editable di UI).
        $payableGl = $accounts->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), 'hutang usaha'))
            ?? ($accounts->firstWhere('code', config('business_support.gl_accounts.payable')));

        $tts = Tts::query()
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

        return Inertia::render('BusinessSupport/CashBook/ApprovalUtjDp', [
            'rows'     => $rows,
            'accounts' => $accounts,
            'tab'      => $tab,
            'counts'   => $counts,
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

    public function utjdpPost(Request $request, Tts $tts, JournalService $svc): RedirectResponse
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

        // Keterangan baris jurnal: sertakan nama customer & nomor unit rumah.
        $tts->loadMissing(['customer:id,full_name', 'booking.unit:id,unit_code']);
        $desc = "Approval UTJ/DP TTS {$tts->tts_number}".$this->descSuffix($tts->customer?->full_name, $tts->booking?->unit?->unit_code);

        try {
            $journal = $this->postJournal($request, $svc, $v, $amount, $tts->tts_number, $desc);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $tts->update(['journal_id' => $journal->id]);

        // Selain GL: catat entri Faktur Pajak Keluaran (keterangan UTJ & DP) — BELUM aktif; nomor
        // Faktur Pajak baru bisa diisi setelah "Konversi ke Uang Muka Penjualan". DPP/PPN diisi 0
        // dulu (dana masih di Hutang Usaha); di-split saat konversi.
        $this->recordFakturKeluaranEntry($request, 'utj_dp', $tts->id, $tts->customer_id, $tts->booking_id, $v['journal_date'], 0.0, 0.0, $amount, $journal->id, false);

        return back()->with('success', "UTJ/DP disetujui & jurnal {$journal->journal_number} terposting.");
    }
}
