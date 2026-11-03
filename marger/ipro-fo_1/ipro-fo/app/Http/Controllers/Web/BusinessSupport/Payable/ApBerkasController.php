<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApInvoiceDraft;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\Foundation\Audit\Models\AuditLog;
use App\Exceptions\BusinessRuleException;
use App\Exports\SheetXlsx;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Account Payable › Master › Upload Berkas.
 *
 *   Berkas Saya          : yang saya unggah (semua staf, ap.berkas.upload)
 *   Siap Dibuat Invoice  : berkas SAYA yang sudah TTD & masih ada sisa nominal (pemegang ap.invoice.create)
 *   Semua Berkas         : seluruh berkas (ap.berkas.view / ap.invoice.view)
 *
 * Hak per aksi: ubah/ajukan/batal/hapus — pengunggah (milik sendiri) atau ap.berkas.manage;
 * tutup parsial & lihat yang dihapus — ap.berkas.manage; export — ap.berkas.export.
 * TTD Dept Head & approval invoice dilakukan di Approval Inbox.
 */
class ApBerkasController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly ApBerkasService $service) {}

    // ── DAFTAR ───────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('ap.berkas.upload') || $this->bisaLihatSemua($user), 403);

        $tabs = $this->tabs($user);
        $tab = (string) $request->input('tab');
        if (! array_key_exists($tab, $tabs)) {
            $tab = ($tabs['siap'] ?? null) && $this->scope($user, 'siap')->count() > 0 ? 'siap' : 'saya';
        }
        $filters = $request->only(['q', 'status', 'supplier_id', 'from', 'to', 'trashed']);

        $statusCounts = $this->filtered($user, $tab, collect($filters)->except('status')->all())
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $rows = $this->filtered($user, $tab, $filters)
            ->with(['supplier:id,name', 'deptHead:id,name', 'uploader:id,name'])->withCount('files')
            ->latest('id')->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (BsApBerkasUpload $b) => $this->row($b, $user));

        return Inertia::render('BusinessSupport/Payable/Berkas/Index', [
            'tab' => $tab,
            'tabs' => $tabs,
            'tabCounts' => collect($tabs)->map(fn ($l, $k) => $this->scope($user, $k)->count()),
            'statusCounts' => $statusCounts,
            'statusLabels' => BsApBerkasUpload::STATUS_LABELS,
            'suppliers' => BsSupplier::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'rows' => $rows,
            // Dari tombol "Edit" berkas di Registrasi Invoice Hutang: daftar ini langsung meneruskan
            // ke halaman edit berkasnya, sehingga Back dari halaman edit kembali ke sini.
            'lanjutEdit' => $this->lanjutEdit($request, $user),
            'can' => [
                'upload' => $user->can('ap.berkas.upload'),
                'export' => $user->can('ap.berkas.export'),
                'trashed' => $user->can('ap.berkas.manage'),
            ],
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $user = $request->user();
        abort_unless($user?->can('ap.berkas.export'), 403);
        $tab = (string) $request->input('tab', 'semua');
        abort_unless(array_key_exists($tab, $this->tabs($user)), 403);
        $filters = $request->only(['q', 'status', 'supplier_id', 'from', 'to', 'trashed']);

        $rows = $this->filtered($user, $tab, $filters)
            ->with(['supplier:id,name', 'deptHead:id,name', 'uploader:id,name'])
            ->latest('id')->limit(5000)->get();
        $head = ['No. Berkas', 'Tgl Unggah', 'Tgl Tagihan', 'Jatuh Tempo', 'Supplier', 'No. Tagihan', 'Keterangan',
            'Total', 'Diinvoice', 'Status', 'Pengunggah', 'Dept Head'];
        $data = $rows->map(fn (BsApBerkasUpload $b) => [
            $b->berkas_number, $b->upload_date?->format('d/m/Y'), $b->document_date?->format('d/m/Y'),
            $b->needed_date?->format('d/m/Y'), $b->supplier?->name, $b->supplier_invoice_no, $b->description,
            $b->amount !== null ? (float) $b->amount : null, $b->invoicedTotal(),
            (BsApBerkasUpload::STATUS_LABELS[$b->status] ?? $b->status).($b->trashed() ? ' (dihapus)' : ''),
            $b->uploader?->name, $b->deptHead?->name,
        ])->all();
        $file = 'Upload-Berkas-'.now()->format('Ymd-Hi');

        if ($request->input('format') === 'pdf') {
            return response($this->htmlDaftar($head, $data, $filters));
        }

        return (new SheetXlsx([$head, ...$data], 1, 'Upload Berkas'))->toResponse($file.'.xlsx');
    }

    // ── FORM ─────────────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.berkas.upload'), 403);

        return Inertia::render('BusinessSupport/Payable/Berkas/Form', $this->formProps($request->user()) + [
            'berkas' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->can('ap.berkas.upload'), 403);
        $data = $request->validate($this->rules(true), $this->messages(), $this->attributes());

        try {
            $berkas = $this->service->create(
                collect($data)->except(['files', 'ajukan'])->all(),
                $this->filesDari($data),
                $user,
                (bool) ($data['ajukan'] ?? false),
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.berkas.show', $berkas->id)->with('success', $berkas->status === BsApBerkasUpload::STATUS_DRAFT
            ? "Berkas {$berkas->berkas_number} tersimpan sebagai draft."
            : "Berkas {$berkas->berkas_number} diajukan — menunggu TTD Dept Head.");
    }

    public function edit(Request $request, BsApBerkasUpload $berkas): Response
    {
        $user = $request->user();
        abort_unless($this->bolehUbah($user, $berkas), 403);
        $berkas->load('files.jenis:id,name');

        return Inertia::render('BusinessSupport/Payable/Berkas/Form', $this->formProps($user) + [
            'draftInvoice' => $this->adaDraftInvoice($user, $berkas),
            'berkas' => $this->detail($berkas) + [
                'files' => $this->files($berkas),
                'form' => $berkas->only(['supplier_id', 'supplier_invoice_no', 'description']) + [
                        'document_date' => $berkas->document_date?->toDateString(),
                        'needed_date' => $berkas->needed_date?->toDateString(),
                        'amount' => $berkas->amount !== null ? (float) $berkas->amount : null,
                    ],
            ],
        ]);
    }

    public function update(Request $request, BsApBerkasUpload $berkas): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->bolehUbah($user, $berkas), 403);
        $data = $request->validate($this->rules(false), $this->messages(), $this->attributes());

        try {
            $this->service->update($berkas, collect($data)->except(['files', 'ajukan'])->all(), $user);
            if ($files = $this->filesDari($data)) {
                $this->service->addFiles($berkas, $files, $user);
            }
            if ($data['ajukan'] ?? false) {
                $this->service->submit($berkas, $user);
            }
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.berkas.show', $berkas->id)
            ->with('success', ($data['ajukan'] ?? false) ? 'Berkas diajukan — menunggu TTD Dept Head.' : 'Perubahan disimpan.');
    }

    // ── AKSI ─────────────────────────────────────────────────────────────────────

    public function submit(Request $request, BsApBerkasUpload $berkas): RedirectResponse
    {
        abort_unless($this->bolehUbah($request->user(), $berkas), 403);

        return $this->jalankan(fn () => $this->service->submit($berkas, $request->user()), 'Berkas diajukan — menunggu TTD Dept Head.');
    }

    public function cancel(Request $request, BsApBerkasUpload $berkas): RedirectResponse
    {
        abort_unless($this->milikAtauKelola($request->user(), $berkas), 403);
        $data = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Alasan batal wajib diisi.']);

        return $this->jalankan(fn () => $this->service->cancel($berkas, $request->user(), $data['reason']), 'Berkas dibatalkan.');
    }

    public function close(Request $request, BsApBerkasUpload $berkas): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.berkas.manage'), 403);
        $data = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Alasan tutup wajib diisi.']);

        return $this->jalankan(fn () => $this->service->close($berkas, $request->user(), $data['reason']), 'Berkas ditutup — sisa nominal tidak akan diinvoice.');
    }

    public function destroy(Request $request, BsApBerkasUpload $berkas): RedirectResponse
    {
        abort_unless($this->milikAtauKelola($request->user(), $berkas), 403);
        try {
            $this->service->delete($berkas, $request->user());
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.berkas.index')->with('success', "Berkas {$berkas->berkas_number} dihapus.");
    }

    public function removeFile(Request $request, BsApBerkasUpload $berkas, BsApBerkasUploadFile $file): RedirectResponse
    {
        abort_unless($this->bolehUbah($request->user(), $berkas), 403);

        return $this->jalankan(fn () => $this->service->removeFile($berkas, $file, $request->user()), 'File dihapus.');
    }

    // ── LIHAT ────────────────────────────────────────────────────────────────────

    public function show(Request $request, BsApBerkasUpload $berkas): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user, $berkas), 403);
        $berkas->load(['files.jenis:id,name', 'supplier:id,name', 'deptHead:id,name', 'uploader:id,name']);

        $invoices = $berkas->invoices()->orderBy('id')->get(['id', 'invoice_number', 'total', 'status', 'invoice_date']);
        $approvals = Approval::query()->where('document_type', BsApInvoice::APPROVAL_DOCUMENT_TYPE)
            ->where('approvable_type', (new BsApInvoice)->getMorphClass())->whereIn('approvable_id', $invoices->pluck('id'))
            ->get()->keyBy('approvable_id');

        return Inertia::render('BusinessSupport/Payable/Berkas/Show', [
            'berkas' => $this->detail($berkas) + ['files' => $this->files($berkas)],
            'wajibKurang' => $this->service->berkasWajibKurang($berkas),
            'jenisChecklist' => BsApBerkas::query()->where('is_active', true)->orderByDesc('is_mandatory')->orderBy('id')->get(['name', 'is_mandatory']),
            'invoices' => $invoices->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->invoice_number, 'total' => (float) $i->total, 'status' => $i->status,
                'date' => $i->invoice_date?->toDateString(),
                'approval' => isset($approvals[$i->id]) ? ['status' => $approvals[$i->id]->status, 'url' => route('approval-inbox.show', $approvals[$i->id]->id)] : null,
            ]),
            'ttdApprovalUrl' => $berkas->approval_id ? route('approval-inbox.show', $berkas->approval_id) : null,
            'timeline' => $this->timeline($berkas),
            'draftInvoice' => $this->adaDraftInvoice($user, $berkas),
            'can' => $this->aksi($user, $berkas),
        ]);
    }

    public function file(Request $request, BsApBerkasUpload $berkas, BsApBerkasUploadFile $file): HttpResponse
    {
        abort_unless((int) $file->berkas_upload_id === (int) $berkas->id, 404);
        abort_unless($this->canView($request->user(), $berkas), 403);
        abort_unless(Storage::disk(ApBerkasService::DISK)->exists($file->path), 404);

        return response()->file(Storage::disk(ApBerkasService::DISK)->path($file->path), [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'inline; filename="'.addcslashes($file->original_name, '"\\').'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Cetak berkas (HTML siap-cetak → simpan PDF dari dialog cetak) dengan kotak TTD. */
    public function print(Request $request, BsApBerkasUpload $berkas): HttpResponse
    {
        abort_unless($this->canView($request->user(), $berkas), 403);
        $berkas->load(['files.jenis:id,name', 'supplier:id,name', 'deptHead', 'uploader']);

        return response(view('pdf.berkas-upload', [
            'b' => $berkas,
            'invoices' => $berkas->invoices()->orderBy('id')->get(),
            'ttd' => $this->kotakTtd($berkas),
            'statusLabel' => BsApBerkasUpload::STATUS_LABELS[$berkas->status] ?? $berkas->status,
            'dicetakOleh' => $request->user()?->name,
        ])->render());
    }

    // ── internal: hak akses ──────────────────────────────────────────────────────

    private function bisaLihatSemua(?User $u): bool
    {
        return (bool) ($u?->can('ap.berkas.view') || $u?->can('ap.invoice.view'));
    }

    private function milikAtauKelola(?User $u, BsApBerkasUpload $b): bool
    {
        return $u !== null && ((int) $b->uploaded_by === (int) $u->id || $u->can('ap.berkas.manage'));
    }

    /** ?lanjut_edit={id} → id berkas yang langsung dibuka halaman edit-nya (hanya yang boleh diubah user). */
    private function lanjutEdit(Request $request, User $user): ?int
    {
        $id = (int) $request->input('lanjut_edit');
        $b = $id > 0 ? BsApBerkasUpload::query()->find($id) : null;

        return $b && $this->bolehUbah($user, $b) ? $b->id : null;
    }

    /** User punya draft Registrasi Invoice Hutang yang memakai berkas ini. */
    private function adaDraftInvoice(User $user, BsApBerkasUpload $b): bool
    {
        return BsApInvoiceDraft::query()->where('user_id', $user->id)->where('jenis', 'invoice')
            ->where('berkas_upload_id', $b->id)->exists();
    }

    private function bolehUbah(?User $u, BsApBerkasUpload $b): bool
    {
        return $this->milikAtauKelola($u, $b) && $b->bisaDiubah();
    }

    /** Pengunggah, Dept Head-nya, tim AP, approver invoice-nya, atau SuperAdmin. */
    private function canView(?User $user, BsApBerkasUpload $berkas): bool
    {
        if (! $user) {
            return false;
        }
        if ((int) $berkas->uploaded_by === (int) $user->id || (int) $berkas->dept_head_id === (int) $user->id
            || $this->bisaLihatSemua($user) || $user->hasRole('SuperAdmin')) {
            return true;
        }
        $inbox = app(ApprovalInboxQuery::class);
        foreach ($berkas->invoices()->get() as $inv) {
            $a = $inv->approvalPembayaran();
            if ($a && $inbox->canView($user, $a->load('steps'))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, bool> tombol yang boleh tampil untuk berkas ini */
    private function aksi(User $u, BsApBerkasUpload $b): array
    {
        $milikKelola = $this->milikAtauKelola($u, $b);
        $tanpaInvoice = ! $b->invoices()->exists();

        return [
            'edit' => $this->bolehUbah($u, $b),
            'submit' => $this->bolehUbah($u, $b),
            'cancel' => $milikKelola && ! $b->trashed() && $tanpaInvoice && in_array($b->status, [
                BsApBerkasUpload::STATUS_DRAFT, BsApBerkasUpload::STATUS_MENUNGGU_TTD, BsApBerkasUpload::STATUS_SIAP_INVOICE, BsApBerkasUpload::STATUS_DITOLAK], true),
            'close' => $u->can('ap.berkas.manage') && $b->status === BsApBerkasUpload::STATUS_SEBAGIAN && ! $b->trashed(),
            'delete' => $milikKelola && ! $b->trashed() && in_array($b->status, [
                BsApBerkasUpload::STATUS_DRAFT, BsApBerkasUpload::STATUS_DITOLAK, BsApBerkasUpload::STATUS_DIBATALKAN], true),
            // Invoice Hutang wajib memakai berkas unggahan pengaju sendiri.
            'invoice' => $u->can('ap.invoice.create') && $b->bisaDiinvoice() && (int) $b->uploaded_by === (int) $u->id,
        ];
    }

    // ── internal: query ──────────────────────────────────────────────────────────

    /** @return array<string, string> tab yang tersedia untuk user ini */
    private function tabs(User $u): array
    {
        $tabs = ['saya' => 'Berkas Saya'];
        if ($u->can('ap.invoice.create')) {
            $tabs['siap'] = 'Siap Dibuat Invoice';
        }
        if ($this->bisaLihatSemua($u)) {
            $tabs['semua'] = 'Semua Berkas';
        }

        return $tabs;
    }

    private function scope(User $u, string $tab): Builder
    {
        $q = BsApBerkasUpload::query();

        return match ($tab) {
            // Invoice Hutang wajib memakai berkas unggahan sendiri → yang siap pun hanya milik sendiri.
            'siap' => $q->where('uploaded_by', $u->id)->whereIn('status', [BsApBerkasUpload::STATUS_SIAP_INVOICE, BsApBerkasUpload::STATUS_SEBAGIAN]),
            'semua' => $q,
            default => $q->where('uploaded_by', $u->id),
        };
    }

    private function filtered(User $u, string $tab, array $f): Builder
    {
        $q = $this->scope($u, $tab);
        if (! empty($f['trashed']) && $u->can('ap.berkas.manage')) {
            $q->withTrashed();
        }

        return $q
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['supplier_id'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('upload_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('upload_date', '<=', $d))
            ->when(trim((string) ($f['q'] ?? '')), function ($q, $s) {
                $like = '%'.$s.'%';
                $q->where(fn ($w) => $w->where('berkas_number', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhere('supplier_invoice_no', 'like', $like)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like))
                    ->orWhereHas('uploader', fn ($s) => $s->where('name', 'like', $like)));
            });
    }

    // ── internal: bentuk data ────────────────────────────────────────────────────

    private function row(BsApBerkasUpload $b, User $u): array
    {
        return [
            'id' => $b->id,
            'number' => $b->berkas_number,
            'date' => $b->upload_date?->toDateString(),
            'document_date' => $b->document_date?->toDateString(),
            'needed_date' => $b->needed_date?->toDateString(),
            'supplier' => $b->supplier?->name,
            'supplier_invoice_no' => $b->supplier_invoice_no,
            'amount' => $b->amount !== null ? (float) $b->amount : null,
            'description' => $b->description,
            'status' => $b->status,
            'deleted' => $b->trashed(),
            'dept_head' => $b->deptHead?->name,
            'uploader' => $b->uploader?->name,
            'files_count' => $b->files_count,
            'reason' => $b->cancel_reason ?? $b->close_reason,
            'can' => $this->aksi($u, $b),
        ];
    }

    private function detail(BsApBerkasUpload $b): array
    {
        return [
            'id' => $b->id,
            'number' => $b->berkas_number,
            'status' => $b->status,
            'deleted' => $b->trashed(),
            'upload_date' => $b->upload_date?->toDateString(),
            'document_date' => $b->document_date?->toDateString(),
            'needed_date' => $b->needed_date?->toDateString(),
            'supplier' => $b->supplier?->name,
            'supplier_invoice_no' => $b->supplier_invoice_no,
            'description' => $b->description,
            'amount' => $b->amount !== null ? (float) $b->amount : null,
            'invoiced' => $b->invoicedTotal(),
            'sisa' => $b->sisa(),
            'dept_head' => $b->deptHead?->name,
            'uploader' => $b->uploader?->name,
            'cancel_reason' => $b->cancel_reason,
            'close_reason' => $b->close_reason,
        ];
    }

    private function files(BsApBerkasUpload $b): array
    {
        return $b->files->map(fn (BsApBerkasUploadFile $f) => [
            'id' => $f->id,
            'name' => $f->original_name,
            'jenis' => $f->jenis?->name,
            'size' => $f->size,
            'mime' => $f->mime,
            'url' => route('bs.ap.berkas.file', [$b->id, $f->id]),
        ])->all();
    }

    /** Riwayat: peristiwa (berkas.*) + perubahan isian (HasAuditTrail), terbaru dulu. */
    private function timeline(BsApBerkasUpload $b): array
    {
        $label = [
            'created' => 'Dibuat', 'updated' => 'Isian diubah', 'soft_deleted' => 'Dihapus', 'deleted' => 'Dihapus',
            'berkas.diajukan' => 'Diajukan ke Dept Head', 'berkas.diajukan_ulang' => 'Diajukan ulang (revisi)',
            'berkas.dibatalkan' => 'Dibatalkan', 'berkas.ditutup' => 'Ditutup parsial', 'berkas.dihapus' => 'Dihapus',
            'berkas.file_ditambah' => 'File ditambah', 'berkas.file_dihapus' => 'File dihapus', 'berkas.diinvoice' => 'Dibuat invoice', 'berkas.diunggah_supplier' => 'Diunggah supplier lewat link',
        ];
        $abaikan = ['updated_at', 'created_at', 'approval_id', 'ap_invoice_id', 'submitted_at', 'status', 'ppn_amount'];
        $logs = AuditLog::query()->where('auditable_type', $b->getMorphClass())->where('auditable_id', $b->id)
            ->latest('created_at')->latest('id')->limit(200)->get();
        $users = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        $out = [];
        foreach ($logs as $l) {
            $ubah = [];
            if ($l->event === 'updated') {
                foreach ((array) $l->new_values as $k => $v) {
                    if (! in_array($k, $abaikan, true)) {
                        $ubah[] = ['field' => $k, 'old' => ((array) $l->old_values)[$k] ?? null, 'new' => $v];
                    }
                }
                if (! $ubah) {
                    continue; // hanya perubahan teknis (status/stempel waktu) — sudah tercermin di peristiwa lain
                }
            }
            $out[] = [
                'event' => $label[$l->event] ?? $l->event,
                'at' => \Illuminate\Support\Carbon::parse($l->created_at)->toIso8601String(),
                'by' => $users[$l->user_id] ?? 'Sistem',
                'notes' => ((array) $l->new_values)['catatan'] ?? null,
                'changes' => $ubah,
            ];
        }

        return $out;
    }

    /** Kotak TTD cetakan: pengunggah, Dept Head, lalu approver invoice terakhir. TTD = data URI. */
    private function kotakTtd(BsApBerkasUpload $b): array
    {
        $img = function (?User $u): ?string {
            if (! $u || ! $u->signature_path || ! Storage::disk('local')->exists($u->signature_path)) {
                return null;
            }

            return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($u->signature_path));
        };
        $kotak = [['Diajukan', $b->uploader, $b->submitted_at ?? $b->created_at]];
        $ttd = $b->approval_id ? Approval::with('steps')->find($b->approval_id) : null;
        $s = $ttd?->steps->sortBy('level')->first();
        $kotak[] = ['Dept. Head', $s?->status === 'approved' ? User::find($s->approver_id) : null, $s?->status === 'approved' ? $s->acted_at : null, $b->deptHead];

        $inv = $b->invoices()->latest('id')->first();
        $ia = $inv?->approvalPembayaran()?->load('steps');
        foreach (\App\Domain\ApprovalInbox\Models\MemoInternal::STEPS as $level => [, $label]) {
            $st = $ia?->steps->firstWhere('level', $level);
            $kotak[] = [$label, $st?->status === 'approved' ? User::find($st->approver_id) : null, $st?->status === 'approved' ? $st->acted_at : null];
        }

        return array_map(fn ($k) => [
            'title' => $k[0],
            'name' => $k[1]?->name ?? ($k[3] ?? null)?->name,
            'signed' => $k[1] !== null,
            'at' => $k[2]?->format('d/m/Y H:i'),
            'img' => $img($k[1]),
        ], $kotak);
    }

    private function formProps(User $user): array
    {
        return [
            'today' => now()->toDateString(),
            'suppliers' => BsSupplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'jenisBerkas' => BsApBerkas::query()->where('is_active', true)->orderByDesc('is_mandatory')->orderBy('id')->get(['id', 'code', 'name', 'is_mandatory']),
            // Otomatis = atasan langsung di Employee Center; null → tampilkan peringatan di form.
            'deptHead' => $this->service->deptHeadUntuk($user)?->only(['id', 'name']),
            'maxFiles' => ApBerkasService::MAX_FILES,
        ];
    }

    private function rules(bool $baru): array
    {
        return [
            'upload_date' => [$baru ? 'required' : 'sometimes', 'date'],
            'document_date' => ['required', 'date'], // bagian dari nomor berkas
            'needed_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:bs_suppliers,id'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:80'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['required', 'string', 'max:255'],
            'files' => [$baru ? 'required' : 'nullable', 'array', 'max:'.ApBerkasService::MAX_FILES],
            'files.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'files.*.berkas_id' => ['nullable', 'integer', 'exists:bs_ap_berkas,id'],
            'ajukan' => ['nullable', 'boolean'],
        ];
    }

    private function messages(): array
    {
        return [
            'files.required' => 'Unggah minimal satu file berkas.',
            'files.*.file.mimes' => 'File harus PDF, JPG, atau PNG.',
            'files.*.file.max' => 'Ukuran tiap file maksimal 10 MB.',
        ];
    }

    private function attributes(): array
    {
        return ['document_date' => 'Tanggal tagihan', 'description' => 'Keterangan', 'needed_date' => 'Jatuh tempo', 'amount' => 'Total tagihan'];
    }

    /** @return list<array{file:\Illuminate\Http\UploadedFile, berkas_id:?int}> */
    private function filesDari(array $data): array
    {
        return array_map(fn ($f) => ['file' => $f['file'], 'berkas_id' => $f['berkas_id'] ?? null], $data['files'] ?? []);
    }

    private function jalankan(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }

    private function htmlDaftar(array $head, array $data, array $filters): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($v) => $v === null ? '' : number_format((float) $v, 0, ',', '.');
        $uang = [7, 8];
        $th = implode('', array_map(fn ($h) => '<th>'.$e($h).'</th>', $head));
        $tr = '';
        foreach ($data as $r) {
            $tr .= '<tr>'.implode('', array_map(fn ($v, $i) => '<td'.(in_array($i, $uang, true) ? ' class="n"' : '').'>'.$e(in_array($i, $uang, true) ? $rp($v) : $v).'</td>', $r, array_keys($r))).'</tr>';
        }
        $f = collect($filters)->filter()->map(fn ($v, $k) => $e($k).': '.$e($v))->implode(' · ');

        return '<!doctype html><html><head><meta charset="utf-8"><title>Daftar Upload Berkas</title><style>'
            .'@page{size:A4 landscape;margin:12mm}body{font-family:Arial,sans-serif;font-size:10px;color:#111}'
            .'h1{font-size:15px;margin:0 0 2px}p{margin:0 0 8px;color:#555}table{width:100%;border-collapse:collapse}'
            .'th,td{border:1px solid #bbb;padding:3px 4px;vertical-align:top}th{background:#f1f1f1;text-align:left}.n{text-align:right;white-space:nowrap}'
            .'</style></head><body onload="window.print()"><h1>Daftar Upload Berkas</h1><p>Dicetak '.now()->format('d/m/Y H:i')
            .($f ? ' · Filter: '.$f : '').' · '.count($data).' berkas</p><table><thead><tr>'.$th.'</tr></thead><tbody>'.$tr.'</tbody></table></body></html>';
    }
}
