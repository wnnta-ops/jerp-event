<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\RefundResource\Pages;
use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\MarketingSales\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Refund';

    protected static ?string $pluralModelLabel = 'Refund / Cash Back';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('no_refund')->label('No. Refund')->disabled()->dehydrated(false)->placeholder('otomatis (RF-...)')->visibleOn('edit'),
            Forms\Components\Select::make('jenis')->label('Jenis')->default('BOOKING')->required()
                ->options(['BOOKING' => 'Refund Booking (batal)', 'LEBIH_BAYAR' => 'Lebih Bayar (Cash Back)']),
            Forms\Components\DatePicker::make('tgl_refund')->label('Tgl Refund')->default(now()),
            Forms\Components\Select::make('customer_id')->label('Customer')->searchable()
                ->options(fn () => Customer::query()->limit(300)->pluck('full_name', 'id')),
            Forms\Components\TextInput::make('nilai_kotor')->label('Nilai Kotor (Rp)')->numeric()->rupiah()->default(0)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Get $get, Forms\Set $set) => $set('nilai_refund', max(0, (float) $state - (float) $get('biaya_admin')))),
            Forms\Components\TextInput::make('biaya_admin')->label('Biaya Admin (Rp)')->numeric()->rupiah()->default(0)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Get $get, Forms\Set $set) => $set('nilai_refund', max(0, (float) $get('nilai_kotor') - (float) $state))),
            Forms\Components\TextInput::make('nilai_refund')->label('Nilai Refund (Rp)')->numeric()->rupiah()->default(0)
                ->helperText('= Nilai Kotor − Biaya Admin'),
            Forms\Components\Select::make('bank_id')->label('Bank Tujuan')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\TextInput::make('rekening_tujuan')->label('Rekening Tujuan'),
            Forms\Components\Select::make('status')->label('Status')->default('DRAFT')
                ->options(['DRAFT' => 'Draft', 'DIPROSES' => 'Diproses', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal']),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_refund')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge()->color(fn (?string $s) => $s === 'BOOKING' ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(24),
                Tables\Columns\TextColumn::make('tgl_refund')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('nilai_refund')->label('Refund')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $s) => match ($s) {
                    'SELESAI' => 'success', 'BATAL' => 'danger', 'DIPROSES' => 'warning', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options(['BOOKING' => 'Booking', 'LEBIH_BAYAR' => 'Lebih Bayar']),
                Tables\Filters\SelectFilter::make('status')->options(['DRAFT' => 'Draft', 'DIPROSES' => 'Diproses', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal']),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRefunds::route('/')];
    }
}
