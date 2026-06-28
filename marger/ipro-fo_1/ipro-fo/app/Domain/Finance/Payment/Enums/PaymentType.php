<?php

declare(strict_types=1);

namespace App\Domain\Finance\Payment\Enums;

/**
 * Jenis cicilan/tagihan di Jadwal Tagihan.
 *
 * Sesuai pattern Java Core System Lama:
 *   BF  = Booking Fee
 *   DP  = Down Payment (cicilan DP)
 *   ANG = Angsuran (cicilan KPR / cash bertahap)
 *   ADM = Biaya Administrasi (notaris, BPHTB, AJB, dll)
 */
enum PaymentType: string
{
    case BookingFee  = 'BF';
    case DownPayment = 'DP';
    case Angsuran    = 'ANG';
    case AdminFee    = 'ADM';

    public function label(): string
    {
        return match ($this) {
            self::BookingFee  => 'Booking Fee',
            self::DownPayment => 'Down Payment',
            self::Angsuran    => 'Angsuran',
            self::AdminFee    => 'Biaya Administrasi',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BookingFee  => 'Uang tanda jadi (UTJ) saat reservasi unit',
            self::DownPayment => 'Cicilan DP customer',
            self::Angsuran    => 'Cicilan KPR atau cash bertahap',
            self::AdminFee    => 'Biaya notaris/BPHTB/AJB/sertifikat',
        };
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'description' => $t->description(),
            ],
            self::cases(),
        );
    }
}
