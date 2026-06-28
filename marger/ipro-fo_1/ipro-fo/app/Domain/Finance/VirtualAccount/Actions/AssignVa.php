<?php

declare(strict_types=1);

namespace App\Domain\Finance\VirtualAccount\Actions;

use App\Domain\Finance\VirtualAccount\Models\VirtualAccount;

final class AssignVa
{
    public function execute(int $customerId, int $bankPartnerId, string $vaNumber): VirtualAccount
    {
        return VirtualAccount::updateOrCreate(
            ['bank_partner_id' => $bankPartnerId, 'va_number' => $vaNumber],
            ['customer_id' => $customerId, 'status' => 'active'],
        );
    }
}
