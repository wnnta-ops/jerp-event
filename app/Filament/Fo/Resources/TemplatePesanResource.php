<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\TemplatePesanResource\Pages;
use App\Modules\FO\Komunikasi\Models\TemplatePesan;
use App\Modules\FO\Komunikasi\Models\WaTemplate;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Pengaturan → Template Pesan: teks email + pilihan template WA per jenis pesan.
 * Cluster kosong = berlaku untuk semua cluster; template khusus cluster mengalahkannya.
 */
class TemplatePesanResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = TemplatePesan::class;

    protected static ?string $slug = 'template-pesan';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Template Pesan';

    protected static ?string $pluralModelLabel = 'Template Pesan';

    /** Batas jumlah isian {{n}} yang disediakan form. */
    private const MAKS_PARAM = 12;

    public static function form(Form $form): Form
    {
        $isianParam = [];
        for ($i = 0; $i < self::MAKS_PARAM; $i++) {
            $isianParam[] = Forms\Components\Select::make('wa_params.'.$i)
                ->label('{{'.($i + 1).'}}')
                ->options(fn (Forms\Get $get) => self::opsiKunci($get('jenis')))
                ->required()
                ->visible(fn (Forms\Get $get) => $i < self::jumlahParam($get('wa_template_id')));
        }

        return $form->schema([
            Forms\Components\Section::make('Berlaku untuk')->columns(3)->schema([
                Forms\Components\Select::make('jenis')->label('Jenis pesan')->options(JenisPesan::pilihan())
                    ->required()->live()->disabledOn('edit')->dehydrated()
                    // Aturan di sini (bukan di Cluster): kolom kosong tidak divalidasi Laravel.
                    ->rule(fn (Forms\Get $get, ?TemplatePesan $record) => self::aturanUnik($get('lokasi_id'), $record)),
                Forms\Components\Select::make('lokasi_id')->label('Cluster')
                    ->options(fn () => Lokasi::query()->orderBy('nama')->pluck('nama', 'id'))
                    ->placeholder('Semua cluster')->searchable()->disabledOn('edit')->dehydrated()
                    ->helperText('Kosongkan = semua cluster. Template khusus cluster dipakai lebih dulu.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),

            Forms\Components\Section::make('Kata kunci')->collapsible()->schema([
                Forms\Components\Placeholder::make('daftar_kunci')->hiddenLabel()
                    ->content(fn (Forms\Get $get) => self::daftarKunciHtml($get('jenis'))),
            ])->visible(fn (Forms\Get $get) => filled($get('jenis'))),

            Forms\Components\Section::make('Email')->schema([
                Forms\Components\Toggle::make('kirim_email')->label('Kirim email')->default(true)->live(),
                Forms\Components\TextInput::make('email_subjek')->label('Subjek')->maxLength(250)
                    ->placeholder('mis. Kwitansi Pembayaran {jenis_pembayaran} — {no_kontrak}')
                    ->visible(fn (Forms\Get $get) => $get('kirim_email')),
                Forms\Components\RichEditor::make('email_isi')->label('Isi email')
                    ->toolbarButtons(['bold', 'italic', 'underline', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                    ->helperText('Kosongkan untuk memakai tampilan email bawaan aplikasi.')
                    ->visible(fn (Forms\Get $get) => $get('kirim_email')),
                Forms\Components\Toggle::make('email_lampiran')->label('Lampirkan PDF (SPRS / kwitansi)')->default(true)
                    ->visible(fn (Forms\Get $get) => $get('kirim_email') && $get('jenis') !== JenisPesan::TAGIHAN_UTJ),
            ]),

            Forms\Components\Section::make('WhatsApp')->schema([
                Forms\Components\Toggle::make('kirim_wa')->label('Kirim WhatsApp')->default(true)->live(),
                Forms\Components\Select::make('wa_template_id')->label('Template WA (Qontak)')
                    ->options(fn () => WaTemplate::query()->where('is_active', true)->orderBy('nama')
                        ->get()->mapWithKeys(fn ($w) => [$w->id => $w->nama.' · '.$w->jumlah_param.' param'.($w->pakai_dokumen ? ' · PDF' : '')]))
                    ->searchable()->live()
                    ->helperText('Daftar dari Pengaturan → Template WA. Isi setiap {{n}} di bawah sesuai urutan di Qontak.')
                    ->visible(fn (Forms\Get $get) => $get('kirim_wa')),
                Forms\Components\Placeholder::make('contoh_wa')->label('Isi template di Qontak')
                    ->content(fn (Forms\Get $get) => new HtmlString('<div style="white-space:pre-wrap;font-size:12px">'
                        .e((string) WaTemplate::find($get('wa_template_id'))?->contoh_isi ?: '—').'</div>'))
                    ->visible(fn (Forms\Get $get) => $get('kirim_wa') && $get('wa_template_id')),
                Forms\Components\Grid::make(3)->schema($isianParam)
                    ->visible(fn (Forms\Get $get) => $get('kirim_wa') && $get('wa_template_id')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lokasi', 'waTemplate']))
            ->columns([
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->formatStateUsing(fn ($state) => JenisPesan::label($state))->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama')->label('Cluster')->placeholder('Semua cluster')->badge(),
                Tables\Columns\IconColumn::make('kirim_email')->label('Email')->boolean(),
                Tables\Columns\TextColumn::make('email_subjek')->label('Subjek')->limit(40)->placeholder('(bawaan)'),
                Tables\Columns\IconColumn::make('kirim_wa')->label('WA')->boolean(),
                Tables\Columns\TextColumn::make('waTemplate.nama')->label('Template WA')->placeholder('(ID .env)'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('Diubah')->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options(JenisPesan::pilihan()),
                Tables\Filters\SelectFilter::make('lokasi_id')->label('Cluster')->relationship('lokasi', 'nama'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('jenis');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTemplatePesan::route('/'),
            'create' => Pages\CreateTemplatePesan::route('/create'),
            'edit' => Pages\EditTemplatePesan::route('/{record}/edit'),
        ];
    }

    /** Isian {{1}}, {{2}}, … → daftar berurutan (form menyimpannya sebagai kunci '0','1',…). */
    public static function urutkanParam($params): ?array
    {
        if (! is_array($params) || $params === []) {
            return null;
        }
        ksort($params, SORT_NUMERIC);

        return array_values($params);
    }

    /** @return array<string,string> */
    private static function opsiKunci(?string $jenis): array
    {
        if (! $jenis) {
            return [];
        }

        return collect(JenisPesan::kataKunci($jenis))->mapWithKeys(fn ($l, $k) => [$k => '{'.$k.'} — '.$l])->all();
    }

    private static function jumlahParam($waTemplateId): int
    {
        return $waTemplateId ? min(self::MAKS_PARAM, (int) WaTemplate::find($waTemplateId)?->jumlah_param) : 0;
    }

    private static function daftarKunciHtml(?string $jenis): HtmlString
    {
        $baris = collect($jenis ? JenisPesan::kataKunci($jenis) : [])
            ->map(fn ($l, $k) => '<code style="font-size:12px">{'.e($k).'}</code> '.e($l))->implode('<br>');

        return new HtmlString('<div style="font-size:13px;line-height:1.8">'.$baris.'</div>');
    }

    /** Satu template per jenis + cluster (soft-deleted diabaikan). */
    private static function aturanUnik($lokasiId, ?TemplatePesan $record): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($lokasiId, $record) {
            $ada = TemplatePesan::query()->where('jenis', $value)
                ->when($lokasiId, fn ($q) => $q->where('lokasi_id', $lokasiId), fn ($q) => $q->whereNull('lokasi_id'))
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
            if ($ada) {
                $fail('Template untuk jenis & cluster ini sudah ada — ubah yang sudah ada.');
            }
        };
    }
}
