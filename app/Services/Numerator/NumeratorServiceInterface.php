<?php

namespace App\Services\Numerator;

interface NumeratorServiceInterface
{
    /**
     * Atomically allocate the next number for the given document code,
     * optionally scoped by company. Returns the formatted full document
     * number (e.g. "KPR-2025-00042").
     */
    public function next(string $code, ?int $companyId = null): string;

    /**
     * Peek at the next number without consuming a sequence value.
     */
    public function peek(string $code, ?int $companyId = null): string;
}
