<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class UploadSitePlan extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $title = 'Upload Site Plan';

    protected static ?string $navigationLabel = 'Upload Site Plan';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'upload-site-plan';

    protected static string $view = 'filament.fo.pages.upload-siteplan';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Upload Gambar Site Plan')
                ->description('Gambar yang dipakai untuk render Leaflet siteplan + polygon editor. Format: PNG/JPG, maks 10 MB. Resolusi tinggi disarankan (≥ 2000×1200).')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Project / Cluster')->required()
                        ->options(fn () => Project::pluck('name', 'id'))
                        ->searchable()->preload(),
                    Forms\Components\FileUpload::make('file')
                        ->label('Gambar Site Plan')
                        ->required()
                        ->image()->imagePreviewHeight('300')
                        ->disk('public')->directory('siteplans')
                        ->visibility('public')
                        ->maxSize(10240),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('width')->label('Lebar (px)')->numeric()
                            ->helperText('Resolusi asli untuk koordinat. Auto-detect dari gambar kalau kosong.'),
                        Forms\Components\TextInput::make('height')->label('Tinggi (px)')->numeric(),
                    ]),
                ]),

            Forms\Components\Section::make('Preview Site Plan Existing')
                ->schema([
                    Forms\Components\Placeholder::make('preview')
                        ->label('')
                        ->content(fn () => $this->preview()),
                ]),
        ]);
    }

    private function preview(): HtmlString
    {
        $project = isset($this->data['project_id']) ? Project::find($this->data['project_id']) : null;
        if (! $project || ! $project->siteplan_image_path) {
            return new HtmlString('<div class="text-sm text-gray-500">Pilih project untuk lihat preview.</div>');
        }

        $url = Storage::disk('public')->url($project->siteplan_image_path);

        return new HtmlString(
            '<img src="'.e($url).'" class="rounded-lg border max-w-full" style="max-height:400px;" alt="Site Plan">'
        );
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $project = Project::findOrFail($data['project_id']);

        // Hapus siteplan lama kalau ada
        if ($project->siteplan_image_path && Storage::disk('public')->exists($project->siteplan_image_path)) {
            Storage::disk('public')->delete($project->siteplan_image_path);
        }

        $w = $data['width'] ?: null;
        $h = $data['height'] ?: null;

        if ((! $w || ! $h) && function_exists('getimagesize')) {
            $size = @getimagesize(Storage::disk('public')->path($data['file']));
            if ($size) {
                $w = $w ?: $size[0];
                $h = $h ?: $size[1];
            }
        }

        $project->update([
            'siteplan_image_path' => $data['file'],
            'siteplan_width' => $w,
            'siteplan_height' => $h,
        ]);

        Notification::make()
            ->title('Site plan berhasil diupload')
            ->body($project->name.' — '.($w ? $w.'×'.$h : 'dimensi belum di-set'))
            ->success()->send();

        $this->form->fill(['project_id' => $project->id]);
    }
}
