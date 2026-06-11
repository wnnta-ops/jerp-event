<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Blt;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Realisasi BLT — pencairan BLT APPROVED (input PPh/PPn + rek/bank) → PAID. */
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
            ->query(Blt::query()->with(['agent', 'kontrak'])->whereIn('status', ['APPROVED', 'PAID']))
            ->heading('Realisasi BLT')
            ->description('BLT APPROVED → cairkan (input PPh/PPn + rekening) menjadi PAID.')
            ->columns([
                Tables\Columns\TextColumn::make('no_blt')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('level_type')->label('Type')->badge(),
                Tables\Columns\TextColumn::make('nama_user')->label('Nama User')->placeholder('—')->limit(20),
                Tables\Columns\TextColumn::make('nilai_blt')->label('Nilai BLT')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('pph_amount')->label('PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('nett')->label('NETT')->money('IDR')->alignEnd()->weight('bold'),
                Tables\Columns\TextColumn::make('tgl_bayar')->label('Tgl Bayar')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $s) => $s === 'PAID' ? 'success' : 'warning'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['APPROVED' => 'Approved', 'PAID' => 'Paid'])->default('APPROVED'),
                Tables\Filters\SelectFilter::make('level_type')->label('Type')->options(['SALES' => 'Sales', 'KOORDINATOR' => 'Koordinator', 'KANTOR' => 'Kantor']),
            ])
            ->actions([
                Tables\Actions\Action::make('realisasi')->label('Realisasi')->icon('heroicon-o-banknotes')->color('success')->button()
                    ->visible(fn (Blt $r) => $r->status === 'APPROVED')
                    ->modalHeading('Realisasi (Cairkan) BLT')
                    ->fillForm(fn (Blt $r) => [
                        'tgl_bayar' => now(),
                        'pph_amount' => (float) $r->pph_amount,
                        'ppn_amount' => (float) $r->ppn_amount,
                        'rek_bank' => $r->rek_bank ?: $r->agent?->rekening_bank,
                        'rek_number' => $r->rek_number ?: $r->agent?->rekening_number,
                        'rek_holder' => $r->rek_holder ?: ($r->agent?->rekening_holder ?: $r->nama_user),
                    ])
                    ->form([
                        Forms\Components\DatePicker::make('tgl_bayar')->label('Tgl Pembayaran')->required(),
                        Forms\Components\TextInput::make('pph_amount')->label('PPh (Rp)')->numeric()->prefix('Rp'),
                        Forms\Components\TextInput::make('ppn_amount')->label('PPn (Rp, jika ada)')->numeric()->prefix('Rp')->default(0),
                        Forms\Components\TextInput::make('rek_bank')->label('Bank'),
                        Forms\Components\TextInput::make('rek_number')->label('No. Rekening'),
                        Forms\Components\TextInput::make('rek_holder')->label('Nama Terang'),
                    ])
                    ->action(function (Blt $r, array $data) {
                        $net = max(0, (float) $r->nilai_blt - (float) ($data['pph_amount'] ?? 0) - (float) ($data['ppn_amount'] ?? 0));
                        $r->update([
                            'status' => 'PAID',
                            'tgl_bayar' => $data['tgl_bayar'],
                            'pph_amount' => $data['pph_amount'] ?? 0,
                            'ppn_amount' => $data['ppn_amount'] ?? 0,
                            'nett' => $net,
                            'rek_bank' => $data['rek_bank'] ?? null,
                            'rek_number' => $data['rek_number'] ?? null,
                            'rek_holder' => $data['rek_holder'] ?? null,
                        ]);
                        Notification::make()->success()->title('BLT dicairkan — '.$r->no_blt)
                            ->body('NETT Rp '.number_format($net, 0, ',', '.'))->send();
                    }),
            ])
            ->defaultSort('tgl_blt', 'desc')->paginated([10, 25, 50]);
    }
}
