<?php

namespace App\Filament\Bo\Pages;

use App\Modules\BO\AccountsReceivable\Services\ArAgingService;
use Filament\Pages\Page;

class ArAgingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Piutang (AR)';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Aging Piutang';

    protected static ?string $navigationLabel = 'Aging Piutang';

    protected static string $view = 'filament.bo.pages.ar-aging';

    /** @var array<string,array{count:int,total:float}> */
    public array $buckets = [];

    public float $total = 0.0;

    public function mount(ArAgingService $aging): void
    {
        $this->buckets = $aging->buckets();
        $this->total = $aging->totalOutstanding();
    }

    /** @return array<string,string> */
    public function bucketLabels(): array
    {
        return [
            'BELUM_JATUH_TEMPO' => 'Belum jatuh tempo',
            '1-30' => '1–30 hari',
            '31-60' => '31–60 hari',
            '61-90' => '61–90 hari',
            '>90' => '> 90 hari',
        ];
    }
}
