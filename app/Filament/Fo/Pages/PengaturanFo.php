<?php

namespace App\Filament\Fo\Pages;

use App\Settings\FoSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PengaturanFo extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Pengaturan FO';

    protected static ?string $navigationLabel = 'Pengaturan FO';

    protected static string $view = 'filament.fo.pages.pengaturan-fo';

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(FoSettings::class);
        $this->form->fill([
            'default_booking_fee' => $s->default_booking_fee,
            'default_tenor_bulan' => $s->default_tenor_bulan,
            'default_dp_persen' => $s->default_dp_persen,
            'reservasi_expiry_days' => $s->reservasi_expiry_days,
            'tts_auto_confirm_minutes' => $s->tts_auto_confirm_minutes,
            'recaptcha_enabled' => $s->recaptcha_enabled,
            'recaptcha_min_score' => $s->recaptcha_min_score,
            'komisi_pph_default_persen' => $s->komisi_pph_default_persen,
            'notification_email' => $s->notification_email,
            'siteplanlive_show_stock' => $s->siteplanlive_show_stock,
            'siteplanlive_sold_animation' => $s->siteplanlive_sold_animation,
            'siteplanlive_focus_lokasi_id' => $s->siteplanlive_focus_lokasi_id,
            'siteplanlive_focus_tahap' => $s->siteplanlive_focus_tahap,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Default nilai transaksi')->columns(3)->schema([
                Forms\Components\TextInput::make('default_booking_fee')->label('Booking fee default')->numeric()->rupiah()->required(),
                Forms\Components\TextInput::make('default_tenor_bulan')->label('Tenor default (bulan)')->numeric()->required(),
                Forms\Components\TextInput::make('default_dp_persen')->label('DP default (%)')->numeric()->suffix('%')->required(),
                Forms\Components\TextInput::make('reservasi_expiry_days')->label('Expiry reservasi (hari)')->numeric()->required(),
                Forms\Components\TextInput::make('tts_auto_confirm_minutes')->label('TTS auto-confirm (menit, 0 = manual)')->numeric()->required(),
                Forms\Components\TextInput::make('komisi_pph_default_persen')->label('PPh komisi default (%)')->numeric()->suffix('%')->required(),
            ]),

            Forms\Components\Section::make('NUP Public')->columns(2)->schema([
                Forms\Components\Toggle::make('recaptcha_enabled')->label('Aktifkan reCAPTCHA v3'),
                Forms\Components\TextInput::make('recaptcha_min_score')->label('Skor minimum reCAPTCHA')->numeric()->step(0.01),

                // Menutup pintu masuk TANPA menyentuh acara launching. Menutup acara
                // mengubah arti datanya; ini hanya menahan pendaftar baru sementara.
                Forms\Components\Toggle::make('nup_public_maintenance')
                    ->label('Mode pemeliharaan (formulir publik ditutup)')
                    ->helperText('Pengunjung melihat halaman pemberitahuan, bukan formulir.')
                    ->live()
                    ->columnSpanFull(),
                Forms\Components\TimePicker::make('nup_public_maintenance_start')
                    ->label('Mulai pukul')->seconds(false)->format('H:i')
                    ->visible(fn (Forms\Get $get) => (bool) $get('nup_public_maintenance')),
                Forms\Components\TimePicker::make('nup_public_maintenance_end')
                    ->label('Selesai pukul')->seconds(false)->format('H:i')
                    ->visible(fn (Forms\Get $get) => (bool) $get('nup_public_maintenance')),
                Forms\Components\TextInput::make('nup_public_maintenance_note')
                    ->label('Keterangan tambahan (opsional)')
                    ->placeholder('mis. Mohon mendaftar kembali setelah jam tersebut.')
                    ->maxLength(200)
                    ->visible(fn (Forms\Get $get) => (bool) $get('nup_public_maintenance'))
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Notifikasi')->schema([
                Forms\Components\TextInput::make('notification_email')->label('Email notifikasi')->email()->required(),
            ]),

            Forms\Components\Section::make('Siteplan Live (acara NUP)')
                ->description('Tampilan live stock di /siteplanlive. BOOKING dihitung sebagai SOLD. Animasi SOLD OUT keluar otomatis saat scope yang dipilih (cluster + tahap) 100% terjual.')
                ->columns(2)->schema([
                    Forms\Components\Toggle::make('siteplanlive_show_stock')->label('Tampilkan kotak Stock Status (Ready/Sold)'),
                    Forms\Components\Toggle::make('siteplanlive_sold_animation')->label('Animasi SOLD OUT otomatis (saat scope 100% sold)'),
                    Forms\Components\Select::make('siteplanlive_focus_lokasi_id')->label('Fokus Cluster (Lokasi)')
                        ->options(fn () => \App\Modules\FO\MarketingSales\Models\Lokasi::where('is_active', true)->orderBy('kode')->get()->mapWithKeys(fn ($l) => [$l->id => $l->kode.' — '.$l->nama]))
                        ->placeholder('Seluruh project')->searchable()->live()
                        ->afterStateUpdated(fn (callable $set) => $set('siteplanlive_focus_tahap', null))
                        ->helperText('Kosongkan = pantau seluruh project.'),
                    Forms\Components\Select::make('siteplanlive_focus_tahap')->label('Fokus Tahap')
                        ->options(function (Forms\Get $get) {
                            $lok = $get('siteplanlive_focus_lokasi_id');
                            return \App\Modules\FO\MarketingSales\Models\Tahap::where('is_active', true)
                                ->when($lok, fn ($q) => $q->where('lokasi_id', $lok))
                                ->orderBy('kode')->get()->mapWithKeys(fn ($t) => [$t->kode => $t->kode.' — '.($t->nama ?: $t->kode)]);
                        })
                        ->placeholder('Semua tahap')->native(false)
                        ->helperText('Diambil dari master Tahap pada cluster terpilih. Kosongkan = semua tahap.'),
                ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $s = app(FoSettings::class);

        foreach ($data as $key => $value) {
            $s->{$key} = $value;
        }
        $s->save();

        Notification::make()->title('Pengaturan disimpan')->success()->send();
    }
}
