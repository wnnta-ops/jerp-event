<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
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
            Forms\Components\Section::make('Upload Gambar Site Plan (per Cluster / Lokasi)')
                ->description('Tiap Cluster (Lokasi) punya siteplan sendiri. Kosong → otomatis pakai gambar project sebagai fallback. Format PNG/JPG, maks 10 MB, resolusi ≥ 2000×1200. Setelah ganti gambar cluster, gambar ulang koordinat unit di Polygon Editor.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Project')->required()
                        ->options(fn () => Project::pluck('name', 'id'))
                        ->searchable()->preload()->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('lokasi_id', null)),
                    Forms\Components\Select::make('lokasi_id')
                        ->label('Cluster / Lokasi')->required()
                        ->options(fn (Forms\Get $get) => $get('project_id')
                            ? Lokasi::where('project_id', $get('project_id'))->orderBy('kode')->pluck('nama', 'id')
                            : [])
                        ->disabled(fn (Forms\Get $get) => ! $get('project_id'))
                        ->searchable()->preload()->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $l = $state ? Lokasi::find($state) : null;
                            $set('siteplan_show_info', $l?->resolvedShowInfo() ?? true);
                            $set('siteplan_show_status', $l?->resolvedShowStatus() ?? true);
                            $set('width', $l?->siteplan_width);
                            $set('height', $l?->siteplan_height);
                        }),
                    Forms\Components\FileUpload::make('file')
                        ->label('Gambar Site Plan Cluster')
                        ->image()->imagePreviewHeight('300')
                        ->disk('public')->directory('siteplans')
                        ->visibility('public')
                        ->maxSize(10240)
                        ->helperText('Kosongkan kalau hanya ingin ubah setelan tampilan (tanpa ganti gambar).'),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('width')->label('Lebar (px)')->numeric()
                            ->helperText('Resolusi asli untuk koordinat. Auto-detect dari gambar kalau kosong.'),
                        Forms\Components\TextInput::make('height')->label('Tinggi (px)')->numeric(),
                    ]),
                ]),

            Forms\Components\Section::make('Setelan Tampilan Site Plan (publik /siteplan)')
                ->description('Atur apa yang tampil ke pengunjung di /siteplan untuk cluster ini. Pilih Cluster dulu, lalu klik "Simpan Setelan". Checklist ini hanya di sini — tidak tampil ke pengunjung.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('siteplan_show_info')
                        ->label('Tampilkan Informasi (statistik Ready / Sold / Hold)')
                        ->default(true),
                    Forms\Components\Toggle::make('siteplan_show_status')
                        ->label('Tampilkan Status (stempel SOLD/BOOKED + badge di popup)')
                        ->default(true),
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
        $lokasi = isset($this->data['lokasi_id']) ? Lokasi::find($this->data['lokasi_id']) : null;
        $sp = $lokasi?->resolvedSiteplan();
        if (! $sp) {
            return new HtmlString('<div class="text-sm text-gray-500">Pilih Cluster untuk lihat preview.</div>');
        }

        $badge = $lokasi->hasOwnSiteplan()
            ? '<span style="color:#16a34a;font-weight:600;">✓ Gambar cluster sendiri</span>'
            : '<span style="color:#d97706;font-weight:600;">↩ Fallback gambar project (cluster belum punya gambar sendiri)</span>';

        return new HtmlString(
            '<div class="text-xs mb-2">'.$badge.' — '.$sp['width'].'×'.$sp['height'].' px</div>'.
            '<img src="'.e($sp['url']).'" class="rounded-lg border max-w-full" style="max-height:400px;" alt="Site Plan">'
        );
    }

    /** Simpan setelan tampilan (toggle Informasi/Status) ke Cluster (Lokasi) terpilih. */
    public function saveDisplay(): void
    {
        $lokasiId = $this->data['lokasi_id'] ?? null;
        if (! $lokasiId) {
            Notification::make()->warning()->title('Pilih Cluster dulu')->send();

            return;
        }

        Lokasi::whereKey($lokasiId)->update([
            'siteplan_show_info' => (bool) ($this->data['siteplan_show_info'] ?? true),
            'siteplan_show_status' => (bool) ($this->data['siteplan_show_status'] ?? true),
        ]);

        Notification::make()->success()
            ->title('Setelan tampilan disimpan')
            ->body('Refresh /siteplan untuk melihat perubahan.')
            ->send();
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $lokasi = Lokasi::findOrFail($data['lokasi_id']);

        $payload = [
            'siteplan_show_info' => (bool) ($data['siteplan_show_info'] ?? true),
            'siteplan_show_status' => (bool) ($data['siteplan_show_status'] ?? true),
        ];

        if (! empty($data['file'])) {
            // Ganti gambar cluster (hapus gambar lama milik cluster ini saja).
            if ($lokasi->siteplan_image_path && Storage::disk('public')->exists($lokasi->siteplan_image_path)) {
                Storage::disk('public')->delete($lokasi->siteplan_image_path);
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

            $payload += ['siteplan_image_path' => $data['file'], 'siteplan_width' => $w, 'siteplan_height' => $h];
            $lokasi->update($payload);

            Notification::make()->title('Site plan cluster diupload')
                ->body($lokasi->nama.' — '.($w ? $w.'×'.$h : 'dimensi belum di-set').'. Gambar ulang koordinat di Polygon Editor.')
                ->success()->send();
        } else {
            $lokasi->update($payload);
            Notification::make()->title('Setelan cluster disimpan')->success()->send();
        }

        $this->form->fill(['project_id' => $lokasi->project_id, 'lokasi_id' => $lokasi->id]);
    }
}
