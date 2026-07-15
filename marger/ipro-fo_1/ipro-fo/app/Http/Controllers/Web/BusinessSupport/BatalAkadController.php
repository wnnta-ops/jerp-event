<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rincian Batal Akad — daftar customer yang KPR-nya BATAL / DITOLAK.
 *
 * Baris-baris ini SENGAJA disaring keluar dari antrean Approval Retensi & Approval UM
 * Penjualan (lihat BankApprovalController::retensiIndex/umIndex) supaya tidak ikut
 * diapprove; halaman ini menjadi tempat menelusurinya.
 *
 * Definisi (disepakati): batal = kpr_applications.status ∈ {cancelled, rejected}.
 * Tanggal batal akad = decided_at — diisi saat keputusan "Batal" / penolakan ditetapkan.
 */
class BatalAkadController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('businesssupport') || $request->user()?->can('bankloan.application.view'), 403);

        $apps = KprApplication::query()
            ->whereIn('status', [KprStatus::Cancelled->value, KprStatus::Rejected->value])
            ->with([
                'customer:id,full_name',
                'bank:id,name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
                'akad:id,kpr_application_id,akad_at',
            ])
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->limit(500)
            ->get();

        $rows = $apps->map(function (KprApplication $k) {
            $before = $k->status_before_cancel ? KprStatus::tryFrom($k->status_before_cancel) : null;
            $akadAt = optional($k->akad?->akad_at)->toDateString();

            return [
                'id'            => $k->id,
                'kpr_number'    => $k->application_number,
                'customer'      => $k->customer?->full_name,
                'unit'          => $k->booking?->unit?->unit_code,
                'booking'       => $k->booking?->booking_number,
                'bank'          => $k->bank?->name,
                'status'        => $k->status->value,          // cancelled | rejected
                'status_label'  => $k->status->label(),
                // Tanggal batal akad — inti halaman ini.
                'batal_at'      => optional($k->decided_at)->toDateString(),
                // Konteks: sudah sejauh mana sebelum dibatalkan, & apakah akadnya sempat terjadi.
                'tahap_sebelum' => $before?->label(),
                'akad_at'       => $akadAt,
                'sempat_akad'   => $akadAt !== null,
                'alasan'        => $k->reject_reason,
                'plafon'        => (float) ($k->plafon_approved ?: $k->plafon_requested),
            ];
        });

        return Inertia::render('BusinessSupport/BatalAkad/Index', [
            'rows' => $rows,
            'totals' => [
                'count'       => $rows->count(),
                'cancelled'   => $rows->where('status', 'cancelled')->count(),
                'rejected'    => $rows->where('status', 'rejected')->count(),
                'sempat_akad' => $rows->where('sempat_akad', true)->count(),
            ],
        ]);
    }
}
