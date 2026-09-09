<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Adminjual\Exports\PriceListTemplateExport;
use App\Modules\FO\Adminjual\Imports\PriceListImport;
use App\Modules\FO\Adminjual\Models\Skema;
use Filament\Actions\Action;
use App\Filament\Fo\Pages\Concerns\MembacaBerkasUnggahan;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

class UploadPriceList extends Page implements HasForms
{
    use MematuhiIzinHalaman;

    use InteractsWithForms;
    use MembacaBerkasUnggahan;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $title = 'Upload Price List (Excel)';

    protected static ?string $navigationLabel = 'Upload Price List';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 65;

    protected static ?string $slug = 'upload-price-list';

    protected static string $view = 'filament.fo.pages.upload-pricelist';

    public ?array $data = [];

    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Download Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(new PriceListTemplateExport, 'template-pricelist.xlsx')),
        ];
    }

    public function form(Form $form): Form
    {
        $skemaCols = Skema::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code')
            ->map(fn ($c) => 'Harga '.ucfirst(strtolower($c)))
            ->implode(' | ');

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Upload File Excel — Format Lebar (per skema)')
                ->description(new HtmlString(
                    'Satu baris per unit. Kolom: <strong>No Unit</strong> (mis. JRK3-004-XV-2) lalu satu kolom harga per skema. '
                    .'Berdasarkan skema aktif sekarang: <code>No Unit | '.e($skemaCols).'</code>. '
                    .'Kolom "Harga {Skema}" dicocokkan otomatis ke skema di master. Menambah skema baru cukup tambah skema + kolomnya.'
                ))
                ->schema([
                    Forms\Components\FileUpload::make('file')
                        ->label('File Excel (.xlsx/.xls/.csv)')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->maxSize(20480),
                ]),
        ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $filePath = $this->jalurBerkas($data['file'] ?? null);
        if ($filePath === null) {
            Notification::make()->title('Berkas tidak terbaca')
                ->body('Unggah ulang berkasnya, lalu tunggu sampai kotak unggahan berwarna hijau sebelum menekan tombol.')
                ->danger()->send();

            return;
        }

        $import = new PriceListImport;
        try {
            Excel::import($import, $filePath);
        } catch (\Throwable $e) {
            Notification::make()->title('Import gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->result = [
            'created' => $import->created,
            'updated' => $import->updated,
            'skipped' => $import->skipped,
            'errors' => $import->errors,
        ];

        Notification::make()
            ->title('Import selesai')
            ->body("✓ {$import->created} baru · ↻ {$import->updated} diperbarui · ⤬ {$import->skipped} dilewati")
            ->success()->send();

        $this->form->fill();
    }
}
