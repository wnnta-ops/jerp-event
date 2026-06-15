<?php

namespace App\Filament\Fo\Resources\KontrakResource\Pages;

use App\Filament\Fo\Resources\KontrakResource;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditKontrak extends EditRecord
{
    protected static string $resource = KontrakResource::class;

    /**
     * SPR (final) wajib balance: total Tagihan + Biaya HARUS sama dengan Nilai
     * Kontrak (harga unit) — selisih 0. Bila tidak, batalkan simpan. SPRS (draft)
     * masih boleh belum pas karena sedang disusun.
     */
    protected function beforeSave(): void
    {
        if ($this->record->tahap !== 'SPR') {
            return;
        }

        $nilaiKontrak = (float) ($this->data['total_kontrak'] ?? $this->record->total_kontrak ?? 0);
        $calc = KontrakResource::hitungTagihan($this->data['tagihan'] ?? [], $nilaiKontrak);

        if (abs($calc['selisih']) >= 1) {
            $rp = fn (float $v) => 'Rp '.number_format($v, 0, ',', '.');
            Notification::make()->danger()->persistent()
                ->title('Tidak bisa simpan — tagihan tidak pas dengan Nilai Kontrak')
                ->body('Nilai Kontrak '.$rp($nilaiKontrak).' · Tagihan + Biaya '.$rp($calc['total'])
                    .' · Selisih '.$rp($calc['selisih']).'. Sesuaikan agar selisih 0.')
                ->send();
            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->regenAction(),
            $this->freeDpAction(),
            $this->cancelFreeDpAction(),

            // Finalisasi: simpan + promosikan SPRS → SPR (beda dari Simpan biasa).
            Actions\Action::make('saveAsSpr')->label('Simpan sebagai SPR')
                ->icon('heroicon-o-document-check')->color('success')
                ->visible(fn () => $this->record->tahap !== 'SPR')
                ->requiresConfirmation()
                ->modalDescription('Finalkan SPRS ini menjadi SPR. Setelah ini kontrak pindah ke menu SPR.')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->update(['tahap' => 'SPR']);
                    // Unit jadi TERJUAL hanya saat kontrak final (SPR); di SPRS masih BOOKING.
                    if ($this->record->unit_id && $unit = Unit::find($this->record->unit_id)) {
                        $unit->update(['status' => 'TERJUAL']);
                    }
                    Notification::make()->success()
                        ->title('Disimpan sebagai SPR')
                        ->body('Kontrak '.$this->record->no_kontrak.' kini SPR — unit ditandai TERJUAL.')
                        ->send();
                    $this->redirect(KontrakResource::getUrl('index'));
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /** Grup "Bentuk Ulang dari Master" — dipakai juga oleh edit SPR. */
    protected function regenAction(): Actions\ActionGroup
    {
        return Actions\ActionGroup::make([
            Actions\Action::make('genTagihan')->label('Jadwal Tagihan ← Skema Bayar')
                ->icon('heroicon-o-calendar-days')
                ->requiresConfirmation()
                ->modalDescription('Bentuk ulang Jadwal Tagihan dari Skema Bayar kontrak. Baris tagihan lama diganti.')
                ->action(fn () => $this->regen('tagihan')),
            Actions\Action::make('genKomisi')->label('Jadwal Komisi ← Skema Komisi')
                ->icon('heroicon-o-banknotes')
                ->requiresConfirmation()
                ->modalDescription('Bentuk ulang Jadwal Komisi dari Skema Komisi yang berlaku. Hanya baris berstatus DRAFT yang diganti.')
                ->action(fn () => $this->regen('komisi')),
            Actions\Action::make('genGimmick')->label('Gimmick ← Setup Gimmick')
                ->icon('heroicon-o-gift')
                ->requiresConfirmation()
                ->modalDescription('Bentuk ulang Gimmick dari master Setup Gimmick. Baris gimmick lama diganti.')
                ->action(fn () => $this->regen('gimmick')),
        ])->label('Bentuk Ulang dari Master')->icon('heroicon-o-sparkles')->button();
    }

    /**
     * Free DP: bentuk ulang Jadwal Tagihan dari Skema Bayar (restore DP asli), lalu
     * DP yang jatuh temponya > Tgl Berkas Lengkap → Rp 0 (FREE). Penyeimbang: baris
     * "SUBSIDI FREE DP" = total yang di-free, agar Total Tagihan tetap = Nilai Kontrak
     * (selisih 0) dan Pencairan KPR tidak berubah.
     */
    protected function applyFreeDp(Carbon $tglBerkas): void
    {
        // Restore jadwal dari skema (idempotent — DP kembali ke nilai asli DP3X).
        app(KontrakGeneratorService::class)->tagihan($this->record);
        $this->record->refresh();

        $dp = $this->record->tagihan()->where('tipe', 'DP')->where('nilai', '>', 0)
            ->orderBy('urutan')->orderBy('jatuh_tempo')->get();
        // DP 1 selalu dibayar (tidak pernah di-free). Free DP ke-2 dst yang jatuh
        // temponya SETELAH tanggal berkas lengkap (sebelum DP2 → DP2&3; setelah DP2 → DP3).
        $freed = $dp->skip(1)->filter(fn ($t) => $t->jatuh_tempo?->gt($tglBerkas))->values();

        if ($freed->isEmpty()) {
            Notification::make()->warning()->title('Tidak ada DP yang di-free')
                ->body('Semua DP sudah jatuh tempo s/d '.$tglBerkas->format('d M Y').', atau skema tidak memiliki DP.')
                ->send();

            return;
        }

        $freedSum = 0.0;
        foreach ($freed as $t) {
            $freedSum += (float) $t->nilai;
            $t->update([
                'nilai' => 0,
                'nama_tagihan' => $t->nama_tagihan.' (FREE)',
                'keterangan' => 'Free DP — berkas lengkap '.$tglBerkas->format('d M Y'),
            ]);
        }

        // Penyeimbang subsidi (total tetap = Nilai Kontrak; Pencairan KPR tidak berubah).
        $this->record->tagihan()->create([
            'urutan' => 900,
            'tipe' => 'LAIN',
            'nama_tagihan' => 'SUBSIDI FREE DP',
            'jatuh_tempo' => $tglBerkas,
            'nilai' => $freedSum,
            'pelunasan' => 0,
            'keterangan' => 'Subsidi developer atas '.$freed->count().' DP yang di-free',
        ]);

        Notification::make()->success()
            ->title($freed->count().' DP di-free (Rp '.number_format($freedSum, 0, ',', '.').')')
            ->body('Pencairan KPR tetap; selisih menjadi subsidi developer. Klik Simpan untuk finalisasi.')
            ->send();

        // Reload halaman penuh agar repeater Jadwal Tagihan bersih (hindari item kosong
        // sisa state Livewire akibat baris tagihan diubah di luar siklus form).
        $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
    }

    /** Tombol FREE DP (KPR DP3X) — dipakai di edit SPRS & SPR. */
    protected function freeDpAction(): Actions\Action
    {
        return Actions\Action::make('freeDp')->label('Free DP')->icon('heroicon-o-gift')->color('warning')
            ->visible(fn () => strtoupper((string) $this->record->cara_bayar) === 'KPR')
            ->modalHeading('Free DP (KPR DP3X)')
            ->modalDescription('DP 1 selalu dibayar. DP ke-2 dst yang jatuh temponya SETELAH tanggal berkas lengkap di-free (Rp 0). Pencairan KPR tetap; selisihnya menjadi subsidi developer.')
            ->modalSubmitActionLabel('Terapkan Free DP')
            ->form([
                DatePicker::make('tgl_berkas')->label('Tgl Berkas Lengkap')->default(now())->required()
                    ->helperText('DP 1 tetap dibayar; DP ke-2 dst yang jatuh tempo setelah tanggal ini akan di-free.'),
            ])
            ->action(fn (array $data) => $this->applyFreeDp(Carbon::parse($data['tgl_berkas'])));
    }

    /** Batalkan Free DP — kembalikan Jadwal Tagihan ke Skema Bayar (DP dipulihkan, baris Subsidi dihapus). */
    protected function cancelFreeDpAction(): Actions\Action
    {
        return Actions\Action::make('cancelFreeDp')->label('Batal Free DP')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->visible(fn () => strtoupper((string) $this->record->cara_bayar) === 'KPR'
                && $this->record->tagihan()->where('nama_tagihan', 'like', '%FREE%')->exists())
            ->requiresConfirmation()
            ->modalHeading('Batalkan Free DP')
            ->modalDescription('Jadwal Tagihan dikembalikan ke Skema Bayar (DP3X): DP yang di-free dipulihkan & baris "SUBSIDI FREE DP" dihapus.')
            ->action(function () {
                app(KontrakGeneratorService::class)->tagihan($this->record);
                Notification::make()->success()->title('Free DP dibatalkan')
                    ->body('Jadwal Tagihan dikembalikan ke Skema Bayar.')->send();
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    /** Generate ulang satu tab dari masternya, lalu muat ulang form. */
    protected function regen(string $which): void
    {
        $svc = app(KontrakGeneratorService::class);
        $n = match ($which) {
            'tagihan' => $svc->tagihan($this->record),
            'komisi' => $svc->komisi($this->record),
            'gimmick' => $svc->gimmick($this->record),
            default => 0,
        };

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->title($n > 0 ? "Berhasil membentuk {$n} baris." : 'Tidak ada data master yang cocok.')
            ->{$n > 0 ? 'success' : 'warning'}()
            ->body($which === 'komisi' && $n === 0 ? 'Pastikan Skema Komisi & Agent kontrak sudah di-set.' : null)
            ->send();
    }
}
