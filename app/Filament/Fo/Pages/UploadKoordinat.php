<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Adminjual\Imports\KoordinatImport;
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

class UploadKoordinat extends Page implements HasForms
{
    use InteractsWithForms;
    use MembacaBerkasUnggahan;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $title = 'Upload Koordinat (Excel)';

    protected static ?string $navigationLabel = 'Upload Koordinat';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 75;

    protected static ?string $slug = 'upload-koordinat';

    protected static string $view = 'filament.fo.pages.upload-koordinat';

    public ?array $data = [];

    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Upload File Excel')
                ->description('Format: sheet "KOORDINAT" dengan kolom No. Unit (mis. JRK3-004-XVI-1), Peta (opsional), Koordinat (mis. "3096,1182,3204,1192,3200,1235,3092,1226,3096,1182" — comma-separated x,y pasangan).')
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

            Forms\Components\Section::make('Editor Polygon Interaktif (Alternatif)')
                ->description('Untuk editing real-time langsung di siteplan tanpa Excel, gunakan editor Leaflet di /fo/upload-koordinat-editor (klik titik di siteplan untuk gambar polygon — auto-save).')
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('info')->label('')->content(new HtmlString(
                        '<a href="'.url('/fo/upload-koordinat-editor').'" class="inline-flex items-center gap-2 text-sm rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">'
                        .'🎯 Buka Polygon Editor</a>'
                    )),
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

        $import = new KoordinatImport;
        try {
            Excel::import($import, $filePath);
        } catch (\Throwable $e) {
            Notification::make()->title('Import gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->result = [
            'updated' => $import->updated,
            'skipped' => $import->skipped,
            'errors' => $import->errors,
        ];

        Notification::make()
            ->title('Import selesai')
            ->body("↻ {$import->updated} polygon di-update · ⤬ {$import->skipped} dilewati")
            ->success()->send();

        $this->form->fill();
    }
}
