<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Models\KprApprovalSetting;
use App\Domain\BankLoan\Models\KprChangeRequest;
use App\Domain\BankLoan\Services\KprPerubahanService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval perubahan Proses KPR:
 *  - Set Up → Setup Approval KPR: per tahap wajib approval?, approver, boleh ubah langsung.
 *  - KPR → Approval → <tahap>: daftar pengajuan untuk approver tahap itu (Setujui / Tolak).
 * Aturan & penerapan perubahannya ada di KprPerubahanService.
 */
class KprApprovalController extends Controller
{
    public function __construct(private readonly KprPerubahanService $svc) {}

    public function setup(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        $settings = KprApprovalSetting::query()->get()->keyBy('stage');

        return Inertia::render('BankLoan/Setup/Approval/Index', [
            'stages' => collect(KprPerubahanService::STAGES)->map(fn ($label, $stage) => [
                'stage'             => $stage,
                'label'             => $label,
                // Aksi yang tercakup tahap ini — ditampilkan agar jelas apa saja yang butuh approval.
                'actions'           => collect(KprPerubahanService::ACTIONS)->filter(fn ($a) => $a[0] === $stage)->map(fn ($a) => $a[1])->values(),
                'is_required'       => (bool) ($settings[$stage]->is_required ?? false),
                'approver_user_ids' => array_map('intval', $settings[$stage]->approver_user_ids ?? []),
                'bypass_user_ids'   => array_map('intval', $settings[$stage]->bypass_user_ids ?? []),
            ])->values(),
            'users'     => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'label' => $u->name.($u->email ? ' — '.$u->email : '')]),
            'canManage' => (bool) $request->user()?->can('bankloan.setup.manage'),
        ]);
    }

    public function saveSetup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $v = $request->validate([
            'stages'                       => 'required|array',
            'stages.*.stage'               => ['required', Rule::in(array_keys(KprPerubahanService::STAGES))],
            'stages.*.is_required'         => 'boolean',
            'stages.*.approver_user_ids'   => 'array',
            'stages.*.approver_user_ids.*' => 'integer|exists:users,id',
            'stages.*.bypass_user_ids'     => 'array',
            'stages.*.bypass_user_ids.*'   => 'integer|exists:users,id',
        ]);

        foreach ($v['stages'] as $row) {
            if (($row['is_required'] ?? false) && empty($row['approver_user_ids'])) {
                return back()->with('error', 'Tahap '.KprPerubahanService::STAGES[$row['stage']].' wajib approval tetapi belum ada approver — pilih minimal satu approver.');
            }
        }

        foreach ($v['stages'] as $row) {
            KprApprovalSetting::updateOrCreate(['stage' => $row['stage']], [
                'is_required'       => (bool) ($row['is_required'] ?? false),
                'approver_user_ids' => array_values(array_unique(array_map('intval', $row['approver_user_ids'] ?? []))),
                'bypass_user_ids'   => array_values(array_unique(array_map('intval', $row['bypass_user_ids'] ?? []))),
            ]);
        }

        return back()->with('success', 'Setup Approval KPR disimpan.');
    }

    public function index(Request $request, string $stage): Response
    {
        abort_unless(isset(KprPerubahanService::STAGES[$stage]), 404);
        $user = $request->user();
        $tahapku = $this->svc->tahapApprover((int) $user->id);
        $isApprover = in_array($stage, $tahapku, true);
        abort_unless($isApprover || $user->can('bankloan.setup.manage'), 403);

        $base = fn () => KprChangeRequest::with(['kpr.customer:id,full_name', 'kpr.bank:id,name', 'kpr.booking.unit:id,unit_code', 'requester:id,name', 'decider:id,name'])
            ->where('stage', $stage);

        return Inertia::render('BankLoan/Approval/Index', [
            'stage'      => $stage,
            'stageLabel' => KprPerubahanService::STAGES[$stage],
            'stages'     => collect(KprPerubahanService::STAGES)->only($tahapku)->map(fn ($l, $s) => ['stage' => $s, 'label' => $l])->values(),
            'pending'    => $base()->where('status', 'pending')->oldest('id')->get()->map(fn ($c) => $this->svc->tampil($c)),
            'selesai'    => $base()->whereIn('status', ['approved', 'rejected'])->latest('decided_at')->limit(50)->get()->map(fn ($c) => $this->svc->tampil($c)),
            'canAct'     => $isApprover,
            'meId'       => (int) $user->id,
        ]);
    }

    public function approve(Request $request, KprChangeRequest $change): RedirectResponse
    {
        $note = $request->validate(['catatan' => 'nullable|string|max:1000'])['catatan'] ?? null;
        try {
            $this->svc->setujui($change, $request->user(), $note);
        } catch (BusinessRuleException $e) {
            return back()->with('error', 'Tidak bisa disetujui: '.$e->getMessage());
        }

        return back()->with('success', 'Pengajuan disetujui — perubahan sudah diterapkan ke KPR '.$change->kpr?->application_number.'.');
    }

    public function reject(Request $request, KprChangeRequest $change): RedirectResponse
    {
        $note = $request->validate(['catatan' => 'required|string|max:1000'], ['catatan.required' => 'Catatan penolakan wajib diisi.'])['catatan'];
        try {
            $this->svc->tolak($change, $request->user(), $note);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pengajuan ditolak — data KPR tidak berubah.');
    }
}
