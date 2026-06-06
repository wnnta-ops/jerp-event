<?php

namespace App\Modules\BO\GeneralLedger\Support;

use App\Modules\BO\GeneralLedger\Contracts\PostsToGeneralLedger;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A lightweight, in-memory posting source for journals that don't originate
 * from a persisted document (penalties, FOBO event payloads, adjustments).
 */
final class AdHocPosting implements PostsToGeneralLedger
{
    /**
     * @param  array<int,JurnalLineDTO>  $lines
     */
    public function __construct(
        private readonly array $lines,
        private readonly string $documentType,
        private readonly string $documentNo,
        private readonly int $companyId,
        private readonly DateTimeInterface $date,
        private readonly ?string $description = null,
    ) {}

    /**
     * @param  array<int,JurnalLineDTO>  $lines
     */
    public static function make(
        array $lines,
        string $documentType,
        string $documentNo,
        int $companyId,
        DateTimeInterface|string|null $date = null,
        ?string $description = null,
    ): self {
        $resolved = $date instanceof DateTimeInterface ? $date : Carbon::parse($date ?? 'now');

        return new self($lines, $documentType, $documentNo, $companyId, $resolved, $description);
    }

    public function glLines(): array
    {
        return $this->lines;
    }

    public function glDocumentType(): string
    {
        return $this->documentType;
    }

    public function glDocumentNo(): string
    {
        return $this->documentNo;
    }

    public function glCompanyId(): int
    {
        return $this->companyId;
    }

    public function glDate(): DateTimeInterface
    {
        return $this->date;
    }

    public function glDescription(): ?string
    {
        return $this->description;
    }
}
