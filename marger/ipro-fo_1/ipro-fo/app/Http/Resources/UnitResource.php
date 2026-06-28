<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Unit\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'unit_code'     => $this->unit_code,
            'project_id'    => $this->project_id,
            'cluster'       => $this->whenLoaded('cluster', fn () => ['id' => $this->cluster->id, 'code' => $this->cluster->code, 'name' => $this->cluster->name]),
            'block'         => $this->whenLoaded('block',   fn () => ['id' => $this->block->id, 'code' => $this->block->code]),
            'unit_type'     => $this->whenLoaded('unitType', fn () => [
                'id' => $this->unitType->id, 'code' => $this->unitType->code, 'name' => $this->unitType->name,
                'lt' => $this->unitType->lt, 'lb' => $this->unitType->lb,
            ]),
            'address'       => $this->address,
            'base_price'    => (float) $this->base_price,
            'current_price' => (float) $this->current_price,
            'status'        => $this->status?->value,
            'status_label'  => $this->status?->label(),
            'attributes'    => $this->attributes_array(),
            'siteplan'      => $this->whenLoaded('siteplanPoint', fn () => $this->siteplanPoint ? [
                'x'        => (float) $this->siteplanPoint->x,
                'y'        => (float) $this->siteplanPoint->y,
                'width'    => (float) $this->siteplanPoint->width,
                'height'   => (float) $this->siteplanPoint->height,
                'rotation' => (float) $this->siteplanPoint->rotation,
                'svg_path' => $this->siteplanPoint->svg_path,
            ] : null),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }

    private function attributes_array(): array
    {
        $attrs = $this->getAttribute('attributes');
        return is_array($attrs) ? $attrs : [];
    }
}
