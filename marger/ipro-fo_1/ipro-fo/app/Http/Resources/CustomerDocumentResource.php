<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Customer\Models\CustomerDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerDocument
 */
class CustomerDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'label'      => $this->label,
            'file_url'   => $this->file_path ? \Illuminate\Support\Facades\Storage::url($this->file_path) : null,
            'mime'       => $this->mime,
            'size_bytes' => $this->size_bytes,
            'uploaded_at'=> $this->created_at?->toIso8601String(),
        ];
    }
}
