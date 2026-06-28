<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        // Filter periode tanggal — default bulan berjalan; mencakup SEMUA jenis sumber jurnal.
        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        $rows = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->withCount('lines')
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Gl/Journals/Index', [
            'journals' => $rows,
            'sources'  => BsJournal::SOURCES,
            'statuses' => BsJournal::STATUSES,
            'filters'  => array_merge(
                $request->only(['source', 'status', 'search']),
                ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            ),
        ]);
    }

    /** Export Excel: semua info Detail Transaksi Jurnal (header + semua baris akun) mengikuti filter layar. */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        $journals = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->get();

        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $rp = fn ($v) => number_format((float) ($v ?? 0), 2, ',', '.');
        $src = BsJournal::SOURCES;
        $st  = BsJournal::STATUSES;

        $h = '<table border="1"><tr style="background:#0f766e;color:#fff">'
            .'<th>No. Jurnal</th><th>Tanggal</th><th>Sumber</th><th>Status</th><th>Keterangan</th><th>Referensi</th>'
            .'<th>Cost Center</th><th>Kode Akun</th><th>Nama Akun</th><th>Keterangan Baris</th><th>Debit</th><th>Kredit</th></tr>';
        $totalD = 0.0;
        $totalK = 0.0;
        foreach ($journals as $j) {
            foreach ($j->lines as $ln) {
                $totalD += (float) $ln->debit;
                $totalK += (float) $ln->credit;
                $h .= '<tr>'
                    .'<td>'.$e($j->journal_number).'</td>'
                    .'<td>'.$e(optional($j->journal_date)->toDateString()).'</td>'
                    .'<td>'.$e($src[$j->source] ?? $j->source).'</td>'
                    .'<td>'.$e($st[$j->status] ?? $j->status).'</td>'
                    .'<td>'.$e($j->description).'</td>'
                    .'<td>'.$e($j->reference).'</td>'
                    .'<td>'.$e($j->costCenter?->name).'</td>'
                    .'<td>'.$e($ln->account?->code).'</td>'
                    .'<td>'.$e($ln->account?->name).'</td>'
                    .'<td>'.$e($ln->description).'</td>'
                    .'<td>'.$rp($ln->debit).'</td>'
                    .'<td>'.$rp($ln->credit).'</td>'
                    .'</tr>';
            }
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="10">TOTAL</td><td>'.$rp($totalD).'</td><td>'.$rp($totalK).'</td></tr></table>';

        $file = 'Detail-Transaksi-Jurnal-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>DETAIL TRANSAKSI JURNAL</h3><p>Periode: '.$e($from->toDateString()).' s/d '.$e($to->toDateString()).'</p>'
            .$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $v = $request->validate([
            'journal_date'   => ['required', 'date'],
            'reference'      => ['nullable', 'string', 'max:150'],
            'description'    => ['required', 'string', 'max:255'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines'                 => ['required', 'array', 'min:2'],
            'lines.*.account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            'lines.*.debit'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.description'   => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $journal = $svc->post([
                'journal_date'   => $v['journal_date'],
                'source'         => 'memorial',
                'status'         => 'draft', // jurnal memorial manual mulai sebagai Baru
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
                'created_by'     => $request->user()->id,
            ], $v['lines']);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', "Jurnal {$journal->journal_number} dibuat sebagai Baru — finalisasi & approve agar efektif di GL.");
    }

    public function show(Request $request, BsJournal $journal): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Show', [
            'journal'  => $journal->load(['lines.account:id,code,name', 'costCenter:id,code,name']),
            'sources'  => BsJournal::SOURCES,
            'statuses' => BsJournal::STATUSES,
        ]);
    }

    /** #54: form edit jurnal (koreksi pencatatan). */
    public function edit(Request $request, BsJournal $journal): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'editing'     => array_merge(
                $journal->only(['id', 'journal_number', 'journal_date', 'reference', 'description', 'cost_center_id', 'source', 'status']),
                ['lines' => $journal->lines()->get(['account_id', 'description', 'debit', 'credit'])->toArray()],
            ),
        ]);
    }

    /** #54: simpan perubahan jurnal (ganti baris, tetap balance). */
    public function update(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $v = $request->validate([
            'journal_date'        => ['required', 'date'],
            'reference'           => ['nullable', 'string', 'max:150'],
            'description'         => ['required', 'string', 'max:255'],
            'cost_center_id'      => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $svc->update($journal, [
                'journal_date'   => $v['journal_date'],
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
            ], $v['lines']);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', "Jurnal {$journal->journal_number} diperbarui.");
    }

    public function finalize(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->transition(fn () => $svc->finalize($journal), $journal, 'Jurnal difinalisasi (Final).');
    }

    public function approve(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->transition(fn () => $svc->approve($journal, $request->user()->id), $journal, 'Jurnal di-approve — efektif di GL.');
    }

    public function reject(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->transition(fn () => $svc->reject($journal), $journal, 'Jurnal dikembalikan ke Baru.');
    }

    private function transition(callable $fn, BsJournal $journal, string $message): RedirectResponse
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', $message);
    }
}
