<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Contract\Models\Contract;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian global lintas modul untuk Command Palette (Cmd/Ctrl+K).
 * Hanya mengembalikan modul yang boleh dilihat user (permission-aware).
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $like = "%{$q}%";
        $user = $request->user();
        $out  = collect();

        // ── Customer ──
        if ($user?->can('master.customer.view')) {
            $out = $out->concat(
                Customer::query()
                    ->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
                    ->limit(5)->get(['id', 'code', 'full_name'])
                    ->map(fn ($c) => ['type' => 'customer', 'label' => "{$c->full_name} · {$c->code}", 'url' => route('customers.show', $c->id)])
            );
        }

        // ── Unit ──
        if ($user?->can('master.unit.view')) {
            $out = $out->concat(
                Unit::query()->where('unit_code', 'like', $like)->limit(5)->get(['id', 'unit_code', 'status'])
                    // Master unit ada di FO. Id unit di BO adalah id CERMIN hasil sinkronisasi,
                    // bukan id FO, jadi tautannya tidak boleh memakai id — ia membuka daftar unit
                    // FO yang sudah tersaring pada kode unitnya.
                    ->map(fn ($u) => ['type' => 'unit', 'label' => "{$u->unit_code} (".$this->str($u->status).')',
                        'url' => config('sso.fo_url').'/fo/units?tableSearch='.urlencode((string) $u->unit_code)])
            );
        }

        // ── Booking ──
        if ($user?->can('sales.booking.view')) {
            $out = $out->concat(
                Booking::query()->where('booking_number', 'like', $like)->limit(5)->get(['id', 'booking_number', 'status'])
                    ->map(fn ($b) => ['type' => 'booking', 'label' => "{$b->booking_number} (".$this->str($b->status).')', 'url' => route('bookings.show', $b->id)])
            );
        }

        // ── Kontrak ──
        if ($user?->can('sales.contract.view')) {
            $out = $out->concat(
                Contract::query()->where('contract_number', 'like', $like)->limit(5)->get(['id', 'contract_number'])
                    ->map(fn ($c) => ['type' => 'kontrak', 'label' => $c->contract_number, 'url' => route('contracts.show', $c->id)])
            );
        }

        // ── NUP ──
        if ($user?->can('sales.nup.view')) {
            $out = $out->concat(
                NupRegistration::query()->where('nup_number', 'like', $like)->limit(5)->get(['id', 'nup_number', 'queue_no'])
                    ->map(fn ($n) => ['type' => 'nup', 'label' => "{$n->nup_number} (antrian #{$n->queue_no})", 'url' => route('nup.show', $n->id)])
            );
        }

        // ── Kuitansi ──
        if ($user?->can('finance.kuitansi.view')) {
            $out = $out->concat(
                Kuitansi::query()->where('kuitansi_number', 'like', $like)->limit(5)->get(['id', 'kuitansi_number'])
                    ->map(fn ($k) => ['type' => 'kuitansi', 'label' => $k->kuitansi_number, 'url' => route('kuitansi.show', $k->id)])
            );
        }

        return response()->json(['data' => $out->values()->take(20)]);
    }

    /** Coerce enum / value menjadi string aman untuk label. */
    private function str(mixed $v): string
    {
        if ($v instanceof \BackedEnum) {
            return (string) $v->value;
        }

        return (string) $v;
    }
}
