<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Repositories;

use App\Domain\Master\Unit\Models\Unit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UnitRepository
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->buildQuery($filters)
            ->with(['cluster:id,code,name', 'block:id,code', 'unitType:id,code,name,lt,lb'])
            ->orderBy('unit_code')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return Builder<Unit>
     */
    public function buildQuery(array $filters = []): Builder
    {
        return Unit::query()
            ->when($filters['search']      ?? null, fn ($q, $term)  => $q->where('unit_code', 'like', "%{$term}%"))
            ->when($filters['project_id']  ?? null, fn ($q, $value) => $q->where('project_id', $value))
            ->when($filters['cluster_id']  ?? null, fn ($q, $value) => $q->where('cluster_id', $value))
            ->when($filters['status']      ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['unit_type_id']?? null, fn ($q, $value) => $q->where('unit_type_id', $value))
            ->when($filters['min_price']   ?? null, fn ($q, $value) => $q->where('current_price', '>=', $value))
            ->when($filters['max_price']   ?? null, fn ($q, $value) => $q->where('current_price', '<=', $value));
    }

    /**
     * Untuk render siteplan: ambil semua unit + koordinat.
     *
     * @return Collection<int, Unit>
     */
    public function forSiteplan(int $projectId): Collection
    {
        return Unit::query()
            ->where('project_id', $projectId)
            ->with(['siteplanPoint', 'cluster:id,code', 'unitType:id,name,lt,lb'])
            ->orderBy('unit_code')
            ->get();
    }
}
