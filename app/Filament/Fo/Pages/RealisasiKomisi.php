<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Komisi;
use App\Support\Rupiah;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Realisasi Komisi — hasil pencairan (PAID) per No. Pengajuan (1 baris = 1 batch hasil
 * checklist Pengajuan + Approval). Cetak ulang permohonan per batch, atau buka
 * Detail/Edit untuk memperbaiki tiap komisi (NETT otomatis), lalu cetak ulang.
 */
class RealisasiKomisi extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'realisasi-komisi';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Realisasi Komisi';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Komisi::query()->whereNotNull('no_pengajuan')->where('status', 'PAID')
                    ->selectRaw('MIN(id) as id, no_pengajuan, COUNT(*) as jml, SUM(amount) as total_komisi, SUM(pph_amount) as total_pph, SUM(amount_net) as total_nett, MAX(tgl_bayar) as tgl_bayar')
                    ->groupBy('no_pengajuan')
            )
            ->heading('Realisasi Komisi')
            ->description('Satu baris = satu No. Pengajuan (batch). Cetak ulang permohonan, atau buka Detail/Edit untuk memperbaiki isinya.')
            ->columns([
                Tables\Columns\TextColumn::make('no_pengajuan')->label('No. Pengajuan')->badge()->color('warning')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jml')->label('Jumlah')->alignCenter()->formatStateUsing(fn ($state) => $state.' komisi'),
                Tables\Columns\TextColumn::make('total_komisi')->label('Total Komisi')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('total_pph')->label('Total PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('total_nett')->label('Total NETT')->money('IDR')->alignEnd()->weight('bold'),
                Tables\Columns\TextColumn::make('tgl_bayar')->label('Tgl Bayar')->date('d M Y')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('cetak')->label('Cetak')->icon('heroicon-o-printer')->color('gray')->button()
                    ->url(fn (Komisi $r) => route('fo.cetak.pengajuan-komisi', ['pengajuan' => $r->no_pengajuan]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('detail')->label('Detail / Edit')->icon('heroicon-o-pencil-square')->color('primary')->button()
                    ->modalHeading(fn (Komisi $r) => 'Pengajuan '.$r->no_pengajuan)
                    ->modalDescription('Perbaiki bila ada kesalahan — NETT dihitung ulang otomatis saat disimpan.')
                    ->modalWidth('5xl')
                    ->modalSubmitActionLabel('Simpan')
                    ->fillForm(fn (Komisi $r) => [
                        'lines' => Komisi::query()->where('no_pengajuan', $r->no_pengajuan)->orderBy('id')->get()
                            ->map(fn (Komisi $k) => [
                                'id' => $k->id,
                                'no_komisi' => $k->no_komisi,
                                'sales' => $k->agent?->name,
                                'amount' => (float) $k->amount,
                                'pph_amount' => (float) $k->pph_amount,
                                'ppn_amount' => (float) $k->ppn_amount,
                                'rek_bank' => $k->rek_bank,
                                'rek_number' => $k->rek_number,
                                'rek_holder' => $k->rek_holder,
                            ])->all(),
                    ])
                    ->form([
                        Forms\Components\Repeater::make('lines')
                            ->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(3)
                            ->collapsible()->collapsed()
                            ->itemLabel(fn (array $state) => ($state['no_komisi'] ?? '').' · '.($state['sales'] ?? ''))
                            ->schema([
                                Forms\Components\Hidden::make('id'),
                                Forms\Components\TextInput::make('no_komisi')->label('No. Komisi')->disabled(),
                                Forms\Components\TextInput::make('sales')->label('Sales')->disabled(),
                                Forms\Components\TextInput::make('amount')->label('Komisi')->numeric()->rupiah()->required(),
                                Forms\Components\TextInput::make('pph_amount')->label('PPh')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('ppn_amount')->label('PPn')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('rek_bank')->label('Bank'),
                                Forms\Components\TextInput::make('rek_number')->label('No. Rekening'),
                                Forms\Components\TextInput::make('rek_holder')->label('Nama Terang'),
                            ]),
                    ])
                    ->action(function (Komisi $r, array $data) {
                        foreach ($data['lines'] ?? [] as $line) {
                            if (empty($line['id'])) {
                                continue;
                            }
                            $amount = Rupiah::toFloat($line['amount'] ?? 0);
                            $pph = Rupiah::toFloat($line['pph_amount'] ?? 0);
                            $ppn = Rupiah::toFloat($line['ppn_amount'] ?? 0);
                            Komisi::where('id', $line['id'])->update([
                                'amount' => $amount,
                                'pph_amount' => $pph,
                                'ppn_amount' => $ppn,
                                'amount_net' => max(0, $amount - $pph - $ppn),
                                'rek_bank' => $line['rek_bank'] ?? null,
                                'rek_number' => $line['rek_number'] ?? null,
                                'rek_holder' => $line['rek_holder'] ?? null,
                            ]);
                        }
                        Notification::make()->success()->title('Pengajuan '.$r->no_pengajuan.' diperbarui')
                            ->body('Silakan Cetak ulang bila perlu.')->send();
                    }),
            ])
            ->defaultSort('tgl_bayar', 'desc')->paginated([10, 25, 50]);
    }
}
