<?php

namespace App\Modules\Event\Data;

/**
 * Potret satu customer FO untuk event MANDIRI (tanpa event NUP). Dibuat hanya
 * oleh FoNupLookup.
 */
final class FoCustomerData
{
    public function __construct(
        public readonly int $customer_id,
        public readonly ?string $customer_code,
        public readonly ?string $name,
        public readonly ?string $phone,
        /** No. NUP bila customer dikenali lewat No. NUP yang diketik (info saja). */
        public readonly ?string $no_nup = null,
    ) {}
}
