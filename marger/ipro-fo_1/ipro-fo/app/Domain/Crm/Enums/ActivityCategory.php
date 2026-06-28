<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

/**
 * Kategori aktivitas CRM (eProperty "Jurnal" tab).
 *
 * 8 kategori sesuai live observation Java Core System Lama.
 */
enum ActivityCategory: string
{
    case FollowUpDocument = 'follow_up_document';
    case VerbalAgreement  = 'verbal_agreement';
    case Complain         = 'complain';
    case LegalMatter      = 'legal_matter';
    case PhoneActivity    = 'phone_activity';
    case CustomerMeeting  = 'customer_meeting';
    case LatestInfo       = 'latest_info';
    case Other            = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FollowUpDocument => 'Follow Up Dokumen',
            self::VerbalAgreement  => 'Agreement Lisan',
            self::Complain         => 'Complain',
            self::LegalMatter      => 'Urusan Legal',
            self::PhoneActivity    => 'Aktivitas Telepon',
            self::CustomerMeeting  => 'Meeting Customer',
            self::LatestInfo       => 'Info Terakhir',
            self::Other            => 'Lainnya',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FollowUpDocument => 'info',
            self::VerbalAgreement  => 'primary',
            self::Complain         => 'danger',
            self::LegalMatter      => 'warning',
            self::PhoneActivity    => 'success',
            self::CustomerMeeting  => 'primary',
            self::LatestInfo       => 'neutral',
            self::Other            => 'neutral',
        };
    }

    /** @return array<int, array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label(), 'color' => $c->color()],
            self::cases(),
        );
    }
}
