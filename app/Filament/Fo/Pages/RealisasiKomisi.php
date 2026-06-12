<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Komisi;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Realisasi Komisi — pencairan komisi APPROVED (input PPh/PPn + rek/bank) → PAID. */
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
            ->query(Komisi::query()->with(['agent', 'kontrak'])->whereIn('status', ['APPROVED', 'PAID']))
            ->heading('Realisasi Komisi')
            ->description('Komisi APPROVED → cairkan (input PPh/PPn + rekening) menjadi PAID.')
            ->columns([
                Tables\Columns\TextColumn::make('no_komisi')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('Komisi')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('pph_amount')->label('PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('amount_net')->label('NETT')->money('IDR')->alignEnd()->weight('bold'),
                Tables\Columns\TextColumn::make('tgl_bayar')->label('Tgl Bayar')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $s) => $s === 'PAID' ? 'success' : 'warning'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['APPROVED' => 'Approved', 'PAID' => 'Paid'])->default('APPROVED'),
            ])
            ->actions([
                Tables\Actions\Action::make('realisasi')->label('Realisasi')->icon('heroicon-o-banknotes')->color('success')->button()
                    ->visible(fn (Komisi $r) => $r->status === 'APPROVED')
                    ->modalHeading('Realisasi (Cairkan) Komisi')
                    ->fillForm(fn (Komisi $r) => [
                        'tgl_bayar' => now(),
                        'pph_amount' => (float) $r->pph_amount,
                        'ppn_amount' => (float) $r->ppn_amount,
                        'rek_bank' => $r->rek_bank ?: $r->agent?->rekening_bank,
                        'rek_number' => $r->rek_number ?: $r->agent?->rekening_number,
                        'rek_holder' => $r->rek_holder ?: ($r->agent?->rekening_holder ?: $r->agent?->name),
                    ])
                    ->form([
                        Forms\Components\DatePicker::make('tgl_bayar')->label('Tgl Pembayaran')->required(),
                        Forms\Components\TextInput::make('pph_amount')->label('PPh (Rp)')->numeric()->rupiah(),
                        Forms\Components\TextInput::make('ppn_amount')->label('PPn (Rp, jika ada)')->numeric()->rupiah()->default(0),
                        Forms\Components\TextInput::make('rek_bank')->label('Bank')->helperText('Link dari setup Sales/Agent.'),
                        Forms\Components\TextInput::make('rek_number')->label('No. Rekening'),
                        Forms\Components\TextInput::make('rek_holder')->label('Nama Terang'),
                    ])
                    ->action(function (Komisi $r, array $data) {
                        $net = max(0, (float) $r->amount - (float) ($data['pph_amount'] ?? 0) - (float) ($data['ppn_amount'] ?? 0));
                        $r->update([
                            'status' => 'PAID',
                            'tgl_bayar' => $data['tgl_bayar'],
                            'pph_amount' => $data['pph_amount'] ?? 0,
                            'ppn_amount' => $data['ppn_amount'] ?? 0,
                            'amount_net' => $net,
                            'rek_bank' => $data['rek_bank'] ?? null,
                            'rek_number' => $data['rek_number'] ?? null,
                            'rek_holder' => $data['rek_holder'] ?? null,
                        ]);
                        Notification::make()->success()->title('Komisi dicairkan — '.$r->no_komisi)
                            ->body('NETT Rp '.number_format($net, 0, ',', '.'))->send();
                    }),
            ])
            ->defaultSort('tgl_komisi', 'desc')->paginated([10, 25, 50]);
    }
}
