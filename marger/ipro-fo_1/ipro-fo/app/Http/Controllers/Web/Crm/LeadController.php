<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Crm;

use App\Domain\Crm\Enums\LeadStage;
use App\Domain\Crm\Models\ActivityLog;
use App\Domain\Crm\Models\CrmLead;
use App\Domain\Crm\Enums\ActivityCategory;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\Models\Agent;
use App\Domain\Master\Customer\Models\DataSource;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM Lead Pipeline — corong penjualan pra-booking (kanban) + konversi ke Customer.
 */
class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('crm.view'), 403);

        $base = CrmLead::query()
            ->with(['dataSource:id,name', 'agent:id,code,name', 'assignedTo:id,name', 'unit:id,unit_code'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('lead_number', 'like', "%{$s}%")))
            ->when($request->integer('agent_id'), fn ($q, $v) => $q->where('agent_id', $v))
            ->when($request->integer('data_source_id'), fn ($q, $v) => $q->where('data_source_id', $v));

        $leads = (clone $base)->latest('id')->get();

        // Kolom kanban (tahap aktif).
        $columns = [];
        foreach (LeadStage::pipeline() as $stage) {
            $col = $leads->where('stage', $stage)->values();
            $columns[] = [
                'stage' => $stage->value, 'label' => $stage->label(), 'color' => $stage->color(),
                'count' => $col->count(), 'value' => round((float) $col->sum('est_value'), 2),
                'leads' => $col->map(fn ($l) => $this->card($l))->all(),
            ];
        }

        $convertedCount = $leads->where('stage', LeadStage::Converted)->count();
        $lostCount      = $leads->where('stage', LeadStage::Lost)->count();
        $activeCount    = $leads->whereIn('stage', LeadStage::pipeline())->count();
        $totalClosed    = $convertedCount + $lostCount;

        // ROI/konversi per sumber marketing.
        $bySource = $leads->groupBy(fn ($l) => $l->dataSource?->name ?? 'Tanpa Sumber')
            ->map(fn ($g, $name) => [
                'name' => $name, 'total' => $g->count(),
                'converted' => $g->where('stage', LeadStage::Converted)->count(),
                'value' => round((float) $g->sum('est_value'), 2),
            ])->sortByDesc('total')->values();

        return Inertia::render('Crm/Leads/Index', [
            'columns' => $columns,
            'stats'   => [
                'active' => $activeCount, 'converted' => $convertedCount, 'lost' => $lostCount,
                'active_value' => round((float) $leads->whereIn('stage', LeadStage::pipeline())->sum('est_value'), 2),
                'conversion_rate' => $totalClosed > 0 ? round($convertedCount / $totalClosed * 100, 1) : 0.0,
            ],
            'bySource' => $bySource,
            'filters'  => $request->only(['search', 'agent_id', 'data_source_id']),
            'masters'  => ['sources' => $this->sources(), 'agents' => $this->agents()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('crm.manage'), 403);

        return Inertia::render('Crm/Leads/Form', ['masters' => $this->masters(), 'stages' => LeadStage::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('crm.manage'), 403);
        $v = $this->validateData($request);
        $v['lead_number'] = $this->nextNumber();
        $v['created_by'] = $request->user()->id;
        $lead = CrmLead::create($v);

        return redirect()->route('crm.leads.show', $lead)->with('success', "Lead {$lead->lead_number} dibuat.");
    }

    public function show(Request $request, CrmLead $lead): Response
    {
        abort_unless($request->user()?->can('crm.view'), 403);
        $lead->load(['dataSource:id,name', 'agent:id,code,name', 'assignedTo:id,name', 'unit:id,unit_code', 'customer:id,code,full_name',
            'activities' => fn ($q) => $q->latest('logged_at')->latest('id')]);

        return Inertia::render('Crm/Leads/Show', [
            'lead'       => array_merge($lead->toArray(), ['stage_label' => $lead->stage->label()]),
            'stages'     => LeadStage::options(),
            'activityCategories' => collect(ActivityCategory::cases())->mapWithKeys(fn ($c) => [$c->value => ucwords(str_replace('_', ' ', $c->value))])->all(),
            'masters'    => $this->masters(),
        ]);
    }

    public function update(Request $request, CrmLead $lead): RedirectResponse
    {
        abort_unless($request->user()?->can('crm.manage'), 403);
        $lead->update($this->validateData($request));

        return back()->with('success', 'Lead diperbarui.');
    }

    /** Pindah tahap pipeline. */
    public function move(Request $request, CrmLead $lead): RedirectResponse
    {
        abort_unless($request->user()?->can('crm.manage'), 403);
        $v = $request->validate(['stage' => ['required', 'in:new,contacted,prospect,hot']]);
        $lead->update(['stage' => $v['stage']]);

        return back(303)->with('success', 'Tahap lead diperbarui.');
    }

    /** Konversi lead → Customer (status Prospect) + tandai converted. */
    public function convert(Request $request, CrmLead $lead, CreateCustomer $createCustomer): RedirectResponse
    {
        abort_unless($request->user()?->can('crm.manage'), 403);
        if ($lead->customer_id) {
            return back()->with('error', 'Lead sudah dikonversi.');
        }

        $customer = $createCustomer->execute([
            'full_name'      => $lead->name,
            'phone'          => $lead->phone,
            'email'          => $lead->email,
            'data_source_id' => $lead->data_source_id,
            'nationality'    => 'WNI',
            'id_type'        => 'KTP',
            'id_number'      => 'PROSPEK-'.$lead->id, // placeholder unik; lengkapi NIK asli di modul Customer
        ]);

        $lead->update(['stage' => LeadStage::Converted, 'customer_id' => $customer->id, 'last_contact_at' => now()]);
        $this->logActivity($lead, ActivityCategory::VerbalAgreement, "Konversi ke customer {$customer->code} — {$customer->full_name}", $request->user()->id);

        return redirect()->route('crm.leads.show', $lead)->with('success', "Lead dikonversi → Customer {$customer->code}. Lanjut buat Booking di modul Penjualan.");
    }

    /** Catat aktivitas follow-up. */
    public function activity(Request $request, CrmLead $lead): RedirectResponse
    {
        abort_unless($request->user()?->can('crm.manage'), 403);
        $v = $request->validate([
            'category'    => ['required', 'string'],
            'description' => ['required', 'string', 'max:1000'],
            'next_followup_at' => ['nullable', 'date'],
        ]);
        $cat = ActivityCategory::tryFrom($v['category']) ?? ActivityCategory::Other;
        $this->logActivity($lead, $cat, $v['description'], $request->user()->id);
        $lead->update(['last_contact_at' => now(), 'next_followup_at' => $v['next_followup_at'] ?? $lead->next_followup_at]);

        return back()->with('success', 'Aktivitas dicatat.');
    }

    public function lost(Request $request, CrmLead $lead): RedirectResponse
    {
        abort_unless($request->user()?->can('crm.manage'), 403);
        $v = $request->validate(['lost_reason' => ['required', 'string', 'max:255']]);
        $lead->update(['stage' => LeadStage::Lost, 'lost_reason' => $v['lost_reason']]);
        $this->logActivity($lead, ActivityCategory::Other, 'Lead batal: '.$v['lost_reason'], $request->user()->id);

        return back()->with('success', 'Lead ditandai batal/lost.');
    }

    private function logActivity(CrmLead $lead, ActivityCategory $cat, string $desc, ?int $userId): void
    {
        ActivityLog::create([
            'subject_type' => CrmLead::class, 'subject_id' => $lead->id,
            'category' => $cat, 'description' => $desc, 'logged_at' => now(), 'logged_by' => $userId,
        ]);
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'phone'            => ['nullable', 'string', 'max:40'],
            'email'            => ['nullable', 'email', 'max:150'],
            'data_source_id'   => ['nullable', 'integer', 'exists:data_sources,id'],
            'agent_id'         => ['nullable', 'integer', 'exists:agents,id'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'unit_id'          => ['nullable', 'integer', 'exists:units,id'],
            'interest'         => ['nullable', 'string', 'max:200'],
            'stage'            => ['nullable', 'in:new,contacted,prospect,hot'],
            'est_value'        => ['nullable', 'numeric', 'min:0'],
            'next_followup_at' => ['nullable', 'date'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function nextNumber(): string
    {
        $d = Carbon::now();
        $seq = CrmLead::withTrashed()->whereYear('created_at', $d->year)->count() + 1;
        do {
            $number = sprintf('LEAD/%s/%05d', $d->format('y'), $seq);
            $seq++;
        } while (CrmLead::withTrashed()->where('lead_number', $number)->exists());

        return $number;
    }

    /** @return array<string,mixed> */
    private function masters(): array
    {
        return [
            'sources' => $this->sources(),
            'agents'  => $this->agents(),
            'units'   => Unit::query()->orderBy('unit_code')->limit(800)->get(['id', 'unit_code', 'current_price', 'status'])
                ->map(fn ($u) => ['id' => $u->id, 'unit_code' => $u->unit_code, 'price' => (float) $u->current_price, 'status' => $u->status]),
            'users'   => User::orderBy('name')->limit(200)->get(['id', 'name']),
        ];
    }

    private function sources()
    {
        return DataSource::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function agents()
    {
        return Agent::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /** @return array<string,mixed> */
    private function card(CrmLead $l): array
    {
        return [
            'id' => $l->id, 'lead_number' => $l->lead_number, 'name' => $l->name, 'phone' => $l->phone,
            'est_value' => (float) $l->est_value, 'stage' => $l->stage->value,
            'source' => $l->dataSource?->name, 'agent' => $l->agent?->name, 'pic' => $l->assignedTo?->name,
            'unit' => $l->unit?->unit_code, 'interest' => $l->interest,
            'next_followup_at' => $l->next_followup_at?->format('Y-m-d'),
        ];
    }
}
