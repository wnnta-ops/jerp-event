<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Komisi;
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
                Tables\Actions\Action::make('batal')->label('Batal')->icon('heroicon-o-arrow-uturn-left')->color('danger')->button()
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Realisasi Komisi')
                    ->modalDescription(fn (Komisi $r) => 'Batalkan realisasi pengajuan '.$r->no_pengajuan.' ('.$r->jml.' komisi)? Komisi kembali ke step sebelumnya (Pengajuan Komisi / DRAFT) untuk diproses ulang.')
                    ->action(function (Komisi $r) {
                        $n = Komisi::query()->where('no_pengajuan', $r->no_pengajuan)->where('status', 'PAID')
                            ->update(['status' => 'DRAFT', 'no_pengajuan' => null, 'tgl_bayar' => null]);
                        Notification::make()->success()->title('Realisasi dibatalkan')
                            ->body($n.' komisi kembali ke Pengajuan Komisi (DRAFT).')->send();
                    }),
            ])
            ->defaultSort('tgl_bayar', 'desc')->paginated([10, 25, 50]);
    }
}
