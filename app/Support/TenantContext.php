<?php

namespace App\Support;

/**
 * Per-request tenant resolver. In v1 there is a single tenant (MUP, id=1).
 * Phase 2+ will wire request-scoped resolution from subdomain / header / user.
 */
class TenantContext
{
    protected int $id = 1;

    public function id(): int
    {
        return $this->id;
    }

    public function set(int $id): void
    {
        $this->id = $id;
    }

    public function reset(): void
    {
        $this->id = 1;
    }
}
