<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\WaTemplateResource\Pages;
use App\Modules\FO\Komunikasi\Models\WaTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengaturan → Template WA (Qontak). Kalimat template dibuat & disetujui di dashboard
 * Qontak; di sini cukup tempel ID-nya agar bisa dipilih di Template Pesan.
 */
class WaTemplateResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = WaTemplate::class;

    protected static ?string $slug = 'template-wa';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Template WA';

    protected static ?string $pluralModelLabel = 'Template WA (Qontak)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(120)
                ->placeholder('mis. Kwitansi Pembayaran v2'),
            Forms\Components\TextInput::make('template_id')->label('ID Template Qontak')->required()->maxLength(120)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at'))
                ->placeholder('tempel dari Qontak → Broadcast → Template')
                ->helperText('Template harus berstatus APPROVED di Qontak.'),
            Forms\Components\Textarea::make('contoh_isi')->label('Isi template (salin dari Qontak)')->rows(5)->columnSpanFull()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('jumlah_param', WaTemplate::hitungParam($state)))
                ->helperText('Untuk referensi saja. Jumlah parameter dihitung otomatis dari {{1}}, {{2}}, …'),
            Forms\Components\TextInput::make('jumlah_param')->label('Jumlah parameter')->numeric()->minValue(0)->maxValue(20)->default(0)->required()
                ->helperText('Harus sama persis dengan template Qontak — beda satu saja, Qontak menolak pesan (422).'),
            Forms\Components\Toggle::make('pakai_dokumen')->label('Header berupa dokumen (PDF)')->inline(false)
                ->helperText('Aktifkan bila template dibuat dengan Header: Document (mis. lampiran SPRS / kwitansi).'),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('dipakaiDi'))
            ->columns([
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('template_id')->label('ID Template')->copyable()->fontFamily('mono')->size('xs')->searchable(),
                Tables\Columns\TextColumn::make('jumlah_param')->label('Param')->alignCenter(),
                Tables\Columns\IconColumn::make('pakai_dokumen')->label('PDF')->boolean(),
                Tables\Columns\TextColumn::make('dipakai_di_count')->label('Dipakai')->suffix(' template')->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('Diubah')->since()->sortable(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('is_active')->label('Aktif')])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // Yang masih dipilih di Template Pesan tak boleh dihapus — kiriman jenis itu
                    // akan diam-diam jatuh ke ID cadangan .env. Nonaktifkan/ganti dulu.
                    ->before(function (WaTemplate $record, Tables\Actions\DeleteAction $action) {
                        if ($record->dipakaiDi()->exists()) {
                            Notification::make()->danger()->title('Masih dipakai di Template Pesan')
                                ->body('Ganti template WA di Template Pesan yang memakainya lebih dulu.')->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('nama');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWaTemplates::route('/'),
        ];
    }
}
