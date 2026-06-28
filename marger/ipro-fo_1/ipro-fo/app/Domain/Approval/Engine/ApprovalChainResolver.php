<?php

declare(strict_types=1);

namespace App\Domain\Approval\Engine;

use App\Domain\Approval\Models\ApprovalChain;
use App\Exceptions\BusinessRuleException;

final class ApprovalChainResolver
{
    /**
     * Cari chain yang match dokumen + context.
     *
     * @param array<string, mixed> $context (mis. ['discount_pct' => 7, 'project_id' => 1])
     */
    public function resolve(string $documentType, array $context = []): ApprovalChain
    {
        $chains = ApprovalChain::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->when($context['project_id'] ?? null,
                fn ($q, $pid) => $q->where(function ($q) use ($pid): void {
                    $q->where('project_id', $pid)->orWhereNull('project_id');
                })
            )
            ->orderByRaw('project_id IS NULL ASC')  // project-specific dulu
            ->get();

        foreach ($chains as $chain) {
            if ($this->matchesConditions($chain->match_conditions ?? [], $context)) {
                return $chain;
            }
        }

        throw new BusinessRuleException(
            "Tidak ada approval chain yang sesuai untuk {$documentType}.",
            errorCode: 'NO_APPROVAL_CHAIN',
            details: ['document_type' => $documentType, 'context' => $context],
        );
    }

    /**
     * Match conditions: support range threshold (mis. {"discount_pct": {"min": 3, "max": 7}}).
     */
    private function matchesConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $rule) {
            $value = $context[$key] ?? null;
            if ($value === null) {
                return false;
            }
            if (is_array($rule)) {
                if (isset($rule['min']) && $value < $rule['min']) return false;
                if (isset($rule['max']) && $value >= $rule['max']) return false;
                if (isset($rule['eq'])  && $value !== $rule['eq']) return false;
            } else {
                if ($value !== $rule) return false;
            }
        }

        return true;
    }
}
