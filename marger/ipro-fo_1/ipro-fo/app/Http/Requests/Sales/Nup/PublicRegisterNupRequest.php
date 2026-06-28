<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales\Nup;

/**
 * Versi PUBLIK dari RegisterNupRequest — boleh diakses guest (tanpa login).
 * Aturan validasi diwarisi penuh.
 */
class PublicRegisterNupRequest extends RegisterNupRequest
{
    public function authorize(): bool
    {
        return true; // form publik
    }
}
