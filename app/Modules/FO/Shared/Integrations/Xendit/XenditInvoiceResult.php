<?php

namespace App\Modules\FO\Shared\Integrations\Xendit;

final class XenditInvoiceResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $invoiceUrl,
        public readonly string $status, // PENDING | PAID | EXPIRED
    ) {}
}
