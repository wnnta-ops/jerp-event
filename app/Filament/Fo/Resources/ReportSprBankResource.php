<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ReportSprBankResource\Pages;
use App\Modules\FO\Launching\Models\SprBankPrint;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

/**
 * Report SPR Bank — daftar customer yang berkas SPR Bank-nya SUDAH dicetak.
 *
 * Baris muncul sendiri saat tombol "Cetak SPR Bank" di Verifikasi Data ditekan;
 * tidak ada tambah/ubah manual. Laporan yang isinya bisa diketik tangan tidak
 * lagi menjawab pertanyaan "siapa yang sudah dicetak" — jawabannya jadi "siapa
 * yang diketik seseorang".
 */
class ReportSprBankResource extends Resource
{
    protected static ?string $model = SprBankPrint::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'NUP';

    protected static ?string $slug = 'report-spr-bank';

    protected static ?string $navigationLabel = 'Report SPR Bank';

    protected static ?string $modelLabel = 'Cetakan SPR Bank';

    protected static ?string $pluralModelLabel = 'Report SPR Bank';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['registration.customer', 'registration.event', 'registration.unit.unitType', 'pencetak']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('terakhir_cetak_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('no_surat')->label('No. SPR')
                    ->searchable()->sortable()->weight('bold'),

                Tables\Columns\TextColumn::make('registration.no_nup')->label('No. NUP')
                    ->searchable()->sortable(),

                Tables\Columns\TextColumn::make('registration.nama_pendaftar')->label('Customer')
                    ->searchable()->sortable()->wrap(),

                // Rantai cadangan SAMA dengan yang dicetak di dokumen. Pada banyak
                // pendaftaran `phone` kosong dan nomornya hanya ada di master customer;
                // membaca satu kolom saja membuat laporan menampilkan "—" untuk baris
                // yang lembar cetaknya jelas-jelas memuat nomor.
                Tables\Columns\TextColumn::make('telp')->label('No. Telp')
                    ->state(fn (SprBankPrint $r) => $r->registration?->phone
                        ?: ($r->registration?->customer?->mobile ?: null))
                    ->placeholder('—')
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'registration',
                        fn ($q) => $q->where('phone', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn ($c) => $c->where('mobile', 'like', "%{$search}%"))
                    )),

                // Idem: tipe_nup sering kosong dan dokumen jatuh ke tipe unit, lalu
                // ke 'RUMAH'. Laporan mengikuti supaya cocok dengan lembar cetaknya.
                Tables\Columns\TextColumn::make('type')->label('Type')
                    ->state(fn (SprBankPrint $r) => $r->registration?->tipe_nup
                        ?: ($r->registration?->unit?->unitType?->name ?: 'RUMAH'))
                    ->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('registration.pilihan_unit_1')->label('Unit')
                    ->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('registration.cara_bayar')->label('Cara Bayar')
                    ->badge()->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('terakhir_cetak_at')->label('Terakhir dicetak')
                    ->dateTime('d M Y H:i')->sortable(),

                Tables\Columns\TextColumn::make('pencetak.name')->label('Dicetak oleh')
                    ->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('jumlah_cetak')->label('Jml cetak')
                    ->badge()->color('gray')->alignCenter()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('rentang')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('dari')->label('Dicetak dari'),
                        \Filament\Forms\Components\DatePicker::make('sampai')->label('Sampai'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $v) => $q->whereDate('terakhir_cetak_at', '>=', $v))
                        ->when($data['sampai'] ?? null, fn ($q, $v) => $q->whereDate('terakhir_cetak_at', '<=', $v))),
            ])
            ->actions([
                // preview=1 → halaman dibuka TANPA menambah hitungan cetak. Melihat
                // ulang bukan mencetak; kalau ikut dihitung, "terakhir dicetak" akan
                // berubah tiap kali seseorang membuka laporan ini.
                Tables\Actions\Action::make('preview')
                    ->label('Preview')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (SprBankPrint $r) => URL::signedRoute('fo.cetak.spr-bank', [
                        'registration' => $r->nup_registration_id, 'preview' => 1,
                    ]))
                    ->openUrlInNewTab(),

                // Baca-saja juga. Dari laporan ini yang berlaku adalah lembar yang
                // SUDAH dicetak; membukanya kembali dalam keadaan bisa disunting
                // membuat berkas berubah tanpa jejak setelah diserahkan ke bank.
                // Untuk mengubah isinya, cetak ulang dari Verifikasi Data.
                Tables\Actions\Action::make('cetak')
                    ->label('Cetak')->icon('heroicon-o-printer')->color('info')
                    ->url(fn (SprBankPrint $r) => URL::signedRoute('fo.cetak.spr-bank', [
                        'registration' => $r->nup_registration_id, 'cetak' => 1,
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Belum ada SPR Bank yang dicetak')
            ->emptyStateDescription('Baris muncul sendiri setelah tombol "Cetak SPR Bank" ditekan di Verifikasi Data.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReportSprBank::route('/')];
    }

    // Laporan: hanya dibaca. Isinya lahir dari tindakan mencetak, bukan dari input.
    public static function canCreate(): bool
    {
        return false;
    }
}
