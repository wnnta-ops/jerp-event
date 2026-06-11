<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\Komisi\Services\KomisiCalculatorService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Generate Komisi — daftar kontrak SPR yang BELUM punya komisi; hitung preview komisi
 * (skema + biaya + PPh, dibulatkan ke atas) lalu generate jadi record Komisi.
 */
class GenerateKomisi extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'generate-komisi';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Generate Komisi';

    /** @return array<string,mixed>|null hasil hitung untuk satu kontrak (null bila tak ada skema/agent). */
    public function calcFor(Kontrak $k): ?array
    {
        if (! $k->agent_id) {
            return null;
        }
        $svc = app(KomisiCalculatorService::class);
        $skema = $svc->matchSkema($k);
        if (! $skema) {
            return null;
        }
        $rate = $svc->rateForAgent($skema, $k->agent?->sales_level_id);

        return ['skema' => $skema, 'rate' => $rate] + $svc->calculate($k, $skema, $rate);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Kontrak::query()->with(['unit', 'customer', 'agent'])
                    ->where('tahap', 'SPR')->where('status', 'AKTIF')->whereNotNull('agent_id')
                    ->whereNotIn('id', Komisi::query()->whereNotNull('kontrak_id')->pluck('kontrak_id'))
            )
            ->heading('Generate Komisi')
            ->description('Kontrak SPR yang belum punya komisi. Preview dihitung dari Skema Komisi + biaya (BPHTB/PPh), dibulatkan ke atas.')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()->limit(20),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->placeholder('—')
                    ->description(fn (Kontrak $r) => $r->agent?->is_arebi ? 'AREBI' : 'NON AREBI'),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('preview')->label('Komisi NETT (preview)')->alignEnd()
                    ->state(fn (Kontrak $r) => ($c = $this->calcFor($r))
                        ? 'Rp '.number_format($c['nett'], 0, ',', '.').' ('.rtrim(rtrim(number_format($c['rate'], 2), '0'), '.').'%)'
                        : '— belum ada skema/agent')
                    ->color(fn (Kontrak $r) => $this->calcFor($r) ? 'success' : 'danger'),
            ])
            ->actions([
                Tables\Actions\Action::make('generate')->label('Generate')->icon('heroicon-o-bolt')->color('primary')->button()
                    ->requiresConfirmation()
                    ->action(fn (Kontrak $r) => $this->generate($r)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('generate_bulk')->label('Generate Terpilih')->icon('heroicon-o-bolt')->color('primary')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $ok = 0;
                        foreach ($records as $r) {
                            if ($this->generate($r, silent: true)) {
                                $ok++;
                            }
                        }
                        Notification::make()->success()->title("$ok komisi di-generate")->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function generate(Kontrak $kontrak, bool $silent = false): bool
    {
        $c = $this->calcFor($kontrak);
        if (! $c) {
            if (! $silent) {
                Notification::make()->danger()->title('Gagal')->body('Tidak ada Skema Komisi cocok / kontrak tanpa agent.')->send();
            }

            return false;
        }

        Komisi::create([
            'tenant_id' => $kontrak->tenant_id ?? 1,
            'tgl_komisi' => now()->toDateString(),
            'agent_id' => $kontrak->agent_id,
            'kontrak_id' => $kontrak->id,
            'dpp' => $c['basis'],
            'persen_komisi' => $c['rate'],
            'amount' => $c['komisi'],
            'pph_persen' => $c['pph_persen'],
            'pph_amount' => $c['pph'],
            'amount_net' => $c['nett'],
            'status' => 'DRAFT',
            'keterangan' => 'Generate dari skema '.$c['skema']->kode,
        ]);

        if (! $silent) {
            Notification::make()->success()->title('Komisi dibuat')
                ->body('NETT Rp '.number_format($c['nett'], 0, ',', '.'))->send();
        }

        return true;
    }
}
