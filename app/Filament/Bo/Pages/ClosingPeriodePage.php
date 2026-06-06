<?php

namespace App\Filament\Bo\Pages;

use App\Filament\Bo\Clusters\GeneralLedger;
use App\Models\Company;
use App\Modules\BO\GeneralLedger\Models\Period;
use App\Modules\BO\GeneralLedger\Services\PeriodClosingService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Throwable;

class ClosingPeriodePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Generate Laporan Keuangan Perusahaan';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Closing Fiskal';

    protected static ?string $navigationLabel = 'Closing Fiskal';

    protected static string $view = 'filament.bo.pages.closing-periode';

    /** @var array<string,mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'company_id' => Company::query()->value('id'),
            'periode' => now()->subMonth()->format('Y-m'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('company_id')->label('Company')->options(fn () => Company::query()->pluck('name', 'id'))->required(),
            TextInput::make('periode')->label('Periode (YYYY-MM)')->placeholder('2026-04')->required()
                ->regex('/^\d{4}-\d{2}$/'),
        ])->columns(2)->statePath('data');
    }

    public function close(): void
    {
        $state = $this->form->getState();
        try {
            app(PeriodClosingService::class)->close((int) $state['company_id'], $state['periode']);
            Notification::make()->success()->title('Periode '.$state['periode'].' ditutup')->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Gagal menutup periode')->body($e->getMessage())->send();
        }
    }

    public function reopen(): void
    {
        $state = $this->form->getState();
        try {
            app(PeriodClosingService::class)->reopen((int) $state['company_id'], $state['periode']);
            Notification::make()->success()->title('Periode '.$state['periode'].' dibuka kembali')->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Gagal membuka periode')->body($e->getMessage())->send();
        }
    }

    /** @return Collection<int,Period> */
    public function recentPeriods()
    {
        return Period::query()->orderByDesc('periode')->limit(12)->get();
    }
}
