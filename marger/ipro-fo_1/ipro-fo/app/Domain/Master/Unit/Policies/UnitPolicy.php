<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Policies;

use App\Domain\Master\Unit\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool { return $user->can('master.unit.view'); }
    public function view(User $user, Unit $unit): bool { return $user->can('master.unit.view') && $user->tenant_id === $unit->tenant_id; }
    public function create(User $user): bool { return $user->can('master.unit.create'); }
    public function update(User $user, Unit $unit): bool { return $user->can('master.unit.update') && $user->tenant_id === $unit->tenant_id; }
    public function updatePrice(User $user, Unit $unit): bool { return $user->can('master.unit.update.price') && $user->tenant_id === $unit->tenant_id; }
    public function delete(User $user, Unit $unit): bool { return $user->can('master.unit.delete') && $user->tenant_id === $unit->tenant_id; }
    public function editSiteplan(User $user): bool { return $user->can('master.unit.siteplan.edit'); }
}
