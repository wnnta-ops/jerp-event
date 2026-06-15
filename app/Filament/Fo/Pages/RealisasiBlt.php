<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Blt;
use App\Support\Rupiah;
use Filament\Forms;
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
                Tables\Actions\Action::make('detail')->label('Detail / Edit')->icon('heroicon-o-pencil-square')->color('primary')->button()
                    ->modalHeading(fn (Blt $r) => 'Pengajuan '.$r->no_pengajuan)
                    ->modalDescription('Perbaiki bila ada kesalahan — NETT dihitung ulang otomatis saat disimpan.')
                    ->modalWidth('5xl')
                    ->modalSubmitActionLabel('Simpan')
                    ->fillForm(fn (Blt $r) => [
                        'lines' => Blt::query()->where('no_pengajuan', $r->no_pengajuan)
                            ->orderByRaw("FIELD(level_type,'SALES','KOORDINATOR','KANTOR')")->get()
                            ->map(fn (Blt $b) => [
                                'id' => $b->id,
                                'no_blt' => $b->no_blt,
                                'level_type' => $b->level_type,
                                'nama_user' => $b->nama_user,
                                'nilai_blt' => (float) $b->nilai_blt,
                                'pph_amount' => (float) $b->pph_amount,
                                'ppn_amount' => (float) $b->ppn_amount,
                                'rek_bank' => $b->rek_bank,
                                'rek_number' => $b->rek_number,
                                'rek_holder' => $b->rek_holder,
                            ])->all(),
                    ])
                    ->form([
                        Forms\Components\Repeater::make('lines')
                            ->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(3)
                            ->collapsible()->collapsed()
                            ->itemLabel(fn (array $state) => ($state['no_blt'] ?? '').' · '.($state['level_type'] ?? '').' · '.($state['nama_user'] ?? ''))
                            ->schema([
                                Forms\Components\Hidden::make('id'),
                                Forms\Components\TextInput::make('no_blt')->label('No. BLT')->disabled(),
                                Forms\Components\TextInput::make('level_type')->label('Type')->disabled(),
                                Forms\Components\TextInput::make('nama_user')->label('Penerima'),
                                Forms\Components\TextInput::make('nilai_blt')->label('Nilai BLT')->numeric()->rupiah()->required(),
                                Forms\Components\TextInput::make('pph_amount')->label('PPh')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('ppn_amount')->label('PPn')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('rek_bank')->label('Bank'),
                                Forms\Components\TextInput::make('rek_number')->label('No. Rekening'),
                                Forms\Components\TextInput::make('rek_holder')->label('Nama Terang'),
                            ]),
                    ])
                    ->action(function (Blt $r, array $data) {
                        foreach ($data['lines'] ?? [] as $line) {
                            if (empty($line['id'])) {
                                continue;
                            }
                            $nilai = Rupiah::toFloat($line['nilai_blt'] ?? 0);
                            $pph = Rupiah::toFloat($line['pph_amount'] ?? 0);
                            $ppn = Rupiah::toFloat($line['ppn_amount'] ?? 0);
                            Blt::where('id', $line['id'])->update([
                                'nama_user' => $line['nama_user'] ?? null,
                                'nilai_blt' => $nilai,
                                'pph_amount' => $pph,
                                'ppn_amount' => $ppn,
                                'nett' => max(0, $nilai - $pph - $ppn),
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
