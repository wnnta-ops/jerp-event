<?php

namespace App\Filament\System\Resources;

use App\Filament\System\Resources\AuditLogResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components as Info;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Audit log';

    protected static ?string $pluralModelLabel = 'Audit Log';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Info\Section::make('Aktivitas')
                ->columns(2)
                ->schema([
                    Info\TextEntry::make('log_name')->label('Log'),
                    Info\TextEntry::make('event')->label('Event')->badge(),
                    Info\TextEntry::make('description')->label('Deskripsi')->columnSpanFull(),
                    Info\TextEntry::make('subject_type')->label('Subject type'),
                    Info\TextEntry::make('subject_id')->label('Subject ID'),
                    Info\TextEntry::make('causer.full_name')->label('Pelaku')->placeholder('System'),
                    Info\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                ]),
            Info\Section::make('Perubahan')
                ->schema([
                    // Paket activitylog versi ini menyimpan diff di kolom
                    // `attribute_changes` ({old, attributes}), bukan `properties`.
                    Info\KeyValueEntry::make('sesudah')
                        ->label('Setelah')
                        ->state(fn (Activity $record): array => static::changeSet($record, 'attributes')),
                    Info\KeyValueEntry::make('sebelum')
                        ->label('Sebelum')
                        ->state(fn (Activity $record): array => static::changeSet($record, 'old')),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('log_name')->label('Log')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('event')->label('Event')->badge()->color(fn (?string $state) => match ($state) {
                    'created' => 'success',
                    'updated' => 'info',
                    'deleted' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('description')->label('Deskripsi')->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('subject_id')->label('ID')->toggleable(),
                Tables\Columns\TextColumn::make('causer.full_name')->label('Pelaku')->placeholder('System')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->options(['created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted']),
                Tables\Filters\SelectFilter::make('log_name')
                    ->options(fn () => Activity::query()->distinct()->pluck('log_name', 'log_name')->filter()->all()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Ambil sisi perubahan ('attributes' = sesudah, 'old' = sebelum) dari kolom
     * attribute_changes, apa pun bentuk cast-nya (Collection/array/JSON string).
     *
     * @return array<string, mixed>
     */
    protected static function changeSet(Activity $record, string $side): array
    {
        $changes = $record->attribute_changes;

        if ($changes instanceof Collection) {
            $changes = $changes->all();
        } elseif (is_string($changes)) {
            $changes = json_decode($changes, true) ?: [];
        }

        return (array) data_get($changes, $side, []);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
