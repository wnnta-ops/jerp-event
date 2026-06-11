<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\PengalihanHakResource\Pages;
use App\Filament\Fo\Resources\PengalihanHakResource\RelationManagers\HistoryRelationManager;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\PengalihanHakService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengalihan Hak — daftar kontrak yang sudah SPR (final/terjual). Pilih kontrak →
 * lakukan Pengalihan Hak (pindah customer: suami→istri / orang lain, boleh daftar baru).
 * Riwayat pengalihan per-kontrak tampil saat dibuka (history).
 */
class PengalihanHakResource extends Resource
{
    protected static ?string $model = Kontrak::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?string $modelLabel = 'Pengalihan Hak';

    protected static ?string $pluralModelLabel = 'Pengalihan Hak';

    protected static ?string $slug = 'pengalihan-hak';

    protected static ?int $navigationSort = 45;

    /** Hanya kontrak SPR (final/terjual) yang muncul di sini. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tahap', 'SPR')->where('status', 'AKTIF');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kontrak (SPR)')->columns(2)->schema([
                Forms\Components\Placeholder::make('no_kontrak')->label('No. Kontrak')
                    ->content(fn (?Kontrak $record) => $record?->no_kontrak ?? '—'),
                Forms\Components\Placeholder::make('unit')->label('Unit')
                    ->content(fn (?Kontrak $record) => $record?->unit?->kode_unit ?? '—'),
                Forms\Components\Placeholder::make('customer')->label('Customer Sekarang')
                    ->content(fn (?Kontrak $record) => $record?->customer?->full_name ?? '—'),
                Forms\Components\Placeholder::make('sales')->label('Sales')
                    ->content(fn (?Kontrak $record) => $record?->agent?->name ?? '—'),
            ]),
        ]);
    }

    /** Form modal pengalihan (dipakai aksi di list & edit). */
    public static function pengalihanForm(): array
    {
        return [
            Forms\Components\Placeholder::make('cur')->label('Customer Sekarang')
                ->content(fn (Kontrak $record) => $record->customer?->full_name ?? '—'),
            Forms\Components\DatePicker::make('tgl_pengalihan')->label('Tanggal Pengalihan Hak')->default(now())->required(),
            Forms\Components\Select::make('customer_baru_id')->label('Customer Baru')->required()->searchable()
                ->options(fn () => Customer::query()->orderBy('full_name')->limit(500)->get()
                    ->mapWithKeys(fn ($c) => [$c->id => $c->full_name.($c->code ? ' ('.$c->code.')' : '')]))
                ->helperText('Tak ada di master? Klik tombol + untuk daftar customer baru.')
                ->createOptionForm([
                    Forms\Components\TextInput::make('full_name')->label('Nama Lengkap')->required(),
                    Forms\Components\TextInput::make('nik')->label('NIK')->maxLength(16),
                    Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(25),
                    Forms\Components\TextInput::make('mobile')->label('No. HP'),
                    Forms\Components\TextInput::make('email')->label('Email')->email(),
                    Forms\Components\Textarea::make('jalan')->label('Alamat')->rows(2)->columnSpanFull(),
                    Forms\Components\FileUpload::make('ktp_file_path')->label('Upload KTP')->directory('customers/ktp')
                        ->acceptedFileTypes(['application/pdf', 'image/*'])->maxSize(5120),
                    Forms\Components\FileUpload::make('npwp_file_path')->label('Upload NPWP')->directory('customers/npwp')
                        ->acceptedFileTypes(['application/pdf', 'image/*'])->maxSize(5120),
                ])
                ->createOptionUsing(fn (array $data) => Customer::create($data + [
                    'tenant_id' => 1, 'lead_status' => 'LEAD', 'is_active' => true,
                ])->getKey()),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2),
        ];
    }

    public static function runPengalihan(Kontrak $record, array $data): void
    {
        $ph = app(PengalihanHakService::class)->transfer($record, (int) $data['customer_baru_id'], [
            'tgl_pengalihan' => $data['tgl_pengalihan'] ?? null,
            'biaya_admin' => $data['biaya_admin'] ?? 0,
            'file_scan_path' => $data['file_scan_path'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
        ]);

        Notification::make()->success()->title('Pengalihan Hak selesai — '.$ph->no_pengalihan)
            ->body('Customer kontrak kini: '.$ph->customerBaru?->full_name)->persistent()->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('agent.name')->label('Sales')->placeholder('—'),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('pengalihan_hak_count')->counts('pengalihanHak')->label('Pengalihan')->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')->formatStateUsing(fn ($state) => $state.'×'),
            ])
            ->actions([
                Tables\Actions\Action::make('pengalihan')->label('Pengalihan Hak')->icon('heroicon-o-identification')->color('primary')
                    ->modalHeading('Pengalihan Hak')->modalWidth('2xl')->modalSubmitActionLabel('Proses Pengalihan')
                    ->form(self::pengalihanForm())
                    ->action(fn (Kontrak $record, array $data) => self::runPengalihan($record, $data)),
                Tables\Actions\EditAction::make()->label('Riwayat'),
            ])
            ->defaultSort('tgl_kontrak', 'desc');
    }

    public static function getRelations(): array
    {
        return [HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPengalihanHaks::route('/'),
            'edit' => Pages\EditPengalihanHak::route('/{record}'),
        ];
    }
}
