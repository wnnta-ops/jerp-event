<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\NupEventResource\Pages;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NupEventResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = NupEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Event Launching';

    protected static ?string $pluralModelLabel = 'Event Launching (NUP)';

    /**
     * Sertakan event yang sudah dihapus supaya bisa dilihat & dipulihkan.
     *
     * NupEvent mewarisi SoftDeletes dari TenantAwareModel: baris yang dihapus tetap
     * ada di database dan MASIH menempati unique(tenant_id, code). Tanpa ini, event
     * terhapus tak terlihat di mana pun namun kodenya tak bisa dipakai lagi —
     * "sudah dihapus tapi kodenya masih dianggap terpakai" tanpa penjelasan.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Event')->columns(2)->schema([
                // Pesan unik menyebut kemungkinan bentrok dengan event TERHAPUS: aturan
                // unique menanyai tabel apa adanya, sehingga baris ber-deleted_at pun
                // tetap dianggap memakai kode itu.
                Forms\Components\TextInput::make('code')->label('Kode event')->required()->maxLength(32)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'Kode ini sudah dipakai event lain — mungkin event yang sudah dihapus. Buka filter "Data terhapus" untuk memulihkannya, atau pakai kode berbeda.',
                    ]),
                Forms\Components\TextInput::make('name')->label('Nama event')->required()->maxLength(255),
                Forms\Components\Select::make('project_id')->label('Proyek')->relationship('project', 'name')->required()->searchable()->preload()
                    ->live()->afterStateUpdated(fn (callable $set) => $set('lokasi_id', null)),
                // Pilih Unit menolak scan NUP event ini bila cluster yang dibuka berbeda.
                Forms\Components\Select::make('lokasi_id')->label('Cluster')
                    ->options(fn (Get $get) => $get('project_id')
                        ? Lokasi::query()->where('project_id', $get('project_id'))->where('is_active', true)
                            ->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->preload()
                    ->placeholder('— Semua cluster')
                    ->helperText('NUP event ini hanya bisa Pilih Unit di cluster ini. Kosong → semua cluster.'),
                Forms\Components\DatePicker::make('tgl_launching')->label('Tgl launching')->required(),
                Forms\Components\DateTimePicker::make('open_registration_at')->label('Buka pendaftaran'),
                Forms\Components\DateTimePicker::make('close_registration_at')->label('Tutup pendaftaran'),
                Forms\Components\TextInput::make('kuota')->label('Kuota')->numeric()->default(0),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
            Tables\Columns\TextColumn::make('project.name')->label('Proyek')->toggleable(),
            Tables\Columns\TextColumn::make('lokasi.nama')->label('Cluster')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('tgl_launching')->label('Tgl launching')->date('d M Y')->sortable(),
            Tables\Columns\TextColumn::make('kuota')->label('Kuota')->numeric()->alignEnd(),
            Tables\Columns\TextColumn::make('registrations_count')->counts('registrations')->label('Pendaftar')->alignEnd(),
            Tables\Columns\TextColumn::make('effective_status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                'DRAFT' => 'gray', 'OPEN' => 'success', 'CLOSED' => 'warning',
                'EXECUTED' => 'primary', 'BATAL' => 'danger', default => 'gray',
            }),
        ])->filters([
            // Bawaannya menyembunyikan yang terhapus; pilih "Terhapus" untuk melihat &
            // memulihkannya — termasuk event yang kodenya masih menyandera kode baru.
            Tables\Filters\TrashedFilter::make()->label('Data terhapus'),
            Tables\Filters\SelectFilter::make('status')->label('Status')->options([
                'OPEN' => 'Buka pendaftaran', 'CLOSED' => 'Tutup pendaftaran', 'DRAFT' => 'Belum buka',
            ])->query(function ($query, array $data) {
                $value = $data['value'] ?? null;
                $now = now();
                if ($value === 'OPEN') {
                    return $query->openNow();
                }
                if ($value === 'CLOSED') {
                    return $query->whereNotIn('status', ['EXECUTED', 'BATAL'])
                        ->whereNotNull('close_registration_at')
                        ->where('close_registration_at', '<', $now);
                }
                if ($value === 'DRAFT') {
                    return $query->whereNotIn('status', ['EXECUTED', 'BATAL'])
                        ->where(fn ($w) => $w->whereNull('open_registration_at')->orWhere('open_registration_at', '>', $now));
                }

                return $query;
            }),
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Proyek'),
        ])->actions([
            Tables\Actions\EditAction::make(),

            // HAPUS PERMANEN, dijaga oleh keterikatan data.
            //
            // Soft delete di sini menyesatkan: baris terhapus tetap menempati
            // unique(tenant_id, code), sehingga event "sudah dihapus" tapi kodenya tak
            // bisa dipakai lagi — tanpa penjelasan apa pun.
            //
            // Hapus permanen TIDAK boleh membabi buta: fo_nup_registrations memakai
            // cascadeOnDelete, jadi tanpa penjagaan ini menghapus satu event akan ikut
            // memusnahkan SELURUH pendaftaran NUP di bawahnya beserta nomor urut yang
            // sudah terbit. Karena itu event yang masih punya pendaftar DITOLAK.
            Tables\Actions\DeleteAction::make()
                ->modalHeading('Hapus event ini?')
                ->modalDescription('Event dihapus permanen. Hanya bisa dilakukan bila belum ada pendaftaran NUP pada event ini.')
                ->action(function (NupEvent $record) {
                    $jml = $record->registrations()->withoutGlobalScopes()->count();

                    if ($jml > 0) {
                        \Filament\Notifications\Notification::make()
                            ->title('Tidak bisa dihapus')
                            ->body("Event ini sudah punya {$jml} pendaftaran NUP. Hapus atau pindahkan pendaftarannya lebih dulu.")
                            ->danger()->persistent()->send();

                        return;
                    }

                    $record->forceDelete();

                    \Filament\Notifications\Notification::make()
                        ->title('Event dihapus')
                        ->body('Kode event kini bebas dipakai lagi.')
                        ->success()->send();
                }),
        ])->bulkActions([
            // Samakan perilakunya dengan hapus satuan. Bila dibiarkan soft delete,
            // hapus massal akan menyisakan baris yang menyandera kode event lagi.
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()
                    ->modalDescription('Event dihapus permanen. Event yang sudah punya pendaftaran NUP akan dilewati.')
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $hapus = 0;
                        $lewat = [];

                        foreach ($records as $record) {
                            if ($record->registrations()->withoutGlobalScopes()->exists()) {
                                $lewat[] = $record->code;

                                continue;
                            }
                            $record->forceDelete();
                            $hapus++;
                        }

                        \Filament\Notifications\Notification::make()
                            ->title($hapus.' event dihapus')
                            ->body($lewat === [] ? 'Kode event kini bebas dipakai lagi.'
                                : 'Dilewati karena sudah punya pendaftaran: '.implode(', ', $lewat))
                            ->{$lewat === [] ? 'success' : 'warning'}()->persistent()->send();
                    }),
            ]),
        ])->defaultSort('tgl_launching', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNupEvents::route('/'),
            'create' => Pages\CreateNupEvent::route('/create'),
            'edit' => Pages\EditNupEvent::route('/{record}/edit'),
        ];
    }
}
