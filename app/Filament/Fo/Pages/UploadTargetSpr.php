<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Filament\Fo\Pages\Concerns\MembacaBerkasUnggahan;
use App\Modules\FO\MarketingSales\Exports\SprTargetTemplateExport;
use App\Modules\FO\MarketingSales\Imports\SprTargetImport;
use App\Support\TenantContext;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Upload Target SPR — angka target per bulan yang menjadi garis pada grafik
 * "Sales Report — SPR per Bulan" di dashboard Front Office.
 *
 * Mengikuti pola Upload Price List (halaman + importer + template) karena itu satu-satunya
 * pola impor yang sudah ada di panel ini: berkas dibaca langsung tanpa dipindah ke disk,
 * hasilnya dilaporkan per baris, dan templatenya bisa diunduh.
 */
class UploadTargetSpr extends Page implements HasForms
{
    use MematuhiIzinHalaman;
    use InteractsWithForms;
    use MembacaBerkasUnggahan;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $title = 'Upload Target SPR (Excel)';

    protected static ?string $navigationLabel = 'Upload Target SPR';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 66;

    protected static ?string $slug = 'upload-target-spr';

    protected static string $view = 'filament.fo.pages.upload-target-spr';

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
                // Wajib di-RETURN: tanpa return, tombolnya tampak berhasil tetapi tidak ada
                // berkas yang turun dan tidak ada pesan apa pun yang menjelaskan.
                ->action(fn () => Excel::download(
                    new SprTargetTemplateExport($this->tenantId()),
                    'template-target-spr-'.now()->year.'.xlsx',
                )),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Upload File Excel — satu baris per skenario target')
                ->description(new HtmlString(
                    'Kolom: <code>Tahun | Skenario | Jan | Feb | … | Des</code>. '
                    .'Satu baris untuk tiap skenario target, misalnya <code>Target (WPnB)</code> lalu '
                    .'<code>Adjust 1 (4&amp;5 Feb 26)</code>. Menambah revisi target cukup menambah '
                    .'<strong>baris</strong> — tidak perlu kolom baru. '
                    .'Mengunggah ulang skenario dengan tahun &amp; nama yang sama akan <strong>memperbarui</strong> angkanya, '
                    .'bukan menambah garis kembar di grafik.'
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
                        // Sengaja jauh lebih kecil daripada halaman impor unit: berkas target
                        // hanya berisi beberapa baris, dan batas 20 MB di halaman lain justru
                        // melewati batas bawaan Livewire sehingga galatnya jadi membingungkan.
                        ->maxSize(2048),
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

        $import = new SprTargetImport($this->tenantId(), auth()->id());
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

        // Angka dashboard di-cache 120 detik. Tanpa dibuang di sini, target yang baru saja
        // diunggah belum tampak di grafik sampai dua menit — dan orang menyimpulkan impornya
        // gagal lalu mengunggahnya lagi.
        Cache::forget('fo:dash:stats:'.$this->tenantId());
        Cache::forget('fo:dash:trend:'.$this->tenantId());
        Cache::forget('fo:dash:pipeline:'.$this->tenantId());

        Notification::make()
            ->title('Import selesai')
            ->body("✓ {$import->created} baru · ↻ {$import->updated} diperbarui · ⤬ {$import->skipped} dilewati")
            ->success()->send();

        $this->form->fill();
    }

    private function tenantId(): int
    {
        return (int) (auth()->user()?->tenant_id ?? app(TenantContext::class)->id());
    }
}
