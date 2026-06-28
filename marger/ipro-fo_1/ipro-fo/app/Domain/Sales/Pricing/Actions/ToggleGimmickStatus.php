<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Actions;

use App\Domain\Sales\Pricing\Models\ContractGimmick;

/**
 * Toggle status diberikan / diterima per gimmick item.
 */
final class ToggleGimmickStatus
{
    public function setDiberikan(ContractGimmick $cg, bool $value, ?string $notes = null): ContractGimmick
    {
        $cg->diberikan = $value;
        if ($notes !== null) $cg->notes = $notes;
        $cg->save();
        return $cg;
    }

    public function setDiterima(ContractGimmick $cg, bool $value, ?string $tglDiterima = null, ?string $notes = null): ContractGimmick
    {
        $cg->diterima     = $value;
        $cg->tgl_diterima = $value ? ($tglDiterima ?? now()->toDateString()) : null;
        if ($notes !== null) $cg->notes = $notes;
        $cg->save();
        return $cg;
    }
}
