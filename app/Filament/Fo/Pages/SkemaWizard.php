<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Fo\Resources\SkemaResource;
use App\Models\Project;
use App\Modules\FO\Adminjual\Services\SkemaCalculatorService;
use App\Modules\FO\Adminjual\Services\SkemaWizardService;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * "Pendaftaran Skema Baru" (Gambar 1): wizard Rumus Global + Nilai + Jadwal →
 * generate Skema + baris termin (template), lalu redirect ke editor grid (Gambar 2).
 * Tombol "Simulasi" menampilkan rincian rupiah (Gambar 2) memakai Present Value.
 */
class SkemaWizard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    // Diakses lewat tombol "Buat Skema (Wizard)" di list Skema, bukan menu sendiri.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Pendaftaran Skema Baru';

    protected static string $view = 'filament.fo.pages.skema-wizard';

    /** @var array<string,mixed> */
    public array $data = [];

    /** @var array<string,mixed> Hasil simulasi (breakdown) untuk ditampilkan di view. */
    public array $preview = [];

    public function mount(): void
    {
        $this->form->fill([
            'cara_bayar' => 'KPR', 'bf_dipotong_di' => 'ANGSURAN1', 'tampil_siteplan' => false,
            'present_value' => 0, 'diskon_persen' => 0, 'subsidi_persen' => 20,
            'bf_count' => 1, 'bf_nilai' => 5000000, 'bf_satuan' => 'RP',
            'bf_pertama' => 0, 'bf_pertama_basis' => 'HARI', 'bf_interval' => 0, 'bf_interval_basis' => 'HARI',
            'dp_count' => 5, 'dp_nilai' => 30, 'dp_satuan' => 'PERSEN',
            'dp_pertama' => 1, 'dp_pertama_basis' => 'BULAN', 'dp_interval' => 1, 'dp_interval_basis' => 'BULAN',
            'ang_count' => 1, 'ang_nilai' => 70, 'ang_satuan' => 'PERSEN',
            'ang_pertama' => 1, 'ang_pertama_basis' => 'BULAN', 'ang_interval' => 1, 'ang_interval_basis' => 'BULAN',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Rumus Global')
                ->columns(2)
                ->schema([
                    Select::make('project_id')->label('Project')
                        ->options(fn () => Project::query()->pluck('name', 'id'))->searchable(),
                    Checkbox::make('tampil_siteplan')->label('Site Plan')->inline()
                        ->helperText('Tampilkan harga skema ini di siteplan publik'),
                    TextInput::make('name')->label('Nama Skema')->required()->columnSpan(2),
                    Select::make('lokasi_id')->label('Lokasi')->placeholder('Semua (umum)')
                        ->options(fn () => Lokasi::query()->pluck('nama', 'id'))
                        ->searchable()->live()->afterStateUpdated(fn (Set $set) => $set('tahap_id', null)),
                    Select::make('tahap_id')->label('Tahap')->placeholder('Semua (umum)')
                        ->options(fn (Get $get) => Tahap::query()->when($get('lokasi_id'), fn ($query, $id) => $query->where('lokasi_id', $id))->pluck('nama', 'id'))
                        ->searchable(),
                    TextInput::make('present_value')->label('Present Value')->numeric()->prefix('Rp')->default(0)
                        ->helperText('Harga acuan untuk simulasi (nominal nyata dihitung per unit dari price list)'),
                    TextInput::make('diskon_persen')->label('Diskon')->numeric()->suffix('% bertingkat')->default(0),
                    Radio::make('cara_bayar')->label('Tipe')->inline()->inlineLabel(false)->options([
                        'KPR' => 'KPR', 'CASH_BERTAHAP' => 'Cash bertahap', 'CASH_KERAS' => 'Cash keras',
                    ])->default('KPR')->required(),
                    TextInput::make('subsidi_persen')->label('Subsidi developer')->numeric()->suffix('%')->default(20)
                        ->helperText('Dipakai mode DP Disebar (Free X% dari DP)'),
                    Radio::make('bf_dipotong_di')->label('Booking Fee dipotong di')->inline()->inlineLabel(false)
                        ->columnSpanFull()->options([
                            'ANGSURAN1' => 'FREE DP', 'DP_DISEBAR' => 'DP 3x', 'ANGSURAN_DISEBAR' => 'CASH / INHOUSE',
                        ])->default('ANGSURAN1'),
                ]),

            Section::make('Rumus Nilai')
                ->description('Jumlah termin × nilai untuk tiap komponen (Rp atau % dari harga).')
                ->schema([
                    $this->nilaiRow('bf', 'Booking Fee'),
                    $this->nilaiRow('dp', 'DP'),
                    $this->nilaiRow('ang', 'Angsuran'),
                ]),

            Section::make('Rumus Jadwal')
                ->description('Pertama = jeda termin pertama; Interval = jeda antar termin berikutnya.')
                ->schema([
                    $this->jadwalRow('bf', 'Booking Fee', 'HARI'),
                    $this->jadwalRow('dp', 'DP', 'BULAN'),
                    $this->jadwalRow('ang', 'Angsuran', 'BULAN'),
                ]),

            Actions::make([
                FormAction::make('simulasi')->label('Simulasi (Present Value)')
                    ->icon('heroicon-o-calculator')->color('gray')->action('simulasi'),
            ]),
        ]);
    }

    /** Baris Rumus Nilai: jumlah × nilai + satuan Rp/%. */
    private function nilaiRow(string $key, string $label): Fieldset
    {
        return Fieldset::make($label)->columns(12)->schema([
            TextInput::make($key.'_count')->label('Jumlah')->numeric()->default(1)->columnSpan(2),
            TextInput::make($key.'_nilai')->label('Nilai')->numeric()->columnSpan(6),
            Radio::make($key.'_satuan')->label('Satuan')->inline()->inlineLabel(false)
                ->options(['RP' => 'Rp', 'PERSEN' => '%'])->default('PERSEN')->columnSpan(4),
        ]);
    }

    /** Baris Rumus Jadwal: Pertama (nilai + Bln/Hari) + Interval (nilai + Bln/Hari). */
    private function jadwalRow(string $key, string $label, string $defaultBasis): Fieldset
    {
        return Fieldset::make($label)->columns(12)->schema([
            TextInput::make($key.'_pertama')->label('Pertama')->numeric()->default(0)->columnSpan(2),
            Radio::make($key.'_pertama_basis')->label('Satuan')->inline()->inlineLabel(false)
                ->options(['BULAN' => 'Bln', 'HARI' => 'Hari'])->default($defaultBasis)->columnSpan(4),
            TextInput::make($key.'_interval')->label('Interval')->numeric()->default(0)->columnSpan(2),
            Radio::make($key.'_interval_basis')->label('Satuan')->inline()->inlineLabel(false)
                ->options(['BULAN' => 'Bln', 'HARI' => 'Hari'])->default($defaultBasis)->columnSpan(4),
        ]);
    }

    /** Hitung & tampilkan breakdown memakai Present Value (tanpa menyimpan skema). */
    public function simulasi(): void
    {
        $d = $this->form->getState();
        $harga = (float) ($d['present_value'] ?? 0);

        if ($harga <= 0) {
            Notification::make()->warning()->title('Isi Present Value dulu')->body('Present Value dipakai sebagai harga acuan simulasi.')->send();

            return;
        }

        $this->preview = app(SkemaCalculatorService::class)->compute([
            'mode' => $d['bf_dipotong_di'] ?? 'TIDAK_DISEBAR',
            'cara_bayar' => $d['cara_bayar'] ?? 'KPR',
            'utj' => ($d['bf_satuan'] ?? 'RP') === 'RP' ? (float) ($d['bf_nilai'] ?? 0) : (float) ($d['bf_nilai'] ?? 0) / 100 * $harga,
            'dp_persen' => (float) ($d['dp_nilai'] ?? 0),
            'ang_persen' => (float) ($d['ang_nilai'] ?? 0),
            'subsidi_persen' => (float) ($d['subsidi_persen'] ?? 0),
            'n_dp' => max(1, (int) ($d['dp_count'] ?? 1)),
            'n_ang' => max(1, (int) ($d['ang_count'] ?? 1)),
        ], $harga);
    }

    public function generate()
    {
        $d = $this->form->getState();

        try {
            $skema = app(SkemaWizardService::class)->create(
                [
                    'name' => $d['name'], 'project_id' => $d['project_id'] ?? null,
                    'lokasi_id' => $d['lokasi_id'] ?? null, 'tahap_id' => $d['tahap_id'] ?? null,
                    'cara_bayar' => $d['cara_bayar'] ?? 'KPR', 'present_value' => $d['present_value'] ?? 0,
                    'diskon_persen' => $d['diskon_persen'] ?? 0, 'subsidi_persen' => $d['subsidi_persen'] ?? 20,
                    'bf_dipotong_di' => $d['bf_dipotong_di'] ?? 'TIDAK_DISEBAR',
                    'tampil_siteplan' => (bool) ($d['tampil_siteplan'] ?? false),
                ],
                $d,
            );
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Gagal generate skema')->body($e->getMessage())->send();

            return null;
        }

        Notification::make()->success()->title('Skema "'.$skema->name.'" dibuat')->body('Silakan cek/edit baris termin.')->send();

        return redirect(SkemaResource::getUrl('edit', ['record' => $skema]));
    }
}
