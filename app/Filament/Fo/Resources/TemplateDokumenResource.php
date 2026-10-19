<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\TemplateDokumenResource\Pages;
use App\Modules\FO\Komunikasi\Models\TemplateDokumen;
use App\Modules\FO\Komunikasi\Support\DokumenBawaan;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Pengaturan → Template Dokumen: teks SPR & SPRS ber-versi, per cluster atau semua cluster.
 *
 * Versi yang sudah berlaku TIDAK bisa diubah/dihapus: kontrak dicetak dengan versi yang
 * berlaku pada tanggal kontraknya, jadi mengubahnya berarti mengubah surat yang sudah
 * ditandatangani customer. Perubahan dibuat sebagai versi baru dengan tanggal berlaku.
 */
class TemplateDokumenResource extends Resource
{
    use MematuhiIzin {
        canEdit as protected bolehUbah;
        canDelete as protected bolehHapus;
    }

    protected static ?string $model = TemplateDokumen::class;

    protected static ?string $slug = 'template-dokumen';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Versi Template Dokumen';

    protected static ?string $pluralModelLabel = 'Template Dokumen SPR / SPRS';

    public static function canEdit(mixed $record): bool
    {
        return ! $record->sudahBerlaku() && static::bolehUbah($record);
    }

    public static function canDelete(mixed $record): bool
    {
        return ! $record->sudahBerlaku() && static::bolehHapus($record);
    }

    public static function form(Form $form): Form
    {
        $isSpr = fn (Forms\Get $get) => $get('dokumen') === DokumenBawaan::SPR;
        $isSprs = fn (Forms\Get $get) => $get('dokumen') === DokumenBawaan::SPRS;
        $isiAwal = fn (Forms\Get $get, Forms\Set $set) => $get('dokumen')
            ? $set('isi', self::untukForm(self::isiAwal($get('dokumen'), $get('lokasi_id'))))
            : null;

        return $form->schema([
            Forms\Components\Section::make('Versi')->columns(3)->schema([
                Forms\Components\Select::make('dokumen')->label('Dokumen')->options(DokumenBawaan::pilihan())
                    ->required()->live()->disabledOn('edit')->dehydrated()
                    ->afterStateUpdated($isiAwal),
                Forms\Components\Select::make('lokasi_id')->label('Cluster')
                    ->options(fn () => Lokasi::query()->orderBy('nama')->pluck('nama', 'id'))
                    ->placeholder('Semua cluster')->searchable()->live()->disabledOn('edit')->dehydrated()
                    ->afterStateUpdated($isiAwal)
                    ->helperText('Kosongkan = semua cluster. Versi khusus cluster dipakai lebih dulu.'),
                Forms\Components\DatePicker::make('berlaku_mulai')->label('Berlaku mulai')->required()
                    ->native(false)->displayFormat('d M Y')->minDate(today())->default(today()->addDay())
                    ->rule('after_or_equal:today')->validationMessages(['after_or_equal' => 'Tanggal berlaku tidak boleh mundur — versi baru hanya berlaku ke depan.'])
                    ->helperText('Kontrak bertanggal sejak hari ini memakai teks versi ini. Setelah berlaku, versi terkunci.'),
                Forms\Components\Textarea::make('catatan')->label('Catatan perubahan')->rows(2)->columnSpanFull()
                    ->placeholder('mis. Pasal VIII denda diubah jadi 1,5‰ — memo direksi 12/10/2026'),
            ]),

            Forms\Components\Section::make('Kata kunci')->collapsible()->collapsed()->schema([
                Forms\Components\Placeholder::make('kunci')->hiddenLabel()->content(new HtmlString(
                    collect(DokumenBawaan::kataKunci())->map(fn ($l, $k) => '<code style="font-size:12px">{'.$k.'}</code> '.e($l))->implode('<br>')
                    .'<br><span style="font-size:12px;color:#6b7280">Teks boleh memakai &lt;b&gt;, &lt;i&gt;, &lt;u&gt;, &lt;br&gt;. Tag lain dibuang saat dicetak.</span>'
                )),
            ]),

            // ── SPR ──
            Forms\Components\Section::make('Kepala & pembuka SPR')->visible($isSpr)->columns(2)->schema([
                Forms\Components\TextInput::make('isi.nama_perusahaan')->label('Nama perusahaan')->required($isSpr),
                Forms\Components\TextInput::make('isi.paraf_perusahaan')->label('Label kotak paraf')->required($isSpr),
                Forms\Components\Textarea::make('isi.kalimat_pembuka')->label('Kalimat pembuka (sebelum data unit)')->rows(3)->columnSpanFull(),
                Forms\Components\Textarea::make('isi.kalimat_syarat')->label('Kalimat sebelum syarat')->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('isi.judul_pasal_bayar')->label('Judul pasal I (jadwal bayar)')->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Pasal-pasal SPR')->visible($isSpr)
                ->description('Urutan bisa digeser. "Mulai halaman baru" = pasal ini membuka halaman berikutnya.')
                ->schema([
                    Forms\Components\Repeater::make('isi.pasal')->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('judul')->label('Judul / nomor pasal')->required(),
                            Forms\Components\Textarea::make('isi')->label('Isi (paragraf)')->rows(3),
                            Forms\Components\Repeater::make('butir')->label('Butir a, b, c …')
                                ->simple(Forms\Components\Textarea::make('teks')->rows(2)->required())
                                ->defaultItems(0)->addActionLabel('Tambah butir')->reorderable(),
                            Forms\Components\Toggle::make('halaman_baru')->label('Mulai halaman baru'),
                        ])
                        ->itemLabel(fn (array $state) => $state['judul'] ?? null)
                        ->collapsible()->collapsed()->reorderable()->cloneable()
                        ->addActionLabel('Tambah pasal'),
                ]),
            Forms\Components\Section::make('Penutup SPR')->visible($isSpr)->columns(3)->schema([
                Forms\Components\Textarea::make('isi.kalimat_penutup')->label('Kalimat penutup')->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('isi.kota')->label('Kota')->required($isSpr),
                Forms\Components\TextInput::make('isi.penandatangan_nama')->label('Penandatangan')->required($isSpr),
                Forms\Components\TextInput::make('isi.penandatangan_jabatan')->label('Jabatan')->required($isSpr),
            ]),

            // ── SPRS ──
            Forms\Components\Section::make('SPRS')->visible($isSprs)->columns(2)->schema([
                Forms\Components\TextInput::make('isi.nama_perusahaan')->label('Nama perusahaan')->required($isSprs),
                Forms\Components\TextInput::make('isi.judul')->label('Judul formulir')->required($isSprs),
                Forms\Components\Repeater::make('isi.syarat')->label('Syarat dan ketentuan')->columnSpanFull()
                    ->simple(Forms\Components\Textarea::make('teks')->rows(2)->required())
                    ->reorderable()->addActionLabel('Tambah syarat'),
                Forms\Components\TextInput::make('isi.kota')->label('Kota')->required($isSprs),
                Forms\Components\TextInput::make('isi.label_perusahaan')->label('Label tanda tangan perusahaan')->required($isSprs),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lokasi', 'pembuat']))
            ->columns([
                Tables\Columns\TextColumn::make('dokumen')->label('Dokumen')->badge()->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama')->label('Cluster')->placeholder('Semua cluster'),
                Tables\Columns\TextColumn::make('versi')->label('Versi')->prefix('v')->alignCenter()->sortable(),
                Tables\Columns\TextColumn::make('berlaku_mulai')->label('Berlaku mulai')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (TemplateDokumen $r) => self::status($r))
                    ->color(fn ($state) => match ($state) {
                        'Berlaku' => 'success',
                        'Terjadwal' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('catatan')->label('Catatan')->limit(50)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('pembuat.name')->label('Dibuat oleh')->placeholder('—')
                    ->description(fn (TemplateDokumen $r) => optional($r->created_at)->format('d M Y H:i')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('dokumen')->options(DokumenBawaan::pilihan()),
                Tables\Filters\SelectFilter::make('lokasi_id')->label('Cluster')->relationship('lokasi', 'nama'),
            ])
            ->actions([
                Tables\Actions\Action::make('pratinjau')->label('Pratinjau')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (TemplateDokumen $r) => route('fo.cetak.template-dokumen', $r), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('versiBaru')->label('Versi baru dari ini')->icon('heroicon-o-document-plus')
                    ->visible(fn () => static::canCreate())
                    ->url(fn (TemplateDokumen $r) => static::getUrl('create', ['dari' => $r->id])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('berlaku_mulai', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTemplateDokumen::route('/'),
            'create' => Pages\CreateTemplateDokumen::route('/create'),
            'edit' => Pages\EditTemplateDokumen::route('/{record}/edit'),
        ];
    }

    /** Berlaku = versi yang dipakai hari ini untuk dokumen & cluster-nya. */
    public static function status(TemplateDokumen $r): string
    {
        if (! $r->sudahBerlaku()) {
            return 'Terjadwal';
        }
        $aktif = TemplateDokumen::query()->where('dokumen', $r->dokumen)
            ->when($r->lokasi_id, fn ($q) => $q->where('lokasi_id', $r->lokasi_id), fn ($q) => $q->whereNull('lokasi_id'))
            ->whereDate('berlaku_mulai', '<=', today())->orderByDesc('berlaku_mulai')->orderByDesc('versi')->value('id');

        return $aktif === $r->id ? 'Berlaku' : 'Riwayat';
    }

    /**
     * Bentuk state repeater (kunci uuid, butir sederhana jadi ['teks' => …]). Dibutuhkan saat
     * isi diganti lewat $set — jalur itu tidak melewati hidrasi repeater bawaan Filament.
     */
    public static function untukForm(array $isi): array
    {
        $daftar = fn (array $xs, callable $f) => collect($xs)->mapWithKeys(fn ($x) => [(string) Str::uuid() => $f($x)])->all();
        $sederhana = fn ($xs) => $daftar((array) $xs, fn ($t) => ['teks' => $t]);

        if (isset($isi['pasal'])) {
            $isi['pasal'] = $daftar((array) $isi['pasal'], fn ($p) => ['butir' => $sederhana($p['butir'] ?? [])] + (array) $p);
        }
        if (isset($isi['syarat'])) {
            $isi['syarat'] = $sederhana($isi['syarat']);
        }

        return $isi;
    }

    /** Isian awal versi baru: versi yang berlaku sekarang (cluster → semua), atau teks bawaan. */
    public static function isiAwal(string $dokumen, $lokasiId): array
    {
        $sekarang = TemplateDokumen::berlakuUntuk($dokumen, $lokasiId ? (int) $lokasiId : null, today());

        return array_replace(DokumenBawaan::untuk($dokumen), (array) ($sekarang?->isi ?? []));
    }
}
