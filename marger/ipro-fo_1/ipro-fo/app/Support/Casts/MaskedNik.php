<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Default cast untuk display NIK / NPWP — mask kecuali user punya permission view.sensitive.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class MaskedNik implements Castable, CastsAttributes
{
    public function __construct(private readonly string $permissionToReveal) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $user = auth()->user();
        if ($user && $user->can($this->permissionToReveal)) {
            return (string) $value;
        }

        $length = strlen((string) $value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr((string) $value, -4);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public static function castUsing(array $arguments): self
    {
        return new self($arguments[0] ?? 'master.customer.view.sensitive');
    }
}
