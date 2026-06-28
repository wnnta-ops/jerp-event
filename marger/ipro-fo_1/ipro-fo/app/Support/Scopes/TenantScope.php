<?php

declare(strict_types=1);

namespace App\Support\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (($tenantId = tenant_id()) !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
