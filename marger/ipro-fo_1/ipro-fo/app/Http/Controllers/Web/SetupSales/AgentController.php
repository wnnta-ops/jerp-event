<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SetupSales;

use App\Domain\Master\Customer\Models\Agent;
use App\Domain\Master\Customer\Models\AgentType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Agen / Broker (eProperty: Setup Sales → Master Agen).
 */
class AgentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        return Inertia::render('SetupSales/Agent/Index', [
            'rows' => Agent::query()->with('agentType:id,label', 'parent:id,name')
                ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
                ->orderBy('name')->paginate(25)->withQueryString(),
            'filters'    => $request->only(['search']),
            'agentTypes' => AgentType::orderBy('label')->get(['id', 'code', 'label']),
            'parents'    => Agent::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        Agent::create($this->validateData($request));

        return back()->with('success', 'Agen ditambahkan.');
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $data = $this->validateData($request, $agent->id);
        abort_if(($data['parent_agent_id'] ?? null) === $agent->id, 422, 'Parent tidak boleh diri sendiri.');
        $agent->update($data);

        return back()->with('success', 'Agen diperbarui.');
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $agent->delete();

        return back()->with('success', 'Agen dihapus.');
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'code'              => ['required', 'string', 'max:32'],
            'name'              => ['required', 'string', 'max:150'],
            'agent_type_id'     => ['required', 'integer', 'exists:agent_types,id'],
            'phone'             => ['nullable', 'string', 'max:30'],
            'email'             => ['nullable', 'email', 'max:150'],
            'npwp'              => ['nullable', 'string', 'max:32'],
            'bank_name'         => ['nullable', 'string', 'max:64'],
            'bank_account_no'   => ['nullable', 'string', 'max:64'],
            'bank_account_name' => ['nullable', 'string', 'max:128'],
            'parent_agent_id'   => ['nullable', 'integer', 'exists:agents,id'],
            'is_active'         => ['boolean'],
        ]);
        $dup = Agent::where('code', $v['code'])->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))->exists();
        abort_if($dup, 422, "Kode agen \"{$v['code']}\" sudah dipakai.");

        return $v;
    }
}
