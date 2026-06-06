<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Pages;

use App\Filament\Bo\Clusters\GeneralLedger;
use App\Models\Company;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use App\Modules\BO\GeneralLedger\Models\Period;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * GL landing page — fiscal-period dashboard. Shows a 12-month grid with journal
 * counts per state, mirroring the legacy "Periode Fiskal" screen.
 *
 * Provisional state mapping (to be finalised from the operator's flow):
 *   Baru = DRAFT · Final = APPROVED · Approved = POSTED · Closed = period CLOSED.
 */
class PeriodeFiskalPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Periode Fiskal';

    protected static ?string $title = 'General Ledger — Periode Fiskal';

    protected static ?int $navigationSort = -100; // first item in the cluster

    protected static string $view = 'filament.bo.gl.periode-fiskal';

    public ?int $tahun = null;

    public ?int $companyId = null;

    public function mount(): void
    {
        $this->tahun = (int) now()->format('Y');
        $this->companyId = Company::query()->value('id');
        $this->form->fill(['tahun' => $this->tahun, 'companyId' => $this->companyId]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('tahun')->label('Tahun')->numeric()->required()
                ->live(debounce: 500)->afterStateUpdated(fn ($state) => $this->tahun = (int) $state),
            Select::make('companyId')->label('Perusahaan')->placeholder('Semua')
                ->options(fn () => Company::query()->pluck('name', 'id'))
                ->live()->afterStateUpdated(fn ($state) => $this->companyId = $state ? (int) $state : null),
        ])->columns(2);
    }

    /**
     * @return array<int,array{bulan:int,baru:int,final:int,approved:int,closed:bool}>
     */
    public function getRows(): array
    {
        $tahun = $this->tahun ?? (int) now()->format('Y');

        $counts = JurnalHeader::query()
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->where('periode', 'like', $tahun.'-%')
            ->selectRaw('periode, status, COUNT(*) as total')
            ->groupBy('periode', 'status')
            ->get()
            ->groupBy('periode');

        $closedPeriodes = Period::query()
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->where('status', 'CLOSED')
            ->where('periode', 'like', $tahun.'-%')
            ->pluck('periode')
            ->all();

        $rows = [];
        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $periode = sprintf('%04d-%02d', $tahun, $bulan);
            $byStatus = ($counts[$periode] ?? collect())->keyBy('status');

            $rows[] = [
                'bulan' => $bulan,
                'baru' => (int) ($byStatus['DRAFT']->total ?? 0),
                'final' => (int) ($byStatus['APPROVED']->total ?? 0),
                'approved' => (int) ($byStatus['POSTED']->total ?? 0),
                'closed' => in_array($periode, $closedPeriodes, true),
            ];
        }

        return $rows;
    }
}
