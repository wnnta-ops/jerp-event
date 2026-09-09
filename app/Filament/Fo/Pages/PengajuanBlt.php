<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Komisi\Models\Blt;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Pengajuan BLT — BLT DRAFT (hasil checklist di Generate BLT). Edit bila perlu (NETT
 * otomatis), lalu Ajukan ke Approval (DRAFT → PENDING, dijadikan 1 batch No. Pengajuan).
 * Setelah disetujui di Approval BLT → PAID → masuk Realisasi BLT (siap cetak).
 */
class PengajuanBlt extends Page implements HasTable
{
    use MematuhiIzinHalaman;

    use InteractsWithTable;

    protected static ?string $slug = 'pengajuan-blt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Pengajuan BLT';

    public function table(Table $table): Table
    {
        return $table
            ->query(Blt::query()->with(['agent', 'kontrak.customer', 'kontrak.unit'])->where('status', 'DRAFT'))
            ->heading('Pengajuan BLT')
            ->description('BLT DRAFT — edit bila perlu (NETT otomatis), lalu Ajukan ke Approval (1 batch). Setelah disetujui → masuk Realisasi BLT.')
            ->columns([
                Tables\Columns\TextColumn::make('no_blt')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('level_type')->label('Type')->badge()->color(fn (?string $state) => match ($state) {
                    'SALES' => 'success', 'KOORDINATOR' => 'warning', 'KANTOR' => 'info', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('kontrak.customer.full_name')->label('Pembeli')->placeholder('—')->searchable()->limit(20),
                Tables\Columns\TextColumn::make('kontrak.unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('nama_user')->label('Penerima')->placeholder('—')->limit(20),
                Tables\Columns\TextColumn::make('nilai_blt')->label('Nilai BLT')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('pph_amount')->label('PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('nett')->label('NETT')->money('IDR')->alignEnd()->weight('bold'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level_type')->label('Type')
                    ->options(['SALES' => 'Sales', 'KOORDINATOR' => 'Koordinator', 'KANTOR' => 'Kantor']),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')->label('Edit')->icon('heroicon-o-pencil-square')->color('gray')->link()
                    ->modalHeading(fn (Blt $r) => 'Edit BLT — '.$r->no_blt)
                    ->fillForm(fn (Blt $r) => [
                        'nilai_blt' => (float) $r->nilai_blt,
                        'pph_amount' => (float) $r->pph_amount,
                        'ppn_amount' => (float) $r->ppn_amount,
                        'rek_bank' => $r->rek_bank ?: $r->agent?->rekening_bank,
                        'rek_number' => $r->rek_number ?: $r->agent?->rekening_number,
                        'rek_holder' => $r->rek_holder ?: ($r->agent?->rekening_holder ?: $r->nama_user),
                    ])
                    ->form([
                        Forms\Components\TextInput::make('nilai_blt')->label('Nilai BLT (Rp)')->numeric()->rupiah()->required(),
                        Forms\Components\TextInput::make('pph_amount')->label('PPh (Rp)')->numeric()->rupiah()->default(0),
                        Forms\Components\TextInput::make('ppn_amount')->label('PPn (Rp)')->numeric()->rupiah()->default(0),
                        Forms\Components\TextInput::make('rek_bank')->label('Bank'),
                        Forms\Components\TextInput::make('rek_number')->label('No. Rekening'),
                        Forms\Components\TextInput::make('rek_holder')->label('Nama Terang'),
                    ])
                    ->action(function (Blt $r, array $data) {
                        $r->update([
                            'nilai_blt' => $data['nilai_blt'] ?? 0,
                            'pph_amount' => $data['pph_amount'] ?? 0,
                            'ppn_amount' => $data['ppn_amount'] ?? 0,
                            'nett' => self::hitungNett($data),
                            'rek_bank' => $data['rek_bank'] ?? null,
                            'rek_number' => $data['rek_number'] ?? null,
                            'rek_holder' => $data['rek_holder'] ?? null,
                        ]);
                        Notification::make()->success()->title('BLT diperbarui — '.$r->no_blt)->send();
                    }),
                Tables\Actions\Action::make('ajukan')->label('Ajukan ke Approval')->icon('heroicon-o-paper-airplane')->color('warning')->button()
                    ->requiresConfirmation()
                    ->modalHeading('Ajukan ke Approval')
                    ->modalDescription(fn (Blt $r) => 'BLT '.$r->no_blt.' diajukan ke Approval BLT (menunggu persetujuan).')
                    ->action(function (Blt $r) {
                        $no = self::nextPengajuan();
                        $r->update(['status' => 'PENDING', 'no_pengajuan' => $no]);
                        self::buatApproval($no, collect([$r]));
                        Notification::make()->success()->title('Diajukan ke Approval — '.$no)
                            ->body('Menunggu persetujuan di menu Approval → Approval BLT.')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('ajukan_bulk')->label('Ajukan Terpilih')->icon('heroicon-o-paper-airplane')->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('BLT terpilih dijadikan SATU pengajuan (batch) dan diajukan ke Approval BLT.')
                    ->action(function ($records) {
                        $no = self::nextPengajuan();
                        $records->each(fn (Blt $r) => $r->update(['status' => 'PENDING', 'no_pengajuan' => $no]));
                        self::buatApproval($no, $records);
                        Notification::make()->success()->title(count($records).' BLT diajukan — '.$no)
                            ->body('Menunggu persetujuan di menu Approval → Approval BLT.')->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('tgl_blt', 'desc')->paginated([10, 25, 50]);
    }

    /** NETT = Nilai BLT − PPh − PPn (tak pernah negatif). */
    protected static function hitungNett(array $data): float
    {
        return max(0, (float) ($data['nilai_blt'] ?? 0) - (float) ($data['pph_amount'] ?? 0) - (float) ($data['ppn_amount'] ?? 0));
    }

    /** No. Pengajuan (batch) baru — atomic via NumeratorService. */
    protected static function nextPengajuan(): string
    {
        return app(\App\Services\Numerator\NumeratorService::class)->next('BLTPENG');
    }

    /** Buat record Approval (jenis BLT) untuk batch ini lalu submit (→ PENDING L1). */
    protected static function buatApproval(string $noPengajuan, \Illuminate\Support\Collection $records): void
    {
        $totalNett = (float) $records->sum(fn (Blt $b) => (float) $b->nett);
        $approval = \App\Modules\FO\Approval\Models\Approval::create([
            'tgl_approval' => now(),
            'jenis' => 'BLT',
            'amount_dampak' => $totalNett,
            'alasan' => 'Pengajuan BLT '.$noPengajuan.' — '.$records->count().' BLT',
            'detail_baru' => [
                'no_pengajuan' => $noPengajuan,
                'jumlah' => $records->count(),
                'total_nett' => $totalNett,
            ],
            'status' => 'DRAFT',
            'requested_by' => auth()->id(),
        ]);
        app(\App\Modules\FO\Approval\Services\ApprovalService::class)->submit($approval);
    }
}
