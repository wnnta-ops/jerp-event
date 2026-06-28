<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BusinessSupport\Models\BsAccount;
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

        $rows = $retentions->map(function ($r) use ($accounts, $banks, $defaults) {
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

        // Pra-filter ke customer tertentu saat datang dari auto-redirect "KPR Lunas" (?customer_id=).
        // ID di URL (bukan nama) → privasi terjaga; nama di-resolve di server utk set filter awal.
        $initialCustomer = null;
        if ($cid = (int) $request->integer('customer_id')) {
            $initialCustomer = optional($retentions->first(
                fn ($r) => (int) ($r->realization?->application?->customer_id ?? 0) === $cid
            ))?->realization?->application?->customer?->full_name;
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

    // ── Approval UTJ & DP (TTS yang sudah diposting ke Kuitansi) ──────────
    public function utjdpIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // Tab: 'pending' = TTS posted menunggu approval; 'rejected' = TTS yang ditolak.
        $tab    = $request->input('tab') === 'rejected' ? 'rejected' : 'pending';
        $status = $tab === 'rejected' ? 'rejected' : 'posted';
        $counts = [
            'pending'  => Tts::where('status', 'posted')->count(),
            'rejected' => Tts::where('status', 'rejected')->count(),
        ];

        $accounts = $this->postableAccounts();
        // #164 — default kredit = akun Hutang Usaha customer (editable di UI).
        $payableGl = $accounts->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), 'hutang usaha'))
            ?? ($accounts->firstWhere('code', config('business_support.gl_accounts.payable')));

        $tts = Tts::query()
            ->where('status', $status)
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

        return back()->with('success', "UTJ/DP disetujui & jurnal {$journal->journal_number} terposting.");
    }
}
