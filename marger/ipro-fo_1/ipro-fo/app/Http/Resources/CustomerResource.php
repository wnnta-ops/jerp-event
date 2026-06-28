<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canSeeSensitive = $request->user()?->can('master.customer.view.sensitive') ?? false;

        return [
            'id'              => $this->id,
            'code'            => $this->code,
            'full_name'       => $this->full_name,
            'id_type'         => $this->id_type?->value,
            'id_number'       => $canSeeSensitive ? $this->id_number : $this->maskNumber($this->id_number),
            'id_expires_at'   => $this->id_expires_at?->toDateString(),
            'id_lifetime'     => (bool) $this->id_lifetime,
            'dob'             => $this->dob?->toDateString(),
            'gender'          => $this->gender,
            'marital_status'  => $this->marital_status,
            'phone'           => $this->phone,
            'phone_alt'       => $this->phone_alt,
            'email'           => $this->email,
            'address' => [
                'line'        => $this->address_line,
                'city'        => $this->city,
                'province'    => $this->province,
                'postal_code' => $this->postal_code,
            ],
            'npwp'            => $canSeeSensitive ? $this->npwp : $this->maskNumber($this->npwp),
            'npwp_name'       => $this->npwp_name,
            'occupation'      => $this->occupation,
            'employer_name'   => $this->employer_name,
            'monthly_income'  => $this->monthly_income,
            'channel'         => $this->channel,
            'status'          => $this->status?->value,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'bookings_count'  => $this->whenCounted('bookings'),
            'documents'       => CustomerDocumentResource::collection($this->whenLoaded('documents')),
        ];
    }

    private function maskNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $len = strlen($value);

        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4).substr($value, -4);
    }
}
