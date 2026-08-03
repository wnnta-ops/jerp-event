<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\GmcResource\Pages;
use App\Modules\FO\FinanceAR\Models\Gmc;
use App\Modules\FO\FinanceAR\Models\GmcItem;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * GMC (Gimmick / FREE SPR) — biaya promo developer untuk customer; mengikat No. SPR, Unit,
 * & Customer (via kontrak). Bukan tagihan customer. Tombol "Posting BO" mengirim biaya ini
 * ke Posting Invoice BO (Sumber: GMC) — sama pola Komisi/BLT.
 */
class GmcResource extends Resource
{
    protected static ?string $model = Gmc::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'GMC (Gimmick)';

    protected static ?string $pluralModelLabel = 'GMC (Gimmick)';

    protected static ?int $navigationSort = 66;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('no_gmc')->label('No. GMC')->disabled()->dehydrated(false)->placeholder('otomatis (GMC-...)')->visibleOn('edit'),
            Forms\Components\DatePicker::make('tgl')->label('Tgl')->default(now()),
            // Mengikat No. SPR + Unit + Customer dari kontrak.
            Forms\Components\Select::make('kontrak_id')->label('SPR / Kontrak')->searchable()->live()->required()
                ->getSearchResultsUsing(fn (string $search) => Kontrak::with(['customer', 'unit'])
                    ->where(fn ($q) => $q->where('no_kontrak', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"))
                        ->orWhereHas('unit', fn ($u) => $u->where('kode_unit', 'like', "%{$search}%")))
                    ->latest('id')->limit(50)->get()
                    ->mapWithKeys(fn ($k) => [$k->id => trim($k->no_kontrak.' — '.($k->customer?->full_name ?? '').' — '.($k->unit?->kode_unit ?? ''), ' —')]))
                ->getOptionLabelUsing(fn ($value) => ($k = Kontrak::with(['customer', 'unit'])->find($value)) ? trim($k->no_kontrak.' — '.($k->customer?->full_name ?? '').' — '.($k->unit?->kode_unit ?? ''), ' —') : null)
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $k = $state ? Kontrak::find($state) : null;
                    $set('customer_id', $k?->customer_id);
                })
                ->helperText('Mengikat No. SPR, Unit, & Customer dari kontrak.'),
            // Read-only: Customer mengikuti SPR/Kontrak (di-set otomatis di atas) agar tak salah pilih.
            Forms\Components\Select::make('customer_id')->label('Customer')
                ->options(fn (Forms\Get $get) => Customer::whereKey($get('customer_id'))->pluck('full_name', 'id'))
                ->disabled()->dehydrated()
                ->helperText('Otomatis dari SPR/Kontrak — tak dapat diubah.'),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),

            // Rincian komponen (WAJIB, min 1) — nilai & nama GMC diturunkan dari komponen.
            // Gimmick bisa multi-supplier (mis. PPJB = Jasa Notaris + PNBP + Jasa CV).
            Forms\Components\Repeater::make('items')
                ->label('Rincian Komponen (wajib — minimal 1)')
                ->relationship()
                ->required()
                ->minItems(1)
                ->schema([
                    // Kategori Gimmick dari master BO (bs_gimmick_categories @ ipro_bo). Tak punya nilai → nilai tetap manual.
                    Forms\Components\Select::make('nama')->label('Kategori Gimmick')->required()->searchable()
                        ->options(fn () => self::gimmickOptions())->columnSpan(2),
                    Forms\Components\Select::make('supplier_id')->label('Supplier')->required()->searchable()
                        ->options(fn () => GmcItem::supplierOptions())->columnSpan(2),
                    Forms\Components\TextInput::make('nilai')->label('Nilai (Rp)')->numeric()->rupiah()->default(0)->required()->columnSpan(2),
                ])
                ->columns(6)->live()->addActionLabel('+ Tambah komponen')
                ->helperText('Tiap komponen jadi hutang AP terpisah ke supplier-nya saat Posting BO. Total GMC = jumlah nilai komponen.')
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('total_rincian')->label('Total Rincian')
                ->content(fn (Forms\Get $get) => 'Rp '.number_format(collect($get('items') ?? [])
                    ->sum(fn ($r) => (float) str_replace('.', '', (string) ($r['nilai'] ?? 0))), 0, ',', '.'))
                ->visible(fn (Forms\Get $get) => filled($get('items')))
                ->columnSpanFull(),
        ])->columns(2);
    }

    /** Bila GMC punya rincian komponen, nilai total = jumlah komponen. */
    public static function syncNilaiFromItems(Gmc $record): void
    {
        if ($record->items()->exists()) {
            $record->update(['nilai' => (float) $record->items()->sum('nilai')]);
        }
    }

    /**
     * GMC.nama diturunkan dari kategori komponen (distinct) — field "Nama Gimmick"
     * level-GMC sudah dihapus, nama kini digabung dari kategori tiap komponen.
     */
    public static function syncNamaFromItems(Gmc $record): void
    {
        $names = $record->items()->pluck('nama')->filter()->unique()->values();
        if ($names->isNotEmpty()) {
            $record->update(['nama' => $names->implode(', ')]);
        }
    }

    /**
     * Isi nama saat create (sebelum insert) dari rincian komponen agar kolom
     * fo_gmc.nama (NOT NULL) terpenuhi; nilai akhir dikoreksi lagi oleh
     * syncNamaFromItems() dari komponen yang sudah tersimpan.
     */
    protected static function fillNamaFromData(array $data): array
    {
        $names = collect($data['items'] ?? [])->pluck('nama')->filter()->unique()->values();
        $data['nama'] = $names->isNotEmpty() ? $names->implode(', ') : ($data['nama'] ?? 'Gimmick');

        return $data;
    }

    /**
     * Posting GMC → antrian Posting Invoice BO. Bila ada rincian komponen: kirim
     * 1 baris per komponen (refund_no unik + supplier masing-masing). Bila tidak:
     * 1 baris (perilaku lama, supplier dipilih saat posting di BO).
     */
    public static function postGmcToBo(Gmc $r): void
    {
        $poster = app(\App\Modules\BO\IproBridge\Services\IproApPoster::class);
        $sprRef = $r->kontrak ? ' — SPR '.$r->kontrak->no_kontrak : '';
        $custId = $r->customer_id ? (int) $r->customer_id : null;
        $custName = $r->customer?->full_name;
        $tgl = $r->tgl?->toDateString();
        $items = $r->items;

        if ($items->isNotEmpty()) {
            $i = 0;
            foreach ($items as $it) {
                $i++;
                $poster->queueGmc(
                    (string) $r->no_gmc.'-'.$i,
                    (float) $it->nilai,
                    $custId, $custName, $tgl,
                    trim('Gimmick '.$it->nama.$sprRef),
                    $it->supplier_id ? (int) $it->supplier_id : null,
                );
            }
        } else {
            $poster->queueGmc((string) $r->no_gmc, (float) $r->nilai, $custId, $custName, $tgl,
                trim('Gimmick '.$r->nama.$sprRef));
        }

        $r->update(['status' => 'DIPROSES']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_gmc')->label('No. GMC')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('No. SPR')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(22),
                Tables\Columns\TextColumn::make('nama')->label('Gimmick')->limit(28),
                Tables\Columns\TextColumn::make('nilai')->label('Nilai')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => $state === 'DIPROSES' ? 'warning' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['DRAFT' => 'Draft', 'DIPROSES' => 'Diproses']),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data) => self::fillNamaFromData($data))
                    ->after(function (Gmc $record) {
                        self::syncNamaFromItems($record);
                        self::syncNilaiFromItems($record);
                    }),
                self::gmcMassalAction(),
            ])
            ->actions([
                // Posting BO: kirim biaya gimmick ke antrian Posting Invoice BO (Sumber: GMC).
                Tables\Actions\Action::make('postingBo')->label('Posting BO')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (Gmc $r) => (float) $r->nilai > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Posting GMC ke BO')
                    ->modalDescription(fn (Gmc $r) => 'Kirim biaya gimmick '.$r->no_gmc.' (Rp '.number_format((float) $r->nilai, 0, ',', '.').') ke Posting Invoice BO (Sumber: GMC)'.($r->items()->exists() ? ' — pecah '.$r->items()->count().' komponen ke supplier masing-masing' : '').'.')
                    ->action(function (Gmc $r) {
                        try {
                            self::postGmcToBo($r);
                            \Filament\Notifications\Notification::make()->success()->title('GMC dikirim ke Posting Invoice BO')
                                ->body('Biaya gimmick '.$r->no_gmc.' menunggu diposting di BO → Posting Invoice (Sumber: GMC).')->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()->warning()->title('Gagal kirim ke BO')->body($e->getMessage())->persistent()->send();
                        }
                    }),
                Tables\Actions\EditAction::make()->after(function (Gmc $record) {
                    self::syncNamaFromItems($record);
                    self::syncNilaiFromItems($record);
                }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                // Posting BO massal — kirim GMC terpilih ke Posting Invoice BO sekaligus (anti dobel).
                Tables\Actions\BulkAction::make('postingBoBulk')->label('Posting BO (terpilih)')
                    ->icon('heroicon-o-paper-airplane')->color('success')->requiresConfirmation()
                    ->modalHeading('Posting BO GMC terpilih')
                    ->modalDescription('Kirim GMC terpilih ke Posting Invoice BO (Sumber: GMC). Yang sudah DIPROSES atau nilai 0 dilewati (tak dobel-posting).')
                    ->action(function (\Illuminate\Support\Collection $records): void {
                        $ok = 0;
                        $skip = 0;
                        $fail = 0;
                        foreach ($records as $r) {
                            if ($r->status === 'DIPROSES' || (float) $r->nilai <= 0) {
                                $skip++;

                                continue;
                            }
                            try {
                                self::postGmcToBo($r);
                                $ok++;
                            } catch (\Throwable $e) {
                                $fail++;
                            }
                        }
                        \Filament\Notifications\Notification::make()
                            ->title('Posting BO massal: '.$ok.' terkirim'.($skip ? ', '.$skip.' dilewati' : '').($fail ? ', '.$fail.' gagal' : ''))
                            ->{$fail ? 'warning' : 'success'}()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('id', 'desc');
    }

    /** Opsi Nama Gimmick dari master Kategori Gimmick BO (bs_gimmick_categories @ ipro_bo). */
    private static function gimmickOptions(): array
    {
        try {
            return \Illuminate\Support\Facades\DB::connection(config('ipro.connection', 'ipro_bo'))
                ->table('bs_gimmick_categories')->where('is_active', true)
                ->orderBy('name')->pluck('name', 'name')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Tombol "GMC Massal" — buka HALAMAN repeater (bukan modal table-action) agar
     * Select searchable/live di dalam repeater TIDAK kena "Livewire Entangle Error"
     * (mountedTableActionsData) yang membuat tambah/hapus baris jadi dobel.
     */
    private static function gmcMassalAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('gmcMassal')
            ->label('GMC Massal')->icon('heroicon-o-squares-plus')->color('warning')
            ->url(fn () => static::getUrl('massal'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGmcs::route('/'),
            'massal' => Pages\GmcMassal::route('/massal'),
        ];
    }
}
