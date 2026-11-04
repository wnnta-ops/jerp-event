<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Approval\Models\ApprovalChain;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System › Approval Chain — susunan approver tiap alur, per level = USER tertentu (bukan peran).
 *
 * Perubahan di sini hanya berlaku untuk pengajuan BERIKUTNYA: saat diajukan, susunan chain
 * disalin ke approval_steps (snapshot), jadi dokumen yang sudah berjalan tetap memakai alur lamanya.
 */
class ApprovalChainController extends Controller
{
    /** Nama jenis dokumen untuk judul kartu. */
    private const DOKUMEN = [
        'memo_internal' => 'Memo Internal',
        'ap_berkas' => 'TTD Upload Berkas',
        'ap_invoice' => 'Invoice Hutang',
        'budget_correction' => 'Koreksi Budget',
        'budget_allocation' => 'Alokasi Budget',
        'booking_discount' => 'Diskon Booking',
        'booking_cancel' => 'Pembatalan Booking',
        'refund' => 'Refund',
        'booking_gn' => 'Ganti Nama',
        'booking_gu' => 'Ganti Unit',
        'surat_peringatan' => 'Surat Peringatan',
        'booking_reschedule' => 'Reschedule Pembayaran',
    ];

    public function index(Request $request): Response
    {
        $this->izin($request);

        $users = User::query()->where('is_active', true)->with('roles:id,name')->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'roles' => $u->roles->pluck('name')->join(', ')]);
        $nama = $users->keyBy('id');

        $chains = ApprovalChain::query()->with('steps')
            // Memo Internal di atas — alur yang diatur dari layar ini.
            ->orderByRaw("document_type = 'memo_internal' DESC")->orderByDesc('is_active')->orderBy('document_type')->orderBy('id')
            ->get()
            ->map(fn (ApprovalChain $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'dokumen' => self::DOKUMEN[$c->document_type] ?? $c->document_type,
                'kondisi' => $c->match_conditions ? collect($c->match_conditions)->map(fn ($v, $k) => $k.' = '.(is_array($v) ? json_encode($v) : $v))->join(', ') : null,
                'is_active' => $c->is_active,
                'steps' => $c->steps->map(fn (ApprovalChainStep $s) => [
                    'id' => $s->id,
                    'level' => $s->level,
                    'type' => $s->approver_type,
                    'label' => $s->label,
                    'user' => $s->approver_type === 'user' ? ($nama[(int) $s->approver_value] ?? ['id' => (int) $s->approver_value, 'name' => 'User #'.$s->approver_value.' (tidak aktif)', 'email' => null]) : null,
                    'value' => $s->approver_value,
                ])->values(),
            ]);

        return Inertia::render('Settings/ApprovalChains/Index', ['chains' => $chains, 'users' => $users->values()]);
    }

    public function storeStep(Request $request, ApprovalChain $chain): RedirectResponse
    {
        $this->izin($request);
        $data = $this->validasi($request);

        DB::transaction(function () use ($chain, $data): void {
            ApprovalChainStep::create([
                'approval_chain_id' => $chain->id,
                'level' => (int) $chain->steps()->max('level') + 1,
                'approver_type' => 'user',
                'approver_value' => (string) $data['user_id'],
                'label' => $data['label'],
            ]);
            $this->rapikan($chain);
        });

        return back()->with('success', "Level ditambahkan ke {$chain->name} — berlaku untuk pengajuan berikutnya.");
    }

    public function updateStep(Request $request, ApprovalChainStep $step): RedirectResponse
    {
        $this->izin($request);
        $data = $this->validasi($request);

        $step->update(['approver_type' => 'user', 'approver_value' => (string) $data['user_id'], 'label' => $data['label']]);

        return back()->with('success', "Level {$step->level} diperbarui — berlaku untuk pengajuan berikutnya.");
    }

    public function destroyStep(Request $request, ApprovalChainStep $step): RedirectResponse
    {
        $this->izin($request);
        $chain = ApprovalChain::findOrFail($step->approval_chain_id);
        if ($chain->steps()->count() <= 1) {
            return back()->with('error', 'Chain minimal punya satu level.');
        }

        DB::transaction(function () use ($chain, $step): void {
            $step->delete();
            $this->rapikan($chain);
        });

        return back()->with('success', 'Level dihapus — berlaku untuk pengajuan berikutnya.');
    }

    /** Level berurutan 1..n tanpa celah; level terakhir = final. */
    private function rapikan(ApprovalChain $chain): void
    {
        $steps = $chain->steps()->get();
        // Geser dulu ke atas supaya unique (chain, level) tidak bentrok saat dinomori ulang.
        foreach ($steps as $s) {
            $s->update(['level' => $s->level + 100]);
        }
        foreach ($steps->values() as $i => $s) {
            $s->update(['level' => $i + 1, 'is_final' => $i === $steps->count() - 1]);
        }
    }

    /** @return array{user_id:int, label:string} */
    private function validasi(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'integer', fn ($a, $v, $fail) => User::query()->whereKey($v)->where('is_active', true)->exists() || $fail('User tidak ditemukan atau tidak aktif.')],
            'label' => 'required|string|max:100',
        ], ['user_id.required' => 'Pilih user approver.', 'label.required' => 'Isi nama tahap (mis. Accounting).']);
    }

    private function izin(Request $request): void
    {
        abort_unless($request->user()?->can('system.setup.approval_chain'), 403);
    }
}
