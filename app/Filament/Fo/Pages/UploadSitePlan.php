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
        // Setelah Ganti/Hapus gambar via upload langsung, controller redirect dgn ?lok=ID →
        // pilih otomatis cluster tsb agar Preview langsung tampil gambar terbaru.
        $l = ($lok = (int) request()->query('lok')) ? Lokasi::find($lok) : null;
        if ($l) {
            $this->form->fill([
                'project_id'           => $l->project_id,
                'lokasi_id'            => $l->id,
                'siteplan_show_info'   => $l->resolvedShowInfo(),
                'siteplan_show_status' => $l->resolvedShowStatus(),
                'width'                => $l->siteplan_width,
                'height'               => $l->siteplan_height,
            ]);

            return;
        }
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Cluster & Dimensi Site Plan')
                ->description('Pilih Cluster untuk atur dimensi & tampilan. Untuk mengganti/menghapus gambar, pakai tombol "Edit Gambar Site Plan" di atas. Setelah gambar berubah, sesuaikan koordinat di Polygon Editor — kalau layout sama cukup "Geser Semua" (tak perlu gambar ulang).')
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

    /** Simpan DIMENSI + setelan tampilan cluster. Ganti/hapus GAMBAR lewat tombol "Edit Gambar Site Plan" (SitePlanImageController). */
    public function submit(): void
    {
        $data = $this->form->getState();
        $lokasi = Lokasi::findOrFail($data['lokasi_id']);

        $lokasi->update([
            'siteplan_show_info'   => (bool) ($data['siteplan_show_info'] ?? true),
            'siteplan_show_status' => (bool) ($data['siteplan_show_status'] ?? true),
            'siteplan_width'       => $data['width'] ?: $lokasi->siteplan_width,
            'siteplan_height'      => $data['height'] ?: $lokasi->siteplan_height,
        ]);

        Notification::make()->title('Dimensi & setelan cluster disimpan')->success()->send();

        $this->form->fill([
            'project_id'           => $lokasi->project_id,
            'lokasi_id'            => $lokasi->id,
            'siteplan_show_info'   => $lokasi->resolvedShowInfo(),
            'siteplan_show_status' => $lokasi->resolvedShowStatus(),
            'width'                => $lokasi->siteplan_width,
            'height'               => $lokasi->siteplan_height,
        ]);
    }
}
