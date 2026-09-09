<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Clusters\GeneralLedger;
use App\Filament\Bo\Resources\JurnalResource\Pages;
use App\Models\Company;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Models\ChartOfAccount;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class JurnalResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = JurnalHeader::class;

    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Master';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal Umum';

    protected static ?string $recordTitleAttribute = 'no_jurnal';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Header Jurnal')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('no_jurnal')->label('No Jurnal')->disabled()->placeholder('Otomatis'),
                    Forms\Components\DatePicker::make('tgl_jurnal')->label('Tanggal')->required()->default(now()),
                    Forms\Components\Select::make('company_id')
                        ->label('Company')
                        ->relationship('company', 'name')
                        ->default(fn () => Company::query()->value('id'))
                        ->required(),
                    Forms\Components\TextInput::make('jenis_dokumen')->label('Jenis dokumen')->default('JU')->required(),
                    Forms\Components\TextInput::make('no_dokumen')->label('No dokumen sumber')->maxLength(64),
                    Forms\Components\TextInput::make('keterangan')->label('Keterangan')->columnSpan(3),
                ])
                ->disabled(fn (?JurnalHeader $record) => $record !== null && $record->status !== 'DRAFT'),

            Forms\Components\Section::make('Baris Jurnal')
                ->schema([
                    Forms\Components\Repeater::make('detil')
                        ->label('')
                        ->relationship()
                        ->columns(12)
                        ->minItems(2)
                        ->defaultItems(2)
                        ->live()
                        ->schema([
                            Forms\Components\Select::make('kode_account')
                                ->label('Akun')
                                ->options(fn () => ChartOfAccount::query()
                                    ->postable()
                                    ->orderBy('kode')
                                    ->get()
                                    ->mapWithKeys(fn ($a) => [$a->kode => $a->kode.' — '.$a->nama]))
                                ->searchable()
                                ->required()
                                ->columnSpan(5),
                            Forms\Components\Select::make('cost_center_id')
                                ->label('Cost Center')
                                ->relationship('costCenter', 'name')
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('debet')->label('Debet')->numeric()->default(0)->columnSpan(2),
                            Forms\Components\TextInput::make('kredit')->label('Kredit')->numeric()->default(0)->columnSpan(2),
                            Forms\Components\Hidden::make('urutan')->default(1),
                            Forms\Components\TextInput::make('keterangan')->label('Ket.')->columnSpan(11),
                        ])
                        ->disabled(fn (?JurnalHeader $record) => $record !== null && $record->status !== 'DRAFT'),

                    Forms\Components\Placeholder::make('balance')
                        ->label('Status balance')
                        ->content(function (Get $get): HtmlString {
                            $rows = $get('detil') ?? [];
                            $d = array_sum(array_map(fn ($r) => (float) ($r['debet'] ?? 0), $rows));
                            $k = array_sum(array_map(fn ($r) => (float) ($r['kredit'] ?? 0), $rows));
                            $balanced = abs($d - $k) < 0.001 && $d > 0;
                            $color = $balanced ? '#059669' : '#dc2626';
                            $label = $balanced ? 'BALANCE ✓' : 'TIDAK BALANCE ✗';

                            return new HtmlString(sprintf(
                                '<span style="color:%s;font-weight:600">Debet %s | Kredit %s — %s</span>',
                                $color,
                                number_format($d, 2),
                                number_format($k, 2),
                                $label,
                            ));
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_jurnal')->label('No Jurnal')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_jurnal')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('jenis_dokumen')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('no_dokumen')->label('Dok sumber')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('total_debet')->label('Nilai')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray',
                    'APPROVED' => 'warning',
                    'POSTED' => 'success',
                    'CANCELLED' => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'POSTED' => 'Posted', 'CANCELLED' => 'Cancelled',
                ]),
                Tables\Filters\SelectFilter::make('jenis_dokumen')->options(fn () => JurnalHeader::query()
                    ->distinct()->pluck('jenis_dokumen', 'jenis_dokumen')->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('cetak')
                    ->label('Cetak')->icon('heroicon-o-printer')->color('gray')
                    ->url(fn (JurnalHeader $r) => route('bo.cetak.jurnal', $r))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')->icon('heroicon-o-check')->color('warning')
                    ->visible(fn (JurnalHeader $r) => $r->status === 'DRAFT')
                    ->requiresConfirmation()
                    ->action(fn (JurnalHeader $r) => app(JurnalPostingServiceInterface::class)->approve($r)),
                Tables\Actions\Action::make('post')
                    ->label('Posting')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (JurnalHeader $r) => $r->status === 'APPROVED')
                    ->requiresConfirmation()
                    ->action(function (JurnalHeader $r) {
                        $r->update(['status' => 'POSTED', 'posted' => true, 'posted_at' => now()]);
                    }),
                Tables\Actions\Action::make('reverse')
                    ->label('Reverse')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (JurnalHeader $r) => $r->status === 'POSTED' && $r->reversal_of_id === null)
                    ->requiresConfirmation()
                    ->form([Forms\Components\TextInput::make('reason')->label('Alasan')->required()])
                    ->action(fn (JurnalHeader $r, array $data) => app(JurnalPostingServiceInterface::class)->reverse($r, $data['reason'])),
            ])
            ->defaultSort('tgl_jurnal', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJurnals::route('/'),
            'create' => Pages\CreateJurnal::route('/create'),
            'view' => Pages\ViewJurnal::route('/{record}'),
            'edit' => Pages\EditJurnal::route('/{record}/edit'),
        ];
    }
}
