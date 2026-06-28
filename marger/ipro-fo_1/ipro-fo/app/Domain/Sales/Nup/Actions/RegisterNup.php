<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Domain\Sales\Nup\Models\NupScheme;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class RegisterNup
{
    public function __construct(private readonly NumeratorService $numerator) {}

    /**
     * @param array{nup_scheme_id:int,customer_id:int,sales_person_id?:int|null,agent_id?:int|null} $data
     */
    public function execute(array $data): NupRegistration
    {
        return DB::transaction(function () use ($data): NupRegistration {
            $scheme = NupScheme::lockForUpdate()->findOrFail($data['nup_scheme_id']);

            if (! $scheme->is_active || now()->lt($scheme->open_from) || now()->gt($scheme->open_to)) {
                throw new BusinessRuleException(
                    "Skema NUP {$scheme->code} tidak aktif atau periode habis.",
                    errorCode: 'NUP_SCHEME_CLOSED',
                );
            }

            $queueNo = (int) NupRegistration::where('nup_scheme_id', $scheme->id)->max('queue_no') + 1;

            return NupRegistration::create([
                'nup_scheme_id'   => $scheme->id,
                'customer_id'     => $data['customer_id'],
                'nup_number'      => $this->numerator->next('NUP'),
                'queue_no'        => $queueNo,
                'registered_at'   => now()->toDateString(),
                'sales_person_id' => $data['sales_person_id'] ?? null,
                'agent_id'        => $data['agent_id']        ?? null,
                'status'          => 'active',
            ]);
        });
    }
}
