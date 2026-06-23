<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\Komisi\Services\KomisiCalculatorService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Pengajuan Komisi — komisi DRAFT (hasil Generate Komisi). Edit bila perlu, lalu Ajukan
 * ke Approval (DRAFT → PENDING, 1 batch No. Pengajuan). Setelah disetujui di Approval
 * Komisi → PAID → masuk Realisasi Komisi (siap cetak).
 */
class PengajuanKomisi extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'pengajuan-komisi';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Pengajuan Komisi';

    public function table(Table $table): Table
    {
        return $table
            ->query(Komisi::query()->with(['agent', 'kontrak.customer'])->where('status', 'DRAFT'))
            ->heading('Pengajuan Komisi')
            ->description('Komisi DRAFT — edit bila perlu, lalu Ajukan ke Approval (1 batch). Setelah disetujui → masuk Realisasi Komisi.')
            ->columns([
                Tables\Columns\TextColumn::make('no_komisi')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('kontrak.customer.full_name')->label('Pembeli')->placeholder('—')->searchable()->limit(20),
                Tables\Columns\TextColumn::make('agent.name')->label('Sales')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('Komisi')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('pph_amount')->label('PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('amount_net')->label('NETT')->money('IDR')->alignEnd()->weight('bold'),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')->label('Edit')->icon('heroicon-o-pencil-square')->color('gray')->link()
                    ->modalHeading(fn (Komisi $r) => 'Edit Komisi — '.$r->no_komisi)
                    ->fillForm(fn (Komisi $r) => [
                        'amount' => (float) $r->amount,
                        'pph_amount' => (float) $r->pph_amount,
                        'ppn_amount' => (float) $r->ppn_amount,
                        'rek_bank' => $r->rek_bank ?: $r->agent?->rekening_bank,
                        'rek_number' => $r->rek_number ?: $r->agent?->rekening_number,
                        'rek_holder' => $r->rek_holder ?: ($r->agent?->rekening_holder ?: $r->agent?->name),
                    ])
                    ->form([
                        Forms\Components\TextInput::make('amount')->label('Komisi (Rp)')->numeric()->rupiah()->required(),
                        Forms\Components\TextInput::make('pph_amount')->label('PPh (Rp)')->numeric()->rupiah()->default(0),
                        Forms\Components\TextInput::make('ppn_amount')->label('PPn (Rp)')->numeric()->rupiah()->default(0),
                        Forms\Components\TextInput::make('rek_bank')->label('Bank'),
                        Forms\Components\TextInput::make('rek_number')->label('No. Rekening'),
                        Forms\Components\TextInput::make('rek_holder')->label('Nama Terang'),
                    ])
                    ->action(function (Komisi $r, array $data) {
                        $r->update([
                            'amount' => $data['amount'] ?? 0,
                            'pph_amount' => $data['pph_amount'] ?? 0,
                            'ppn_amount' => $data['ppn_amount'] ?? 0,
                            'amount_net' => self::hitungNett($data),
                            'rek_bank' => $data['rek_bank'] ?? null,
                            'rek_number' => $data['rek_number'] ?? null,
                            'rek_holder' => $data['rek_holder'] ?? null,
                        ]);
                        Notification::make()->success()->title('Komisi diperbarui — '.$r->no_komisi)->send();
                    }),
                Tables\Actions\Action::make('recalc')->label('Hitung Ulang')->icon('heroicon-o-calculator')->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Hitung Ulang Komisi')
                    ->modalDescription(fn (Komisi $r) => 'Hitung ulang Komisi & PPh '.$r->no_komisi.' dari Skema Komisi terbaru (memperbaiki PPh yang masih 0).')
                    ->action(function (Komisi $r) {
                        if (self::recalcKomisi($r)) {
                            $r->refresh();
                            $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
                            Notification::make()->success()->title('Dihitung ulang — '.$r->no_komisi)
                                ->body('Komisi '.$rp($r->amount).' · PPh '.$rp($r->pph_amount).' ('.rtrim(rtrim(number_format((float) $r->pph_persen, 2), '0'), '.').'%) · NETT '.$rp($r->amount_net))->send();
                        } else {
                            Notification::make()->danger()->title('Gagal hitung ulang')->body('Tak ada Skema Komisi cocok / kontrak tanpa agent.')->send();
                        }
                    }),
                Tables\Actions\Action::make('ajukan')->label('Ajukan ke Approval')->icon('heroicon-o-paper-airplane')->color('warning')->button()
                    ->requiresConfirmation()
                    ->modalHeading('Ajukan ke Approval')
                    ->modalDescription(fn (Komisi $r) => 'Komisi '.$r->no_komisi.' diajukan ke Approval Komisi (menunggu persetujuan).')
                    ->action(function (Komisi $r) {
                        $no = self::nextPengajuan();
                        $r->update(['status' => 'PENDING', 'no_pengajuan' => $no]);
                        self::buatApproval($no, collect([$r]));
                        Notification::make()->success()->title('Diajukan ke Approval — '.$no)
                            ->body('Menunggu persetujuan di menu Approval → Approval Komisi.')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('recalc_bulk')->label('Hitung Ulang Terpilih')->icon('heroicon-o-calculator')->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Hitung ulang Komisi & PPh terpilih dari Skema Komisi terbaru (memperbaiki PPh 0).')
                    ->action(function ($records) {
                        $ok = 0;
                        foreach ($records as $r) {
                            if (self::recalcKomisi($r)) {
                                $ok++;
                            }
                        }
                        Notification::make()->success()->title("$ok komisi dihitung ulang")->send();
                    })->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('ajukan_bulk')->label('Ajukan Terpilih')->icon('heroicon-o-paper-airplane')->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Komisi terpilih dijadikan SATU pengajuan (batch) dan diajukan ke Approval Komisi.')
                    ->action(function ($records) {
                        $no = self::nextPengajuan();
                        $records->each(fn (Komisi $r) => $r->update(['status' => 'PENDING', 'no_pengajuan' => $no]));
                        self::buatApproval($no, $records);
                        Notification::make()->success()->title(count($records).' Komisi diajukan — '.$no)
                            ->body('Menunggu persetujuan di menu Approval → Approval Komisi.')->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('tgl_komisi', 'desc')->paginated([10, 25, 50]);
    }

    /**
     * Hitung ulang Komisi dari Skema Komisi terkini (rate per level/AREBI + PPh 23).
     * Memperbaiki data lama yang PPh-nya 0. Mengembalikan false bila tak ada skema/agent.
     */
    protected static function recalcKomisi(Komisi $r): bool
    {
        $kontrak = $r->kontrak;
        $kontrak?->loadMissing(['agent', 'unit']);
        if (! $kontrak || ! $kontrak->agent_id) {
            return false;
        }
        $svc = app(KomisiCalculatorService::class);
        $skema = $svc->matchSkema($kontrak);
        if (! $skema) {
            return false;
        }
        $rate = $svc->rateForAgent($skema, $kontrak->agent?->sales_level_id);
        $c = $svc->calculate($kontrak, $skema, $rate);
        $r->update([
            'dpp' => $c['basis'],
            'persen_komisi' => $c['rate'],
            'amount' => $c['komisi'],
            'pph_persen' => $c['pph_persen'],
            'pph_amount' => $c['pph'],
            'amount_net' => $c['nett'],
        ]);

        return true;
    }

    /** NETT = Komisi − PPh − PPn (tak pernah negatif). */
    protected static function hitungNett(array $data): float
    {
        return max(0, (float) ($data['amount'] ?? 0) - (float) ($data['pph_amount'] ?? 0) - (float) ($data['ppn_amount'] ?? 0));
    }

    /** No. Pengajuan (batch) baru — atomic via NumeratorService. */
    protected static function nextPengajuan(): string
    {
        return app(\App\Services\Numerator\NumeratorService::class)->next('KOMPENG');
    }

    /** Buat record Approval (jenis KOMISI) untuk batch ini lalu submit (→ PENDING L1). */
    protected static function buatApproval(string $noPengajuan, \Illuminate\Support\Collection $records): void
    {
        $totalNett = (float) $records->sum(fn (Komisi $r) => (float) $r->amount_net);
        $approval = \App\Modules\FO\Approval\Models\Approval::create([
            'tgl_approval' => now(),
            'jenis' => 'KOMISI',
            'amount_dampak' => $totalNett,
            'alasan' => 'Pengajuan Komisi '.$noPengajuan.' — '.$records->count().' komisi',
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
