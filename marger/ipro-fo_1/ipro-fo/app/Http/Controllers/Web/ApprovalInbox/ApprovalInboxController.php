<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\ApprovalInbox;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval Inbox — tracking & keputusan approval bertingkat. Pengajuannya: Memo Urgent (form di
 * sini, dokumen MemoInternal) dan dari AP (TTD Berkas, Invoice Hutang, Koreksi Budget).
 * Visibilitas per baris diatur ApprovalInboxQuery (staf hanya melihat ajuannya sendiri).
 */
class ApprovalInboxController extends Controller
{
    public function __construct(private readonly ApprovalInboxQuery $inbox) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);

        $lists = [
            'perlu' => $this->inbox->needsMe($user),
            'pengajuan' => $this->inbox->mine($user),
            'riwayat' => $this->inbox->history($user),
        ];
        $tab = $request->input('tab');
        if (! array_key_exists((string) $tab, $lists)) {
            $tab = $lists['perlu']->isNotEmpty() ? 'perlu' : 'pengajuan';
        }

        // Cari (no. dokumen / perihal / keterangan / pengaju) & filter jenis dokumen, status, tanggal
        // diajukan — berlaku untuk ketiga tab, jumlah di tab ikut hasil saringan.
        $f = [
            'q' => trim((string) $request->input('q', '')),
            'jenis' => (string) $request->input('jenis', ''),
            'status' => (string) $request->input('status', ''),
            'dari' => (string) $request->input('dari', ''),
            'sampai' => (string) $request->input('sampai', ''),
        ];
        $kelompok = ['diproses' => ['pending', 'in_review'], 'disetujui' => ['approved'], 'ditolak' => ['rejected'], 'dibatalkan' => ['cancelled']];
        $cocok = function (array $r) use ($f, $kelompok): bool {
            if ($f['q'] !== '' && ! str_contains(mb_strtolower(implode(' ', [$r['number'], $r['subject'], $r['department'], $r['submitter'], $r['type']])), mb_strtolower($f['q']))) {
                return false;
            }
            if ($f['jenis'] !== '' && $r['document_type'] !== $f['jenis']) {
                return false;
            }
            if ($f['status'] !== '' && ! in_array($r['status'], $kelompok[$f['status']] ?? [], true)) {
                return false;
            }
            $tgl = $r['submitted_at'] ? substr((string) \Illuminate\Support\Carbon::parse($r['submitted_at'])->timezone(config('app.timezone'))->toDateString(), 0, 10) : null;

            return ($f['dari'] === '' || ($tgl && $tgl >= $f['dari'])) && ($f['sampai'] === '' || ($tgl && $tgl <= $f['sampai']));
        };
        $baris = array_map(fn ($l) => $l->map(fn (Approval $a) => $this->inbox->row($a))->filter($cocok)->values(), $lists);

        return Inertia::render('ApprovalInbox/Index', [
            'tab' => $tab,
            'counts' => array_map(fn ($l) => $l->count(), $baris),
            'rows' => $baris[$tab],
            'filters' => $f,
            'pilihanJenis' => \App\Domain\ApprovalInbox\Services\ApprovalInboxQuery::TYPE_LABELS,
            'canSubmit' => $user->can('approvalinbox.submit'),
        ]);
    }

    /** Form Memo Urgent (dokumen MemoInternal → Accounting → Finance → GM → Direktur → MW / BA). */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('approvalinbox.submit'), 403);

        return Inertia::render('ApprovalInbox/Create', [
            'today' => now()->toDateString(),
            'jenisMemo' => \App\Domain\ApprovalInbox\Models\MemoInternal::JENIS,
            // Alur yang AKAN dipakai per jenis = chain aktif di System › Approval Chain saat ini.
            'alur' => collect(\App\Domain\ApprovalInbox\Models\MemoInternal::JENIS)->map(function ($l, $jenis) {
                try {
                    return app(\App\Domain\Approval\Engine\ApprovalChainResolver::class)
                        ->resolve(\App\Domain\ApprovalInbox\Models\MemoInternal::DOCUMENT_TYPE, ['jenis' => $jenis])
                        ->load('steps')->steps->map(fn ($s) => $s->label ?: $s->approver_value)->values();
                } catch (BusinessRuleException) {
                    return [];
                }
            }),
            'departemen' => $this->pilihanDepartemen($request->user()),
            'semuaDept' => $request->user()->hasRole('SuperAdmin'),
            'suppliers' => $this->pilihanSupplier()->get(['id', 'code', 'name']),
            // Memo pengaju ini yang sudah dibayar di Kuitansi tapi invoice hutangnya belum masuk —
            // tampil merah; lewat batas hari → tidak bisa mengajukan memo baru.
            'belumInvoice' => ($bayar = app(\App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::class))->outstanding($request->user()->id),
            'batasHari' => $bayar->batasHari(),
        ]);
    }

    /**
     * Departemen yang boleh dipilih untuk Memo Urgent = cost center (unit kerja) karyawan yang
     * tertaut ke akun ini di Employee Center (utama lebih dulu); SuperAdmin boleh semua departemen.
     *
     * @return list<array{id:int, code:string, name:string}>
     */
    private function pilihanDepartemen(\App\Models\User $user): array
    {
        $q = \App\Domain\BusinessSupport\Models\BsCostCenter::query()->where('is_active', true);
        if (! $user->hasRole('SuperAdmin')) {
            $ids = app(\App\Domain\BusinessSupport\Services\CostCenterPengguna::class)->idsUntuk($user);
            if ($ids === []) {
                return [];
            }
            $q->whereIn('id', $ids)->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $ids)).')');
        } else {
            $q->orderBy('code');
        }

        return $q->get(['id', 'code', 'name'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->all();
    }

    /**
     * Supplier Memo Urgent = master supplier aktif yang sama dengan pilihan Invoice Hutang (tanpa
     * penerima Reimburse/Deposit), supaya memo bisa ditarik ke invoice supplier tersebut.
     */
    private function pilihanSupplier(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Domain\BusinessSupport\Models\BsSupplier::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', \App\Domain\BusinessSupport\Services\PenerimaHutang::KATEGORI))
            ->orderBy('name');
    }

    public function store(Request $request, SubmitMemoInternal $submit): RedirectResponse
    {
        abort_unless($request->user()?->can('approvalinbox.submit'), 403);

        if (app(\App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::class)->terblokir($request->user())) {
            return back()->withInput()->with('error', 'Masih ada Memo Urgent Anda yang sudah dibayar tapi invoice hutangnya belum dibuat lewat batas hari — buat invoicenya dulu.');
        }
        // Departemen dipilih dari daftar milik user (SuperAdmin: semua) — server menyimpan NAMANYA.
        $dept = collect($this->pilihanDepartemen($request->user()));
        if ($dept->isEmpty()) {
            return back()->withInput()->with('error', 'Departemen Anda belum diatur — isi cost center Anda di Data Center › Employee Center, atau hubungi HRD.');
        }
        $data = $request->validate([
            'memo_date' => 'required|date',
            // Form ini khusus Memo Urgent; jenis lain (Reguler) hanya bila dikirim eksplisit.
            'jenis' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Domain\ApprovalInbox\Models\MemoInternal::JENIS))],
            'cost_center_id' => ['required', 'integer', \Illuminate\Validation\Rule::in($dept->pluck('id')->all())],
            'supplier_id' => ['required', 'integer', fn ($a, $v, $fail) => $this->pilihanSupplier()->whereKey($v)->exists() || $fail('Supplier tidak ditemukan atau tidak aktif.')],
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            // Rincian pajak (sama dengan tahap 2 invoice); nominal = Dibayar (net), dihitung server.
            'dpp' => 'required|numeric|min:1',
            'ppn_mode' => 'required|in:none,with',
            'ppn_pct' => 'nullable|numeric|min:0|max:100',
            'ppn_incl' => 'nullable|boolean',
            'ppn_base' => 'nullable|numeric|min:0',
            'pph_mode' => 'required|in:none,withheld,borne',
            'pph_type' => 'nullable|required_unless:pph_mode,none|in:pph21,pph23,pph4_2',
            'pph_pct' => 'nullable|numeric|min:0|max:100',
            'pph_base' => 'nullable|numeric|min:0',
        ], [
            'dpp.required' => 'Nilai Transaksi wajib diisi.',
            'dpp.min' => 'Nilai Transaksi wajib diisi.',
            'pph_type.required_unless' => 'Pilih jenis PPh.',
            'cost_center_id.in' => 'Departemen ini bukan departemen Anda.',
            'supplier_id.required' => 'Pilih supplier.',
        ], ['cost_center_id' => 'Departemen', 'supplier_id' => 'Supplier', 'dpp' => 'Nilai Transaksi']);
        $data['ppn_incl'] = (bool) ($data['ppn_incl'] ?? false);
        $data['jenis'] ??= 'urgent';
        $data['department'] = $dept->firstWhere('id', (int) $data['cost_center_id'])['name'];
        unset($data['cost_center_id']);

        try {
            $memo = $submit->execute($data);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $jenis = \App\Domain\ApprovalInbox\Models\MemoInternal::JENIS[$memo->jenis];
        $pertama = \App\Domain\ApprovalInbox\Models\MemoInternal::labelTahap(
            \App\Domain\Approval\Models\ApprovalStep::query()->where('approval_id', $memo->approval_id)->orderBy('level')->first());

        return redirect()->route('approval-inbox.memo')
            ->with('success', "{$jenis} {$memo->memo_number} berhasil diajukan — menunggu ".($pertama ?? 'approval').'.');
    }

    public function show(Request $request, Approval $approval): Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);
        abort_unless(in_array($approval->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true), 404);
        $approval->load('steps')->loadMorph('approvable', [
            \App\Domain\BusinessSupport\Models\BsApBerkasUpload::class => ['supplier:id,name', 'files.jenis:id,name'],
            \App\Domain\BusinessSupport\Models\BsApInvoice::class => ['supplier:id,name'],
            \App\Domain\BusinessSupport\Models\BsBudgetCorrection::class => ['account:id,code,name', 'costCenter:id,code,name', 'budget'],
            \App\Domain\ApprovalInbox\Models\MemoInternal::class => ['supplier:id,name'],
        ]);
        abort_unless($this->inbox->canView($user, $approval), 403);

        return Inertia::render('ApprovalInbox/Show', [
            'approval' => $this->inbox->row($approval),
            'doc' => $this->inbox->detail($approval),
            'stages' => $this->inbox->stages($approval),
            'canAct' => $this->inbox->canAct($user, $approval),
            // Memo Urgent: preview lembar memo + TTD tiap tahap yang sudah menyetujui.
            'memo' => $this->lembarMemo($approval),
        ]);
    }

    /** Tutup pop-up pengingat Memo Urgent dibayar tanpa invoice — muncul lagi di login berikutnya. */
    public function tutupPengingat(Request $request): RedirectResponse
    {
        $request->session()->forget(\App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::SESI_PENGINGAT);

        return back();
    }

    /** Pilihan filter Pembayaran di daftar Memo Urgent. */
    private const FILTER_BAYAR = ['belum' => 'Belum dibayar', 'dibayar' => 'Dibayar, belum diinvoice', 'diinvoice' => 'Sudah diinvoice'];

    /**
     * Daftar Memo Urgent yang diajukan user ini: cari (no. memo / perihal / supplier / departemen),
     * filter status approval, pembayaran (Kuitansi / Invoice Hutang) & rentang tanggal memo.
     */
    public function memoSaya(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.submit'), 403);
        $f = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'bayar' => (string) $request->input('bayar', ''),
            'dari' => (string) $request->input('dari', ''),
            'sampai' => (string) $request->input('sampai', ''),
        ];
        $dibayar = \App\Domain\BusinessSupport\Models\BsApKuitansi::query()->select('memo_internal_id')->whereNotNull('memo_internal_id')->where('status', 'active');
        $diinvoice = \App\Domain\BusinessSupport\Models\BsApInvoice::query()->select('memo_internal_id')->whereNotNull('memo_internal_id')
            ->where('status', '!=', \App\Domain\BusinessSupport\Models\BsApInvoice::STATUS_BATAL);

        $dasar = \App\Domain\ApprovalInbox\Models\MemoInternal::query()->where('created_by', $user->id)
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('memo_number', 'like', "%{$f['q']}%")->orWhere('subject', 'like', "%{$f['q']}%")
                ->orWhere('department', 'like', "%{$f['q']}%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$f['q']}%"))))
            ->when($f['bayar'] === 'belum', fn ($q) => $q->whereNotIn('id', $dibayar)->whereNotIn('id', $diinvoice))
            ->when($f['bayar'] === 'dibayar', fn ($q) => $q->whereIn('id', $dibayar)->whereNotIn('id', $diinvoice))
            ->when($f['bayar'] === 'diinvoice', fn ($q) => $q->whereIn('id', $diinvoice))
            ->when($f['dari'] !== '', fn ($q) => $q->whereDate('memo_date', '>=', $f['dari']))
            ->when($f['sampai'] !== '', fn ($q) => $q->whereDate('memo_date', '<=', $f['sampai']));
        // Jumlah per status (setelah filter lain) untuk tab Semua / Diproses / Disetujui / Ditolak.
        $jumlah = (clone $dasar)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $idDibayar = (clone $dibayar)->pluck('memo_internal_id')->flip();
        $idInvoice = (clone $diinvoice)->pluck('memo_internal_id')->flip();
        $memos = (clone $dasar)->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->with(['approval.steps', 'supplier:id,name'])->orderByDesc('memo_date')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn ($m) => [
                'id' => $m->id, 'number' => $m->memo_number, 'date' => $m->memo_date?->toDateString(), 'jenis' => \App\Domain\ApprovalInbox\Models\MemoInternal::JENIS[$m->jenis] ?? 'Memo',
                'subject' => $m->subject, 'department' => $m->department, 'supplier' => $m->supplier?->name, 'amount' => (float) $m->amount,
                'status' => $m->status, 'posisi' => \App\Domain\ApprovalInbox\Services\MemoUntukInvoice::status($m),
                'bayar' => isset($idInvoice[$m->id]) ? 'diinvoice' : (isset($idDibayar[$m->id]) ? 'dibayar' : 'belum'),
                'url' => $m->approval_id ? route('approval-inbox.show', $m->approval_id) : null,
                'cetak' => $m->approval_id ? route('approval-inbox.cetak', $m->approval_id) : null,
            ]);

        return Inertia::render('ApprovalInbox/MemoSaya', [
            'memos' => $memos, 'filters' => $f, 'pilihanBayar' => self::FILTER_BAYAR,
            'jumlah' => ['semua' => (int) $jumlah->sum(), 'diajukan' => (int) ($jumlah['diajukan'] ?? 0),
                'disetujui' => (int) ($jumlah['disetujui'] ?? 0), 'ditolak' => (int) ($jumlah['ditolak'] ?? 0)],
        ]);
    }

    /** Cetak lembar Memo Urgent (HTML siap-cetak) — TTD sesuai tahap approval yang sudah dilalui. */
    public function cetak(Request $request, Approval $approval): \Illuminate\Http\Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);
        abort_unless($approval->document_type === \App\Domain\ApprovalInbox\Models\MemoInternal::DOCUMENT_TYPE, 404);
        $approval->load('steps')->loadMorph('approvable', [\App\Domain\ApprovalInbox\Models\MemoInternal::class => ['supplier:id,name']]);
        abort_unless($this->inbox->canView($user, $approval), 403);

        return response(view('pdf.memo-urgent', [
            'memo' => $this->lembarMemo($approval),
            'stages' => $this->inbox->stages($approval),
            'status' => $approval->status,
            'dicetakOleh' => $user->name,
            'pembayaran' => $this->pembayaranMemo($approval),
            // ?preview=1 — ditampilkan di halaman detail (iframe), tanpa membuka dialog cetak.
            'preview' => $request->boolean('preview'),
        ])->render());
    }

    /**
     * Finance tidak menyetujui Memo Urgent — Finance yang MEMBAYARKAN. Kolom TTD paling kanan lembar
     * memo terisi begitu memo dibayar (kuitansi Bayar Memo Urgent aktif): pembuat kuitansinya.
     *
     * @return array{oleh:?string, ttd:?string, tanggal:?string, nomor:string}|null
     */
    private function pembayaranMemo(Approval $approval): ?array
    {
        $kui = app(\App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::class)->kuitansiMemo((int) $approval->approvable_id);
        if (! $kui) {
            return null;
        }
        $kasir = \App\Models\User::query()->find($kui->created_by);

        return ['oleh' => $kasir?->name, 'ttd' => $kasir?->signatureUrl(), 'tanggal' => $kui->kuitansi_date?->format('d/m/Y'), 'nomor' => $kui->kuitansi_number];
    }

    /** "Nilai Transaksi Rp … · PPN 11% Rp … · PPh 23 dipotong Rp …" — null untuk memo lama tanpa rincian. */
    private function rincianPajak(\App\Domain\ApprovalInbox\Models\MemoInternal $m): ?string
    {
        $p = $m->pajak();
        if (! $p) {
            return null;
        }
        $h = \App\Domain\BusinessSupport\Support\PajakHutang::hitung($p);
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',').'%';
        $bag = ['DPP '.$rp($h['dpp'])];
        if ($p['ppn_mode'] === 'with') {
            $bag[] = 'PPN '.$pct($h['ppn_pct']).($p['ppn_incl'] ? ' (include)' : '').' '.$rp($h['ppn']);
        }
        if ($p['pph_mode'] !== 'none') {
            $jenis = ['pph21' => 'PPh 21', 'pph23' => 'PPh 23', 'pph4_2' => 'PPh 4(2)'][$p['pph_type']] ?? 'PPh';
            $bag[] = $jenis.' '.$pct($h['pph_pct']).($p['pph_mode'] === 'borne' ? ' ditanggung ' : ' dipotong ').$rp($h['pph_journal']);
        }

        return implode(' · ', $bag);
    }

    /** Isi lembar Memo Urgent untuk preview & cetak; null bila dokumennya bukan memo. */
    private function lembarMemo(Approval $approval): ?array
    {
        $m = $approval->approvable;
        if (! $m instanceof \App\Domain\ApprovalInbox\Models\MemoInternal) {
            return null;
        }

        return [
            'number' => $m->memo_number,
            'jenis' => \App\Domain\ApprovalInbox\Models\MemoInternal::JENIS[$m->jenis] ?? 'Memo Internal',
            'date' => $m->memo_date?->toDateString(),
            'department' => $m->department,
            'pengaju' => \App\Models\User::query()->whereKey($m->created_by)->value('name'),
            'supplier' => $m->supplier?->name,
            'subject' => (string) $m->subject,
            'body' => (string) $m->body,
            'amount' => $m->amount !== null ? (float) $m->amount : null,
            'rincian' => $this->rincianPajak($m),
            // Rincian lengkap untuk lembar cetak: Nilai Transaksi, PPN, PPh, Total Bayar (= nominal memo).
            'pajak' => ($p = $m->pajak()) ? \App\Domain\BusinessSupport\Support\PajakHutang::hitung($p) + [
                'ppn_mode' => $p['ppn_mode'], 'pph_mode' => $p['pph_mode'], 'pph_type' => $p['pph_type'],
                'nilai_transaksi' => (float) $p['dpp'],
            ] : null,
            'terbilang' => $m->amount !== null ? mb_strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round((float) $m->amount))))).' RUPIAH' : null,
            'perusahaan' => (string) (\Illuminate\Support\Facades\DB::table('parameters')->where('tenant_id', $m->tenant_id)
                ->where('group', 'company')->where('key', 'name')->value('value') ?: 'PT MUP'),
            'cetak_url' => route('approval-inbox.cetak', $approval->id),
        ];
    }

    public function act(Request $request, Approval $approval, ActOnApproval $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);
        abort_unless(in_array($approval->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true), 404);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|required_if:action,reject|string|max:500',
        ], ['notes.required_if' => 'Alasan penolakan wajib diisi.']);

        try {
            $action->execute($approval->load('steps'), $data['action'], $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $done = $data['action'] === 'approve' ? 'disetujui' : 'ditolak';

        return redirect()->route('approval-inbox.index', ['tab' => 'perlu'])
            ->with('success', "Dokumen #{$approval->id} {$done}.");
    }
}
