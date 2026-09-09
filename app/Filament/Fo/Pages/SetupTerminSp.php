<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Settings\CollectionSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Setup Termin SP — atur kapan reminder dikirim (H- sebelum jatuh tempo) dan
 * ambang keterlambatan yang memicu SP I/II/III serta rekomendasi pembatalan.
 */
class SetupTerminSp extends Page implements HasForms
{
    use MematuhiIzinHalaman;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Setup Termin SP';

    protected static ?string $navigationLabel = 'Setup Termin SP';

    protected static string $view = 'filament.fo.pages.setup-termin-sp';

    protected static ?string $slug = 'collection-termin-sp';

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(CollectionSettings::class);
        $this->form->fill([
            'reminder_days_before' => $s->reminder_days_before,
            'sp1_overdue_days' => $s->sp1_overdue_days,
            'sp2_overdue_days' => $s->sp2_overdue_days,
            'sp3_overdue_days' => $s->sp3_overdue_days,
            'pembatalan_overdue_days' => $s->pembatalan_overdue_days,
            'channel_email' => $s->channel_email,
            'channel_wa' => $s->channel_wa,
            'auto_kirim_jam' => $s->auto_kirim_jam,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Reminder pra-jatuh-tempo')->schema([
                Forms\Components\TextInput::make('reminder_days_before')
                    ->label('Kirim reminder berapa hari sebelum jatuh tempo (H-)')
                    ->numeric()->minValue(0)->required()
                    ->helperText('Contoh: 3 = reminder dikirim 3 hari sebelum tanggal jatuh tempo DP/angsuran.'),
            ]),

            Forms\Components\Section::make('Ambang keterlambatan (hari telat ≥)')->columns(2)->schema([
                Forms\Components\TextInput::make('sp1_overdue_days')->label('Surat Peringatan I')->numeric()->minValue(0)->required(),
                Forms\Components\TextInput::make('sp2_overdue_days')->label('Surat Peringatan II')->numeric()->minValue(0)->required(),
                Forms\Components\TextInput::make('sp3_overdue_days')->label('Surat Peringatan III')->numeric()->minValue(0)->required(),
                Forms\Components\TextInput::make('pembatalan_overdue_days')->label('Rekomendasi Pembatalan')->numeric()->minValue(0)->required()
                    ->helperText('Pembatalan TIDAK otomatis — hanya ditandai. Proses batal lewat menu Pembatalan Kontrak (approval).'),
            ]),

            Forms\Components\Section::make('Channel pengiriman')
                ->description('Mengatur DUA hal sekaligus: (1) channel pengiriman OTOMATIS harian pada jam terpilih, dan (2) centangan awal pada modal tombol Kirim manual di halaman Collection. Keduanya MATI = TIDAK ADA pengiriman otomatis sama sekali (default) — kirim hanya manual.')
                ->columns(2)->schema([
                    Forms\Components\Toggle::make('channel_email')->label('Kirim via Email'),
                    Forms\Components\Toggle::make('channel_wa')->label('Kirim via WhatsApp'),
                    Forms\Components\TimePicker::make('auto_kirim_jam')->label('Jam kirim otomatis')
                        ->seconds(false)->required()
                        ->helperText('Pengiriman otomatis berjalan sekali sehari pada jam ini (bila ada channel yang nyala). Perubahan jam berlaku otomatis, tanpa restart.'),
                ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Simpan')->submit('save')];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        // TimePicker bisa mengembalikan HH:MM:SS — simpan HH:MM (format dailyAt).
        $data['auto_kirim_jam'] = substr((string) ($data['auto_kirim_jam'] ?? '08:00'), 0, 5);
        $s = app(CollectionSettings::class);
        foreach ($data as $key => $value) {
            $s->{$key} = $value;
        }
        $s->save();

        Notification::make()->title('Termin SP disimpan')->success()->send();
    }
}
