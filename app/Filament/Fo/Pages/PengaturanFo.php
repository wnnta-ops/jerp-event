<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
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
    use MematuhiIzinHalaman;

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
            'blt_batal_sepihak_nilai' => $s->blt_batal_sepihak_nilai,
            'blt_batal_sepihak_pph_persen' => $s->blt_batal_sepihak_pph_persen,
            'ttd_blt_diajukan' => $s->ttd_blt_diajukan,
            'ttd_blt_diperiksa' => $s->ttd_blt_diperiksa,
            'ttd_blt_diketahui' => $s->ttd_blt_diketahui,
            'ttd_blt_disetujui' => $s->ttd_blt_disetujui,
            'ttd_komisi_diajukan' => $s->ttd_komisi_diajukan,
            'ttd_komisi_diperiksa' => $s->ttd_komisi_diperiksa,
            'ttd_komisi_diketahui' => $s->ttd_komisi_diketahui,
            'ttd_komisi_disetujui' => $s->ttd_komisi_disetujui,
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

            Forms\Components\Section::make('BLT Batal Sepihak')
                ->description('BLT untuk sales ketika kontrak dibatalkan sepihak oleh pembeli. Nilainya tetap — bukan turunan nilai kontrak, justru karena kontraknya tidak jadi. Batal karena bank menolak atau kolektibilitas TIDAK menerbitkan BLT.')
                ->columns(2)->schema([
                    Forms\Components\TextInput::make('blt_batal_sepihak_nilai')->label('Nilai BLT')->numeric()->rupiah()->required()->minValue(0),
                    Forms\Components\TextInput::make('blt_batal_sepihak_pph_persen')->label('PPh 21')->numeric()->suffix('%')->required()->minValue(0)->maxValue(100)
                        ->helperText('BLT NETT = Nilai BLT − PPh, dibulatkan ke atas.'),
                ]),

            ...collect(['blt' => 'BLT', 'komisi' => 'Komisi'])->map(fn ($judul, $jenis) => Forms\Components\Section::make('Tanda Tangan Pengajuan '.$judul)
                ->description('Nama yang tercetak di bawah tanda tangan pada cetak Pengajuan '.$judul.'. Lebih dari satu orang dipisah koma (mis. "Rizki Setyawardana, Rachmat Suhendra"). Kosongkan bila ingin ditulis tangan.')
                ->columns(4)->schema([
                    Forms\Components\TextInput::make("ttd_{$jenis}_diajukan")->label('Diajukan Oleh')->maxLength(200),
                    Forms\Components\TextInput::make("ttd_{$jenis}_diperiksa")->label('Diperiksa Oleh')->maxLength(200),
                    Forms\Components\TextInput::make("ttd_{$jenis}_diketahui")->label('Diketahui Oleh')->maxLength(200),
                    Forms\Components\TextInput::make("ttd_{$jenis}_disetujui")->label('Disetujui Oleh')->maxLength(200),
                ]))->values()->all(),

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
                Forms\Components\TextInput::make('nup_public_maintenance_bypass')
                    ->label('Kode akses tim (untuk menguji saat pemeliharaan)')
                    ->helperText('Buka formulir dengan ?kode=ISI-INI. Kosongkan berarti tak ada yang bisa melewati.')
                    ->maxLength(64)
                    ->visible(fn (Forms\Get $get) => (bool) $get('nup_public_maintenance'))
                    ->columnSpanFull(),
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
                        ->options(fn () => Lokasi::where('is_active', true)->orderBy('kode')->get()->mapWithKeys(fn ($l) => [$l->id => $l->kode.' — '.$l->nama]))
                        ->placeholder('Seluruh project')->searchable()->live()
                        ->afterStateUpdated(fn (callable $set) => $set('siteplanlive_focus_tahap', null))
                        ->helperText('Kosongkan = pantau seluruh project.'),
                    Forms\Components\Select::make('siteplanlive_focus_tahap')->label('Fokus Tahap')
                        ->options(function (Forms\Get $get) {
                            $lok = $get('siteplanlive_focus_lokasi_id');

                            return Tahap::where('is_active', true)
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
