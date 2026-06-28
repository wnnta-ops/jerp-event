<?php

declare(strict_types=1);

namespace App\Support\ValueObjects;

use InvalidArgumentException;

/**
 * Money value object — simpan sebagai cents (integer) untuk akurasi.
 * IDR tidak punya pecahan, jadi 1 unit = 1 rupiah (cents = 0 desimal).
 */
final readonly class Money
{
    public function __construct(
        public int $amount,                          // selalu dalam unit terkecil (rupiah, tanpa desimal)
        public string $currency = 'IDR',
    ) {}

    public static function fromRupiah(int|float|string $rupiah): self
    {
        return new self((int) round((float) $rupiah));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multipliedBy(float $factor): self
    {
        return new self((int) round($this->amount * $factor), $this->currency);
    }

    public function percent(float $pct): self
    {
        return $this->multipliedBy($pct / 100);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function format(): string
    {
        return format_rupiah($this->amount);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Mata uang berbeda: {$this->currency} vs {$other->currency}");
        }
    }
}
