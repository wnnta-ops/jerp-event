<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\BgResource\Pages;
use App\Modules\FO\FinanceAR\Models\Bg;
use App\Modules\FO\MarketingSales\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class BgResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Bg::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Bilyet Giro';

    protected static ?string $pluralModelLabel = 'Bilyet Giro (BG)';

    protected static ?int $navigationSort = 55;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // No. BG otomatis dari numerator (BG-...). Hanya tampil (disabled) saat edit.
            Forms\Components\TextInput::make('no_bg')->label('No. BG/Cek')->disabled()->dehydrated(false)->placeholder('otomatis (BG-...)')->visibleOn('edit'),
            // Bank = free text (ketik manual, tak harus dari master bank).
            Forms\Components\TextInput::make('bank_nama')->label('Bank')->maxLength(100)->placeholder('ketik nama bank'),
            Forms\Components\DatePicker::make('tgl_bg')->label('Tgl BG'),
            Forms\Components\DatePicker::make('tgl_jatuh_tempo')->label('Jatuh Tempo'),
            Forms\Components\Select::make('customer_id')->label('Customer')->searchable()->live()
                // Server-side search: cari dari SELURUH Master Customer (bukan cuma 300 pertama).
                ->getSearchResultsUsing(fn (string $search) => Customer::query()
                    ->where('full_name', 'like', "%{$search}%")->orderBy('full_name')->limit(50)->pluck('full_name', 'id'))
                ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->full_name)
                // Ganti customer → reset pilihan tagihan agar tak salah-tarik.
                ->afterStateUpdated(fn (Forms\Set $set) => $set('tagihan_ref', null)),
            // Setelah Customer dipilih: pilih DP/Angsuran yang BELUM LUNAS → Nominal terisi otomatis (tetap bisa diedit).
            Forms\Components\Select::make('tagihan_ref')
                ->label('DP / Angsuran (belum lunas)')
                ->helperText('Pilih tagihan → Nominal terisi otomatis (tetap bisa diedit).')
                ->searchable()
                ->live()
                ->dehydrated(false) // hanya pembantu, tak disimpan ke fo_bg
                ->visible(fn (Forms\Get $get) => filled($get('customer_id')))
                ->options(function (Forms\Get $get): array {
                    $cid = $get('customer_id');
                    if (! $cid) {
                        return [];
                    }

                    return DB::table('fo_kontrak_tagihan as t')
                        ->join('fo_kontrak as k', 'k.id', '=', 't.kontrak_id')
                        ->where('k.customer_id', $cid)->whereNull('k.deleted_at')
                        ->whereIn('t.tipe', ['DP', 'ANG'])
                        // Kecualikan PENCAIRAN KPR (pencairan bank → developer, bukan bayaran customer via giro).
                        ->whereRaw("UPPER(COALESCE(t.nama_tagihan, '')) NOT LIKE '%PENCAIRAN%'")
                        ->whereRaw('(t.nilai - COALESCE(t.pelunasan, 0)) >= 1') // belum lunas (sisa >= 1 rupiah; abaikan recehan desimal)
                        ->orderBy('k.no_kontrak')->orderBy('t.urutan')
                        ->get(['t.id', 'k.no_kontrak', 't.nama_tagihan', 't.nilai', 't.pelunasan'])
                        ->mapWithKeys(fn ($t) => [
                            $t->id => $t->no_kontrak.' · '.$t->nama_tagihan.' · sisa Rp '.number_format((float) $t->nilai - (float) $t->pelunasan, 0, ',', '.'),
                        ])->all();
                })
                ->afterStateUpdated(function ($state, Forms\Set $set): void {
                    if (! $state) {
                        return;
                    }
                    $t = DB::table('fo_kontrak_tagihan as t')->join('fo_kontrak as k', 'k.id', '=', 't.kontrak_id')
                        ->where('t.id', $state)->first(['t.nilai', 't.pelunasan', 't.nama_tagihan', 'k.no_kontrak']);
                    if (! $t) {
                        return;
                    }
                    $sisa = (float) $t->nilai - (float) $t->pelunasan;
                    // State field rupiah = integer polos (mask yang memformat tampilan). Bulatkan → hindari recehan desimal.
                    $set('amount', (int) round($sisa));                                            // default (bisa diedit)
                    $set('keterangan', 'Pembayaran '.$t->nama_tagihan.' — '.$t->no_kontrak);       // jejak tagihan (bisa diedit)
                }),
            Forms\Components\TextInput::make('amount')->label('Nominal (Rp)')->numeric()->rupiah()->default(0),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_bg')->label('No. BG')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('bank_nama')->label('Bank')->placeholder('—')->getStateUsing(fn (Bg $r) => $r->bank_nama ?: $r->bank?->name),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(24),
                Tables\Columns\TextColumn::make('tgl_jatuh_tempo')->label('Jatuh Tempo')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'CAIR' => 'success', 'BERMASALAH' => 'danger', 'JATUH_TEMPO' => 'warning', 'DIGANTI' => 'gray', default => 'info',
                }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options([
                'NORMAL' => 'Normal', 'JATUH_TEMPO' => 'Jatuh Tempo', 'CAIR' => 'Cair', 'BERMASALAH' => 'Bermasalah', 'DIGANTI' => 'Diganti',
            ])])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                // Ganti Giro: giro bermasalah → buat giro pengganti, tandai lama DIGANTI.
                Tables\Actions\Action::make('ganti')->label('Ganti Giro')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (Bg $r) => ! in_array($r->status, ['DIGANTI', 'CAIR']))
                    ->form([
                        Forms\Components\TextInput::make('no_bg_baru')->label('No. BG Pengganti')->required(),
                        Forms\Components\DatePicker::make('tgl_bg_baru')->label('Tgl BG Pengganti')->default(now()),
                        Forms\Components\DatePicker::make('tgl_jt_baru')->label('Jatuh Tempo Baru'),
                    ])
                    ->action(function (Bg $r, array $data) {
                        $baru = Bg::create([
                            'tenant_id' => $r->tenant_id, 'no_bg' => $data['no_bg_baru'],
                            'tgl_bg' => $data['tgl_bg_baru'] ?? now(), 'tgl_jatuh_tempo' => $data['tgl_jt_baru'] ?? null,
                            'bank_id' => $r->bank_id, 'bank_nama' => $r->bank_nama, 'customer_id' => $r->customer_id, 'tts_id' => $r->tts_id,
                            'amount' => $r->amount, 'pengelola' => $r->pengelola, 'status' => 'NORMAL',
                            'keterangan' => 'Pengganti dari BG '.$r->no_bg,
                        ]);
                        $r->update(['status' => 'DIGANTI', 'bg_pengganti_id' => $baru->id]);
                        Notification::make()->success()->title('Giro diganti → '.$baru->no_bg)->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBgs::route('/')];
    }
}
