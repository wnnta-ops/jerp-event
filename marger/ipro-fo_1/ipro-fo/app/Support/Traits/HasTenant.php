<?php

declare(strict_types=1);

namespace App\Support\Traits;

use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Support\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasTenant
{
    public static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $model): void {
            if ($model->tenant_id === null) {
                // Konteks request (middleware SetTenant) → fallback tenant default (MUP)
                // supaya record dari guest/webhook/console tidak melanggar NOT NULL.
                $model->tenant_id = tenant_id() ?? default_tenant_id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
