<?php

declare(strict_types=1);

namespace App\Domain\Finance\Payment\Enums;

/**
 * Cara bayar (payment method) yang dipakai untuk allocate ke schedule.
 *
 * Sesuai pattern Java Core System Lama:
 *   TN = Tunai (Cash)
 *   KD = Kartu Debit
 *   KK = Kartu Kredit
 *   TR = Transfer Bank
 *   BG = Cek / Bilyet Giro
 *   DN = Diskon (Discount-as-Payment — Free DP / Subsidi)
 *   KR = Kredit Kepemilikan Rumah (KPR Disbursement)
 */
enum PaymentMethod: string
{
    case Tunai          = 'TN';
    case KartuDebit     = 'KD';
    case KartuKredit    = 'KK';
    case TransferBank   = 'TR';
    case CekGiro        = 'BG';
    case Diskon         = 'DN';
    case KPR            = 'KR';

    public function label(): string
    {
        return match ($this) {
            self::Tunai         => 'Tunai',
            self::KartuDebit    => 'Kartu Debit',
            self::KartuKredit   => 'Kartu Kredit',
            self::TransferBank  => 'Transfer Bank',
            self::CekGiro       => 'Cek / Bilyet Giro',
            self::Diskon        => 'Diskon',
            self::KPR           => 'KPR',
        };
    }

    /**
     * Apakah method ini = uang masuk real (cash equivalent)?
     * Diskon dan KPR pre-cair = bukan cash.
     */
    public function isCashLike(): bool
    {
        return in_array($this, [
            self::Tunai, self::KartuDebit, self::KartuKredit,
            self::TransferBank, self::CekGiro,
        ], true);
    }

    public function isDiscount(): bool
    {
        return $this === self::Diskon;
    }

    public function isKpr(): bool
    {
        return $this === self::KPR;
    }

    /** @return array<int, array{value: string, label: string, is_cash: bool}> */
    public static function options(): array
    {
        return array_map(
            fn (self $m) => [
                'value' => $m->value,
                'label' => $m->label(),
                'is_cash' => $m->isCashLike(),
            ],
            self::cases(),
        );
    }
}
