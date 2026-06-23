<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Blt;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Realisasi BLT — daftar per No. Pengajuan (1 baris = 1 pengajuan hasil checklist
 * Generate). Cetak ulang permohonan per batch, atau buka Detail/Edit untuk
 * memperbaiki tiap BLT di dalamnya (NETT otomatis), lalu cetak ulang.
 */
class RealisasiBlt extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'realisasi-blt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Realisasi BLT';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Blt::query()->whereNotNull('no_pengajuan')->where('status', 'PAID')
                    ->selectRaw('MIN(id) as id, no_pengajuan, COUNT(*) as jml, SUM(nilai_blt) as total_nilai, SUM(pph_amount) as total_pph, SUM(nett) as total_nett, MAX(tgl_bayar) as tgl_bayar')
                    ->groupBy('no_pengajuan')
            )
            ->heading('Realisasi BLT')
            ->description('Satu baris = satu No. Pengajuan (batch checklist). Cetak ulang permohonan, atau buka Detail/Edit untuk memperbaiki isinya.')
            ->columns([
                Tables\Columns\TextColumn::make('no_pengajuan')->label('No. Pengajuan')->badge()->color('warning')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jml')->label('Jumlah BLT')->alignCenter()->formatStateUsing(fn ($state) => $state.' BLT'),
                Tables\Columns\TextColumn::make('total_nilai')->label('Total Nilai BLT')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('total_pph')->label('Total PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('total_nett')->label('Total NETT')->money('IDR')->alignEnd()->weight('bold'),
                Tables\Columns\TextColumn::make('tgl_bayar')->label('Tgl Bayar')->date('d M Y')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('cetak')->label('Cetak')->icon('heroicon-o-printer')->color('gray')->button()
                    ->url(fn (Blt $r) => route('fo.cetak.pengajuan-blt', ['pengajuan' => $r->no_pengajuan]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('batal')->label('Batal')->icon('heroicon-o-arrow-uturn-left')->color('danger')->button()
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Realisasi BLT')
                    ->modalDescription(fn (Blt $r) => 'Batalkan realisasi pengajuan '.$r->no_pengajuan.' ('.$r->jml.' BLT)? BLT kembali ke step sebelumnya (Pengajuan BLT / DRAFT) untuk diproses ulang.')
                    ->action(function (Blt $r) {
                        $n = Blt::query()->where('no_pengajuan', $r->no_pengajuan)->where('status', 'PAID')
                            ->update(['status' => 'DRAFT', 'no_pengajuan' => null, 'tgl_bayar' => null]);
                        Notification::make()->success()->title('Realisasi dibatalkan')
                            ->body($n.' BLT kembali ke Pengajuan BLT (DRAFT).')->send();
                    }),
            ])
            ->defaultSort('tgl_bayar', 'desc')->paginated([10, 25, 50]);
    }
}
