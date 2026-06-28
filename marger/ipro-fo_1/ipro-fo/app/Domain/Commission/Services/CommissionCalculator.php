<?php

declare(strict_types=1);

namespace App\Domain\Commission\Services;

use App\Domain\Commission\Models\Commission;
use App\Domain\Commission\Models\CommissionLine;
use App\Domain\Commission\Models\CommissionScheme;
use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Hitung komisi multi-tier untuk satu booking:
 *  - POKOK: agen utama yang closing
 *  - P:     Personal bonus
 *  - R:     Referral bonus
 *  - CF:    Customer Finder
 *  - CFP/CFR: CF Personal/Referral
 *  - REWARD: program reward marketing
 */
final class CommissionCalculator
{
    public function calculate(Booking $booking, ?CommissionScheme $scheme = null): Commission
    {
        $scheme ??= CommissionScheme::query()
            ->where(function ($q) use ($booking): void {
                $q->where('project_id', $booking->project_id)->orWhereNull('project_id');
            })
            ->where('is_active', true)
            ->orderByRaw('project_id IS NULL ASC')
            ->first();

        return DB::transaction(function () use ($booking, $scheme): Commission {
            $commission = Commission::create([
                'booking_id'           => $booking->id,
                'commission_scheme_id' => $scheme?->id,
                'base_amount'          => (float) $booking->total_price,
                'status'               => 'scheduled',
            ]);

            $base = (float) $booking->total_price;

            // POKOK ke agen utama
            if ($booking->agent_id) {
                $terms = $scheme?->terms->where('agent_type', 'POKOK') ?? collect();
                foreach ($terms as $term) {
                    CommissionLine::create([
                        'commission_id' => $commission->id,
                        'agent_id'      => $booking->agent_id,
                        'tier'          => 'POKOK',
                        'rate_pct'      => $term->rate_pct,
                        'amount'        => $term->rate_pct
                            ? round($base * ((float) $term->rate_pct / 100), 2)
                            : (float) ($term->flat_amount ?? 0),
                        'trigger_event' => $term->trigger_event,
                        'status'        => 'scheduled',
                    ]);
                }

                // CF/CFP/CFR via parent agent hierarchy
                $this->processHierarchy($commission, $base, $booking->agent_id, $scheme);
            }

            return $commission;
        });
    }

    private function processHierarchy(Commission $commission, float $base, int $agentId, ?CommissionScheme $scheme): void
    {
        $agent = \App\Domain\Master\Customer\Models\Agent::find($agentId);
        if (! $agent || ! $agent->parent_agent_id || ! $scheme) {
            return;
        }

        $parent = \App\Domain\Master\Customer\Models\Agent::find($agent->parent_agent_id);
        if (! $parent) return;

        $tier   = $parent->agentType->code ?? 'R';
        $terms  = $scheme->terms->where('agent_type', $tier);

        foreach ($terms as $term) {
            CommissionLine::create([
                'commission_id' => $commission->id,
                'agent_id'      => $parent->id,
                'tier'          => $tier,
                'rate_pct'      => $term->rate_pct,
                'amount'        => $term->rate_pct
                    ? round($base * ((float) $term->rate_pct / 100), 2)
                    : (float) ($term->flat_amount ?? 0),
                'trigger_event' => $term->trigger_event,
                'status'        => 'scheduled',
            ]);
        }
    }
}
