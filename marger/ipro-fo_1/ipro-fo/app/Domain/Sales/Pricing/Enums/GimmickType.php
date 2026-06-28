<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Enums;

/**
 * Tipe gimmick (sesuai pattern Java Core System Lama "Tipe Barang").
 *
 *   Cash         = Cash bonus / refund
 *   Discount     = Diskon harga
 *   ServiceWaiver= Waiver biaya jasa (BPHTB, AJB, dll)
 *   Physical     = Barang fisik (canopy, taman, AC)
 *   Voucher3rd   = Voucher pihak ketiga (IKEA, dll)
 *   Service      = Layanan tambahan
 *   Warranty     = Garansi (anti rayap, struktur)
 *   Voucher      = Voucher generic / "FREE X"
 */
enum GimmickType: string
{
    case Cash          = 'cash';
    case Discount      = 'discount';
    case ServiceWaiver = 'service_waiver';
    case Physical      = 'physical';
    case Voucher3rd    = 'voucher_3rd';
    case Service       = 'service';
    case Warranty      = 'warranty';
    case Voucher       = 'voucher';

    public function label(): string
    {
        return match ($this) {
            self::Cash          => 'Cash',
            self::Discount      => 'Discount',
            self::ServiceWaiver => 'Service Waiver',
            self::Physical      => 'Barang Fisik',
            self::Voucher3rd    => 'Voucher Pihak Ketiga',
            self::Service       => 'Layanan',
            self::Warranty      => 'Garansi',
            self::Voucher       => 'Voucher',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash, self::Discount      => 'success',
            self::ServiceWaiver, self::Warranty => 'info',
            self::Physical                  => 'primary',
            self::Voucher, self::Voucher3rd => 'warning',
            self::Service                   => 'neutral',
        };
    }

    /** @return array<int, array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label(), 'color' => $t->color()],
            self::cases(),
        );
    }
}
