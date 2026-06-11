<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Blt;
use App\Modules\FO\Komisi\Services\BltCalculatorService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Generate BLT — kontrak SPR belum punya BLT; buat record BLT per level (Sales/Koord/Kantor). */
class GenerateBlt extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'generate-blt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Generate BLT';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Kontrak::query()->with(['unit', 'customer', 'agent'])
                    ->where('tahap', 'SPR')->where('status', 'AKTIF')
                    ->whereNotIn('id', Blt::query()->whereNotNull('kontrak_id')->pluck('kontrak_id'))
            )
            ->heading('Generate BLT')
            ->description('Kontrak SPR belum punya BLT. Generate membuat BLT per level (Sales/Koordinator/Kantor) dari Skema BLT, NETT = NILAI BLT − PPh (dibulatkan ke atas).')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->limit(20),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->placeholder('—'),
                Tables\Columns\TextColumn::make('preview')->label('Skema BLT')
                    ->state(fn (Kontrak $r) => ($s = app(BltCalculatorService::class)->matchSkema($r))
                        ? $s->nama.' ('.$s->lines->count().' level)'
                        : '— belum ada skema')
                    ->color(fn (Kontrak $r) => app(BltCalculatorService::class)->matchSkema($r) ? 'success' : 'danger'),
            ])
            ->actions([
                Tables\Actions\Action::make('generate')->label('Generate')->icon('heroicon-o-bolt')->color('primary')->button()
                    ->requiresConfirmation()->action(fn (Kontrak $r) => $this->generate($r)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('generate_bulk')->label('Generate Terpilih')->icon('heroicon-o-bolt')->color('primary')
                    ->requiresConfirmation()->action(function ($records) {
                        $n = 0;
                        foreach ($records as $r) {
                            $n += $this->generate($r, silent: true);
                        }
                        Notification::make()->success()->title("$n baris BLT di-generate")->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('tgl_kontrak', 'desc')->paginated([10, 25, 50]);
    }

    public function generate(Kontrak $kontrak, bool $silent = false): int
    {
        $svc = app(BltCalculatorService::class);
        $skema = $svc->matchSkema($kontrak);
        if (! $skema) {
            if (! $silent) {
                Notification::make()->danger()->title('Gagal')->body('Tidak ada Skema BLT cocok.')->send();
            }

            return 0;
        }

        $kontrak->loadMissing(['customer', 'agent']);
        $n = 0;
        foreach ($svc->generate($kontrak, $skema) as $row) {
            Blt::create([
                'tenant_id' => $kontrak->tenant_id ?? 1,
                'tgl_blt' => now()->toDateString(),
                'kontrak_id' => $kontrak->id,
                'agent_id' => $kontrak->agent_id,
                'sales_level_id' => $row['sales_level_id'],
                'level_type' => $row['level_type'],
                'nama_user' => $kontrak->customer?->full_name,
                'nilai_blt' => $row['nilai_blt'],
                'pph_jenis' => $row['pph_jenis'],
                'pph_persen' => $row['pph_persen'],
                'pph_amount' => $row['pph_amount'],
                'nett' => $row['nett'],
                'status' => 'DRAFT',
                'keterangan' => 'Generate dari skema '.$skema->kode,
            ]);
            $n++;
        }

        if (! $silent) {
            Notification::make()->success()->title("BLT dibuat — $n baris (kontrak {$kontrak->no_kontrak})")->send();
        }

        return $n;
    }
}
