<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum Channel: string
{
    case Mail      = 'mail';
    case Whatsapp  = 'whatsapp';
    case Sms       = 'sms';
    case InApp     = 'inApp';
    case Telegram  = 'telegram';
}
