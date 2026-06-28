<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Enums;

/**
 * Jenis dokumen yang di-checklist di Contract (Kelengkapan Berkas).
 */
enum DocumentType: string
{
    case KK         = 'kk';
    case KTP        = 'ktp';
    case NPWP       = 'npwp';
    case AktaNikah  = 'akta_nikah';
    case SuratKuasa = 'surat_kuasa';
    case PPJB       = 'ppjb';
    case Lainnya    = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::KK         => 'Kartu Keluarga (KK)',
            self::KTP        => 'KTP',
            self::NPWP       => 'NPWP',
            self::AktaNikah  => 'Akta Nikah',
            self::SuratKuasa => 'Surat Kuasa',
            self::PPJB       => 'PPJB',
            self::Lainnya    => 'Lainnya',
        };
    }

    /**
     * Dokumen wajib untuk action tertentu.
     *
     * @return array<int, self>
     */
    public static function requiredFor(string $action): array
    {
        return match ($action) {
            'ppjb_sign' => [self::KK, self::KTP, self::NPWP],
            'ajb_sign'  => [self::KK, self::KTP, self::NPWP, self::AktaNikah, self::PPJB],
            default     => [],
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $d) => ['value' => $d->value, 'label' => $d->label()],
            self::cases(),
        );
    }
}
