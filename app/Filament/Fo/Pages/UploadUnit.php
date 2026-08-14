<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use App\Modules\FO\Adminjual\Imports\UnitImport;
use App\Filament\Fo\Pages\Concerns\MembacaBerkasUnggahan;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class UploadUnit extends Page implements HasForms
{
    use InteractsWithForms;
    use MembacaBerkasUnggahan;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $title = 'Upload Unit (Excel)';

    protected static ?string $navigationLabel = 'Upload Unit';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'upload-unit';

    protected static string $view = 'filament.fo.pages.upload-unit';

    public ?array $data = [];

    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Pilih Project + Upload File')
                ->description('Format Excel: sheet "UNIT" dengan kolom Lokasi, Tahap, Blok, Nomor, Alamat, Price List Minimum, Price List Rumah, Price List Kavling, Zoning, Panjang, Lebar, Tinggi, Luas Tanah, Luas Lebih Tanah, Luas Bangunan, Lebar Jalan, Arah Hadap, Panorama, Tipe Properti, Kategori Unit. No. Unit dibuat otomatis: Lokasi-Tahap-Blok-Nomer. Lokasi & Tahap WAJIB sudah terdaftar di Setup Unit.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Project')->required()
                        ->options(fn () => Project::pluck('name', 'id'))
                        ->searchable()->preload(),
                    // storeFiles(false): berkas TIDAK dipindahkan ke disk, state menyimpan
                    // TemporaryUploadedFile apa adanya lalu dibaca langsung oleh importer.
                    //
                    // Sebelumnya memakai ->disk('local')->directory('imports/units'), dan
                    // pemindahan itulah yang gagal — state jadi kosong sehingga validasi
                    // melaporkannya sebagai "field is required", pesan yang menyesatkan
                    // karena berkasnya sendiri sudah terunggah utuh dan lolos validasi.
                    // Impor hanya perlu membaca berkas sekali; menyalinnya dulu ke disk
                    // tidak memberi manfaat apa pun, hanya menambah satu titik gagal.
                    Forms\Components\FileUpload::make('file')
                        ->label('File Excel (.xlsx/.xls/.csv)')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->maxSize(20480), // 20 MB
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

        $import = new UnitImport(projectId: (int) $data['project_id']);
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
            ->body("✓ {$import->created} dibuat · ↻ {$import->updated} diperbarui · ⤬ {$import->skipped} dilewati")
            ->success()->send();

        $this->form->fill();
    }
}
