<?php

namespace App\Modules\Event\Data;

/**
 * Hasil satu scan, dikirim ke HP scanner sebagai JSON.
 *
 * status: accepted | rejected | duplicate | pick (perlu pilih NUP).
 */
final class ScanResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const DUPLICATE = 'duplicate';

    public const PICK = 'pick';

    /**
     * @param  list<array{nup_registration_id:int, no_nup:?string, name:?string}>  $candidates
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $name = null,
        public readonly ?string $no_nup = null,
        public readonly ?int $participant_id = null,
        public readonly ?string $warning = null,
        public readonly array $candidates = [],
        public readonly ?int $log_id = null,
        public readonly ?string $action = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
