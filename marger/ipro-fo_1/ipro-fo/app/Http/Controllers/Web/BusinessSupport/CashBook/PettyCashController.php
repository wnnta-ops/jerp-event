<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Actions\RecordCashTransaction;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kas Kecil (Petty Cash) — sub-buku kas kecil. Transaksi kas dengan akun kas = "KAS KECIL".
 *   Masuk (top-up)  : Dr Kas Kecil / Cr Bank
 *   Keluar (beban)  : Dr Beban     / Cr Kas Kecil
 */
class PettyCashController extends Controller
{
    private const PETTY_CODE = '10101010102';

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.pettycash.view'), 403);

        // Jangan 500 bila akun Kas Kecil belum ada — tampilkan halaman kosong + peringatan.
        $pettyId = (int) BsAccount::where('code', self::PETTY_CODE)->value('id');
        if ($pettyId === 0) {
            return Inertia::render('BusinessSupport/CashBook/PettyCash/Index', [
                'transactions' => ['data' => []],
                'saldo'        => 0.0,
                'filters'      => $request->only(['type']),
                'summary'      => ['in' => 0.0, 'out' => 0.0],
                'warning'      => 'Akun Kas Kecil (10101010102) belum tersedia di Chart of Account. Hubungi admin akuntansi.',
            ]);
        }

        // #86: mutasi masuk/keluar + saldo berjalan per baris — sumber = General Ledger akun Kas Kecil
        // (sama basis dgn Cash Book GL & kartu Saldo), sehingga saldo berjalan cocok dengan closing.
        $base = fn () => BsJournalLine::query()
            ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
            ->where('j.status', 'posted')->whereNull('j.deleted_at')
            ->where('bs_journal_lines.account_id', $pettyId)
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('j.journal_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('j.journal_date', '<=', $v));

        // Saldo awal sebelum 'from' agar saldo berjalan benar saat difilter periode.
        $opening = 0.0;
        if ($from = $request->input('from')) {
            $opening = (float) BsJournalLine::query()
                ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
                ->where('j.status', 'posted')->whereNull('j.deleted_at')
                ->where('bs_journal_lines.account_id', $pettyId)
                ->whereDate('j.journal_date', '<', $from)
                ->selectRaw('COALESCE(SUM(bs_journal_lines.debit-bs_journal_lines.credit),0) as bal')->value('bal');
        }

        // Saldo berjalan atas seluruh set terfilter (urut naik), peta per-id agar benar lintas halaman.
        $runningSaldo = [];
        $bal = $opening;
        foreach ($base()->orderBy('j.journal_date')->orderBy('bs_journal_lines.id')
            ->get(['bs_journal_lines.id', 'bs_journal_lines.debit', 'bs_journal_lines.credit']) as $l) {
            $bal += (float) $l->debit - (float) $l->credit;
            $runningSaldo[$l->id] = $bal;
        }

        $rows = $base()
            ->orderByDesc('j.journal_date')->orderByDesc('bs_journal_lines.id')
            ->select([
                'bs_journal_lines.id', 'bs_journal_lines.debit', 'bs_journal_lines.credit',
                'bs_journal_lines.description as line_description',
                'j.journal_number', 'j.reference', 'j.journal_date', 'j.description as journal_description',
            ])
            ->paginate(50)->withQueryString();

        $rows->getCollection()->transform(function (BsJournalLine $l) use ($runningSaldo): BsJournalLine {
            $l->setAttribute('masuk', (float) $l->debit);
            $l->setAttribute('keluar', (float) $l->credit);
            $l->setAttribute('running_saldo', $runningSaldo[$l->id] ?? null);
            $l->setAttribute('trx_number', $l->reference ?: $l->journal_number);
            $l->setAttribute('trx_date', $l->journal_date);
            $l->setAttribute('description', $l->line_description ?: $l->journal_description);

            return $l;
        });

        // Saldo (closing) s.d. tanggal "to" — basis sama (debit−credit).
        $saldo = (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->where('bs_journal_lines.account_id', $pettyId)
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('bs_journals.journal_date', '<=', $v))
            ->selectRaw('COALESCE(SUM(debit-credit),0) bal')->value('bal');

        $sum = $base()->selectRaw('COALESCE(SUM(bs_journal_lines.debit),0) as m, COALESCE(SUM(bs_journal_lines.credit),0) as k')->first();

        return Inertia::render('BusinessSupport/CashBook/PettyCash/Index', [
            'transactions' => $rows,
            'saldo'        => $saldo,
            'filters'      => $request->only(['from', 'to']),
            'summary'      => ['in' => (float) ($sum->m ?? 0), 'out' => (float) ($sum->k ?? 0)],
        ]);
    }

    /** Download mutasi Kas Kecil (mengikuti filter layar Dari/Sampai) ke Excel (.xls) — pola #129. */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.pettycash.view'), 403);

        $pettyId = (int) BsAccount::where('code', self::PETTY_CODE)->value('id');
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $base = fn () => BsJournalLine::query()
            ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
            ->where('j.status', 'posted')->whereNull('j.deleted_at')
            ->where('bs_journal_lines.account_id', $pettyId)
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('j.journal_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('j.journal_date', '<=', $v));

        $opening = 0.0;
        if ($pettyId && ($from = $request->input('from'))) {
            $opening = (float) BsJournalLine::query()
                ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
                ->where('j.status', 'posted')->whereNull('j.deleted_at')
                ->where('bs_journal_lines.account_id', $pettyId)
                ->whereDate('j.journal_date', '<', $from)
                ->selectRaw('COALESCE(SUM(bs_journal_lines.debit-bs_journal_lines.credit),0) as bal')->value('bal');
        }

        $rows = $pettyId
            ? $base()->orderBy('j.journal_date')->orderBy('bs_journal_lines.id')
                ->get(['bs_journal_lines.debit', 'bs_journal_lines.credit', 'bs_journal_lines.description as line_description',
                    'j.journal_number', 'j.reference', 'j.journal_date', 'j.description as journal_description'])
            : collect();

        $h = '<table border="1"><tr><th colspan="6" style="background:#0f766e;color:#fff">Mutasi Kas Kecil</th></tr>'
            .'<tr style="background:#e2e8f0"><th>No.</th><th>Tanggal</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th><th>Saldo</th></tr>';
        $bal = $opening;
        foreach ($rows as $r) {
            $bal += (float) $r->debit - (float) $r->credit;
            $h .= '<tr><td>'.$e($r->reference ?: $r->journal_number).'</td><td>'.$e(optional($r->journal_date)->toDateString())
                .'</td><td>'.$e($r->line_description ?: $r->journal_description)
                .'</td><td>'.$rp($r->debit).'</td><td>'.$rp($r->credit).'</td><td>'.$rp($bal).'</td></tr>';
        }
        $h .= '</table>';
        $file = 'Mutasi-kas-kecil-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.pettycash.create'), 403);

        // #52: sumber dana top-up = bank/kas + akun dari customer (Uang Muka Penjualan, Piutang)
        // + dari karyawan (Uang Muka Operasional / refund bon sementara).
        $extraSrc = array_filter([
            config('business_support.gl_accounts.unearned_revenue'), // Uang Muka Penjualan (UTJ/DP customer)
            config('business_support.gl_accounts.receivable'),       // Piutang Usaha (angsuran customer)
            config('business_support.gl_accounts.advance_op'),       // Uang Muka Operasional (refund bon karyawan)
        ]);

        return Inertia::render('BusinessSupport/CashBook/PettyCash/Form', [
            'sources'  => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '101010%')->orWhereIn('code', $extraSrc))
                ->where('code', '!=', self::PETTY_CODE)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'defaultType' => $request->input('type', 'out'),
        ]);
    }

    public function store(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.pettycash.create'), 403);

        $v = $request->validate([
            'trx_type'           => ['required', 'in:in,out'],
            'trx_date'           => ['required', 'date'],
            'counter_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'payee'              => ['nullable', 'string', 'max:150'],
            'description'        => ['required', 'string', 'max:255'],
        ]);
        $v['cash_account_id'] = $this->pettyId();
        $v['auto_post'] = true; // Kas Kecil = dana float pra-otorisasi → langsung berjurnal & sinkron (GL + register), tanpa antrian approval BKK.

        try {
            $trx = $action->execute($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.pettycash.index')->with('success', "Kas Kecil {$trx->trx_number} tercatat — jurnal otomatis terbentuk.");
    }

    private function pettyId(): int
    {
        $id = BsAccount::where('code', self::PETTY_CODE)->value('id');
        if ($id) {
            return (int) $id;
        }

        // Self-heal: akun Kas Kecil belum terbentuk (mis. migrasi 000060 jalan sebelum tenant ada,
        // atau akun terhapus). Buat idempoten di bawah induk "KAS" (101010101), meniru migrasi.
        $parent = BsAccount::where('code', '101010101')->first(['id', 'tenant_id']);
        abort_if(! $parent, 500, 'Akun induk Kas (101010101) belum tersedia — jalankan seeder Chart of Account terlebih dahulu.');

        $petty = BsAccount::create([
            'tenant_id' => $parent->tenant_id, 'code' => self::PETTY_CODE, 'name' => 'KAS KECIL',
            'category_code' => '1', 'type' => 'asset', 'level' => 6, 'parent_code' => '101010101',
            'parent_id' => $parent->id, 'normal_balance' => 'D', 'is_postable' => true, 'is_active' => true,
        ]);

        return (int) $petty->id;
    }
}
