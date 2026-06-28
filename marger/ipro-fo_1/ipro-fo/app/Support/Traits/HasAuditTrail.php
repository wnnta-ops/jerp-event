<?php

declare(strict_types=1);

namespace App\Support\Traits;

use App\Domain\Foundation\Audit\Observers\AuditObserver;

trait HasAuditTrail
{
    public static function bootHasAuditTrail(): void
    {
        static::observe(AuditObserver::class);
    }

    /**
     * Kolom yang TIDAK BOLEH masuk ke audit log (PII).
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return ['password', 'remember_token', 'id_number', 'npwp', 'mfa_secret', 'token'];
    }
}
