<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger;
use App\Models\Company;
use App\Modules\BO\GeneralLedger\Services\FinancialReportService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class NeracaSaldoPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Neraca';

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static ?string $title = 'Neraca Saldo (Trial Balance)';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.bo.gl.neraca-saldo';

    public ?int $companyId = null;

    public ?string $sampaiPeriode = null;

    public function mount(): void
    {
        $this->companyId = Company::query()->value('id');
        $this->sampaiPeriode = now()->format('Y-m');
        $this->form->fill(['companyId' => $this->companyId, 'sampaiPeriode' => $this->sampaiPeriode]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('companyId')->label('Perusahaan')->placeholder('Semua')
                ->options(fn () => Company::query()->pluck('name', 'id'))
                ->live()->afterStateUpdated(fn ($state) => $this->companyId = $state ? (int) $state : null),
            TextInput::make('sampaiPeriode')->label('S/d periode (YYYY-MM)')->placeholder('2026-12')
                ->live(debounce: 600)->afterStateUpdated(fn ($state) => $this->sampaiPeriode = $state),
        ])->columns(2);
    }

    /** @return Collection<int,\stdClass> */
    public function rows(): Collection
    {
        return app(FinancialReportService::class)->trialBalance($this->companyId, $this->sampaiPeriode);
    }

    /** @return array{total_debet:float,total_kredit:float,seimbang:bool} */
    public function totals(): array
    {
        return app(FinancialReportService::class)->trialBalanceTotals($this->companyId, $this->sampaiPeriode);
    }
}
