<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\FinanceAR\Models\Bg;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use Filament\Pages\Page;

/** Reminder AR — kartu tindak lanjut (TTS belum posting, giro jatuh tempo/bermasalah, dll). */
class ReminderAr extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $navigationLabel = 'Reminder AR';

    protected static ?string $title = 'Reminder AR';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.fo.pages.reminder-ar';

    /** @return array<int,array<string,mixed>> */
    public function getCards(): array
    {
        $today = now()->toDateString();

        return [
            [
                'label' => 'TTS Belum Posting', 'icon' => 'heroicon-o-ticket', 'color' => '#f59e0b',
                'count' => Tts::whereIn('status', ['DRAFT', 'KONFIRMASI'])->count(),
                'desc' => 'TTS belum dijadikan kuitansi (BKM).', 'url' => url('/fo/tts'),
            ],
            [
                'label' => 'Giro Jatuh Tempo', 'icon' => 'heroicon-o-clock', 'color' => '#0ea5e9',
                'count' => Bg::whereIn('status', ['NORMAL', 'JATUH_TEMPO'])->whereNotNull('tgl_jatuh_tempo')->whereDate('tgl_jatuh_tempo', '<=', $today)->count(),
                'desc' => 'Giro siap dicairkan.', 'url' => url('/fo/bgs'),
            ],
            [
                'label' => 'Giro Bermasalah', 'icon' => 'heroicon-o-exclamation-triangle', 'color' => '#ef4444',
                'count' => Bg::where('status', 'BERMASALAH')->count(),
                'desc' => 'Giro ditolak — perlu ganti giro.', 'url' => url('/fo/bgs'),
            ],
            [
                'label' => 'Transfer Anonim Baru', 'icon' => 'heroicon-o-question-mark-circle', 'color' => '#8b5cf6',
                'count' => TransferAnonim::where('status', 'BARU')->count(),
                'desc' => 'Transfer belum teridentifikasi.', 'url' => url('/fo/transfer-anonims'),
            ],
            [
                'label' => 'Refund Diproses', 'icon' => 'heroicon-o-arrow-uturn-left', 'color' => '#10b981',
                'count' => Refund::where('status', 'DIPROSES')->count(),
                'desc' => 'Refund menunggu penyelesaian.', 'url' => url('/fo/refunds'),
            ],
        ];
    }
}
