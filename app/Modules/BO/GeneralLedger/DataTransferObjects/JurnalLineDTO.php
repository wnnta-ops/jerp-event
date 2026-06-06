<?php

namespace App\Modules\BO\GeneralLedger\DataTransferObjects;

/**
 * One line of a journal entry. Exactly one of debet/kredit should be > 0.
 */
final class JurnalLineDTO
{
    public function __construct(
        public readonly string $kodeAccount,
        public readonly float $debet = 0.0,
        public readonly float $kredit = 0.0,
        public readonly ?string $keterangan = null,
        public readonly ?int $costCenterId = null,
    ) {}

    public static function debet(string $kodeAccount, float $amount, ?string $keterangan = null, ?int $costCenterId = null): self
    {
        return new self($kodeAccount, debet: $amount, keterangan: $keterangan, costCenterId: $costCenterId);
    }

    public static function kredit(string $kodeAccount, float $amount, ?string $keterangan = null, ?int $costCenterId = null): self
    {
        return new self($kodeAccount, kredit: $amount, keterangan: $keterangan, costCenterId: $costCenterId);
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            kodeAccount: (string) $row['kode_account'],
            debet: (float) ($row['debet'] ?? 0),
            kredit: (float) ($row['kredit'] ?? 0),
            keterangan: $row['keterangan'] ?? null,
            costCenterId: isset($row['cost_center_id']) ? (int) $row['cost_center_id'] : null,
        );
    }
}
