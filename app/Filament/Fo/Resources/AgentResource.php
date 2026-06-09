<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\AgentResource\Pages;
use App\Modules\FO\Komisi\Models\Agent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Sales';

    protected static ?string $pluralModelLabel = 'Pendaftaran Sales';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')->columns(3)->schema([
                Forms\Components\TextInput::make('code')
                    ->label('No. Sales')->maxLength(32)->required()->unique(ignoreRecord: true)
                    ->placeholder('mis. 00193')
                    ->helperText('Nomor unik per sales (5 digit, zero-padded).'),
                Forms\Components\TextInput::make('inisial')
                    ->label('Inisial')->maxLength(16)
                    ->placeholder('mis. TS')
                    ->helperText('Inisial pendek untuk print/voucher.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\TextInput::make('name')
                    ->label('Nama Lengkap')->required()->maxLength(255)
                    ->columnSpan(2),
                Forms\Components\TextInput::make('agency_name')
                    ->label('Agensi Properti')->maxLength(191)
                    ->placeholder('mis. Uhome / DB Real Estate')
                    ->helperText('Tampil sbg "Nama - Agensi" di SPRS/SPR. Kosongkan utk sales internal.'),
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()->preload(),
            ]),

            Forms\Components\Section::make('Tipe & Level')->columns(3)->schema([
                Forms\Components\Select::make('jenis')
                    ->label('Jenis Agent')
                    ->options(['INTERNAL' => 'Internal', 'EXTERNAL' => 'External', 'KORPORASI' => 'Korporasi'])
                    ->default('EXTERNAL')->required(),
                Forms\Components\Select::make('sales_type_id')
                    ->label('Tipe Sales')
                    ->relationship('salesType', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable()->preload(),
                Forms\Components\Toggle::make('is_arebi')->label('AREBI')->inline(false),
                Forms\Components\Select::make('sales_level_id')
                    ->label('Level Sales')
                    ->relationship('salesLevel', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable()->preload(),
                Forms\Components\Select::make('atasan_id')
                    ->label('Atasan (langsung)')
                    ->relationship('atasan', 'name', fn ($query, $record) => $record
                        ? $query->where('id', '!=', $record->id)
                        : $query)
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable()->preload()
                    ->placeholder('— Tidak ada atasan —'),
                Forms\Components\TextInput::make('komisi_persen_default')
                    ->label('Komisi default (%)')->numeric()->suffix('%')->default(0),
            ]),

            Forms\Components\Section::make('Kontak')->columns(3)->schema([
                Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                Forms\Components\TextInput::make('phone')->label('Telepon')->tel()->maxLength(32),
                Forms\Components\TextInput::make('whatsapp')->label('WhatsApp')->tel()->maxLength(32),
                Forms\Components\Textarea::make('alamat')->label('Alamat')->rows(2)->columnSpanFull(),
            ])->collapsible(),

            Forms\Components\Section::make('Pajak & Rekening')->columns(3)->schema([
                Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(32),
                Forms\Components\TextInput::make('rekening_bank')->label('Bank')->maxLength(32),
                Forms\Components\TextInput::make('rekening_number')->label('No Rekening')->maxLength(64),
                Forms\Components\TextInput::make('rekening_holder')->label('Atas Nama Rekening')->maxLength(255)->columnSpan(2),
            ])->collapsible(),

            Forms\Components\Section::make('Catatan')->schema([
                Forms\Components\Textarea::make('keterangan')->rows(2),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('No. Sales')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('inisial')->label('Inisial')->badge()->color('gray')->toggleable(),
            Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('salesType.name')->label('Tipe')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('salesLevel.name')->label('Level')->badge()->toggleable()->wrap(),
            Tables\Columns\TextColumn::make('atasan.name')->label('Atasan')->placeholder('—')->toggleable(),
            Tables\Columns\TextColumn::make('whatsapp')->label('WA')->toggleable(),
            Tables\Columns\TextColumn::make('komisi_persen_default')->label('Komisi %')->numeric(2)->suffix('%')->alignEnd()->toggleable(),
            Tables\Columns\IconColumn::make('is_arebi')->label('AREBI')->boolean()->toggleable(),
            Tables\Columns\TextColumn::make('komisi_count')->counts('komisi')->label('# Komisi')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('sales_type_id')->relationship('salesType', 'name')->label('Tipe Sales'),
            Tables\Filters\SelectFilter::make('sales_level_id')->relationship('salesLevel', 'name')->label('Level Sales'),
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
            Tables\Filters\TernaryFilter::make('is_arebi')->label('AREBI'),
            Tables\Filters\TernaryFilter::make('is_active'),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgents::route('/'),
            'create' => Pages\CreateAgent::route('/create'),
            'edit' => Pages\EditAgent::route('/{record}/edit'),
        ];
    }
}
