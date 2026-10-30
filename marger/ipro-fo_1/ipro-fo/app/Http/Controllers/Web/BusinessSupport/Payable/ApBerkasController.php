<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Account Payable › Master › Upload Berkas.
 *
 *   Berkas Saya          : yang saya unggah (semua staf)
 *   Siap Dibuat Invoice  : sudah TTD Dept Head, belum diinvoice (pemegang ap.invoice.create)
 *   Semua Berkas         : seluruh berkas (pemegang ap.invoice.view)
 *
 * TTD Dept Head & approval invoice dilakukan di Approval Inbox.
 */
class ApBerkasController extends Controller
{
    public function __construct(private readonly ApBerkasService $service) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('ap.berkas.upload') || $user?->can('ap.invoice.view'), 403);

        $base = fn () => BsApBerkasUpload::query()->with(['supplier:id,name', 'deptHead:id,name', 'uploader:id,name', 'invoice:id,invoice_number'])->withCount('files');
        $lists = ['saya' => $base()->where('uploaded_by', $user->id)];
        if ($user->can('ap.invoice.create')) {
            $lists['siap'] = $base()->where('status', BsApBerkasUpload::STATUS_SIAP_INVOICE)->whereNull('ap_invoice_id');
        }
        if ($user->can('ap.invoice.view')) {
            $lists['semua'] = $base();
        }

        $counts = array_map(fn ($q) => (clone $q)->count(), $lists);
        $tab = (string) $request->input('tab');
        if (! array_key_exists($tab, $lists)) {
            $tab = ($counts['siap'] ?? 0) > 0 ? 'siap' : 'saya';
        }

        return Inertia::render('BusinessSupport/Payable/Berkas/Index', [
            'tab' => $tab,
            'tabs' => array_keys($lists),
            'counts' => $counts,
            'rows' => $lists[$tab]->latest('id')->limit(200)->get()->map(fn (BsApBerkasUpload $b) => $this->row($b)),
            'canCreateInvoice' => $user->can('ap.invoice.create'),
            'canUpload' => $user->can('ap.berkas.upload'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('ap.berkas.upload'), 403);

        return Inertia::render('BusinessSupport/Payable/Berkas/Create', [
            'today' => now()->toDateString(),
            'suppliers' => BsSupplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'jenisBerkas' => BsApBerkas::query()->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']),
            'deptHeads' => User::role(ApBerkasService::DEPT_HEAD_ROLE)->where('is_active', true)
                ->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->can('ap.berkas.upload'), 403);

        $data = $request->validate([
            'upload_date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:bs_suppliers,id'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:80'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['required', 'string', 'max:255'],
            'dept_head_id' => ['required', 'integer', 'exists:users,id'],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'files.*.berkas_id' => ['nullable', 'integer', 'exists:bs_ap_berkas,id'],
        ], [
            'files.required' => 'Unggah minimal satu file berkas.',
            'files.*.file.mimes' => 'File harus PDF, JPG, atau PNG.',
            'files.*.file.max' => 'Ukuran tiap file maksimal 10 MB.',
        ], [
            'dept_head_id' => 'Dept Head penanda tangan',
            'description' => 'Keterangan',
        ]);

        try {
            $berkas = $this->service->upload(
                collect($data)->except('files')->all(),
                array_map(fn ($f) => ['file' => $f['file'], 'berkas_id' => $f['berkas_id'] ?? null], $data['files']),
                $user,
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.berkas.show', $berkas->id)
            ->with('success', "Berkas {$berkas->berkas_number} diunggah — menunggu TTD {$berkas->deptHead?->name}.");
    }

    public function show(Request $request, BsApBerkasUpload $berkas): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user, $berkas), 403);
        $berkas->load(['files.jenis:id,name', 'supplier:id,name', 'deptHead:id,name', 'uploader:id,name', 'invoice:id,invoice_number,total,status']);
        $invoiceApproval = $berkas->invoice?->approvalPembayaran();

        return Inertia::render('BusinessSupport/Payable/Berkas/Show', [
            'berkas' => $this->row($berkas) + [
                'files' => $berkas->files->map(fn (BsApBerkasUploadFile $f) => [
                    'id' => $f->id,
                    'name' => $f->original_name,
                    'jenis' => $f->jenis?->name,
                    'size' => $f->size,
                    'mime' => $f->mime,
                    'url' => route('bs.ap.berkas.file', [$berkas->id, $f->id]),
                ]),
            ],
            'ttdApprovalUrl' => $berkas->approval_id ? route('approval-inbox.show', $berkas->approval_id) : null,
            'invoiceApproval' => $invoiceApproval ? [
                'status' => $invoiceApproval->status,
                'url' => route('approval-inbox.show', $invoiceApproval->id),
            ] : null,
            'canCreateInvoice' => $user->can('ap.invoice.create')
                && $berkas->status === BsApBerkasUpload::STATUS_SIAP_INVOICE && ! $berkas->ap_invoice_id,
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

    /** Pengunggah, Dept Head-nya, tim AP, approver invoice-nya, atau SuperAdmin. */
    private function canView(?User $user, BsApBerkasUpload $berkas): bool
    {
        if (! $user) {
            return false;
        }
        if ((int) $berkas->uploaded_by === (int) $user->id || (int) $berkas->dept_head_id === (int) $user->id
            || $user->can('ap.invoice.view') || $user->hasRole('SuperAdmin')) {
            return true;
        }
        $approval = $berkas->invoice?->approvalPembayaran();

        return $approval !== null && app(ApprovalInboxQuery::class)->canView($user, $approval->load('steps'));
    }

    private function row(BsApBerkasUpload $b): array
    {
        return [
            'id' => $b->id,
            'number' => $b->berkas_number,
            'date' => $b->upload_date?->toDateString(),
            'supplier' => $b->supplier?->name,
            'supplier_invoice_no' => $b->supplier_invoice_no,
            'amount' => $b->amount !== null ? (float) $b->amount : null,
            'description' => $b->description,
            'status' => $b->status,
            'dept_head' => $b->deptHead?->name,
            'uploader' => $b->uploader?->name,
            'files_count' => $b->files_count ?? $b->files?->count(),
            'invoice' => $b->invoice ? ['id' => $b->invoice->id, 'number' => $b->invoice->invoice_number] : null,
        ];
    }
}
