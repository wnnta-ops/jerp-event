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
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Default nilai transaksi')->columns(3)->schema([
                Forms\Components\TextInput::make('default_booking_fee')->label('Booking fee default')->numeric()->prefix('Rp')->required(),
                Forms\Components\TextInput::make('default_tenor_bulan')->label('Tenor default (bulan)')->numeric()->required(),
                Forms\Components\TextInput::make('default_dp_persen')->label('DP default (%)')->numeric()->suffix('%')->required(),
                Forms\Components\TextInput::make('reservasi_expiry_days')->label('Expiry reservasi (hari)')->numeric()->required(),
                Forms\Components\TextInput::make('tts_auto_confirm_minutes')->label('TTS auto-confirm (menit, 0 = manual)')->numeric()->required(),
                Forms\Components\TextInput::make('komisi_pph_default_persen')->label('PPh komisi default (%)')->numeric()->suffix('%')->required(),
            ]),

            Forms\Components\Section::make('NUP Public')->columns(2)->schema([
                Forms\Components\Toggle::make('recaptcha_enabled')->label('Aktifkan reCAPTCHA v3'),
                Forms\Components\TextInput::make('recaptcha_min_score')->label('Skor minimum reCAPTCHA')->numeric()->step(0.01),
            ]),

            Forms\Components\Section::make('Notifikasi')->schema([
                Forms\Components\TextInput::make('notification_email')->label('Email notifikasi')->email()->required(),
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
