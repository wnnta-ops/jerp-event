<?php

namespace App\Filament\Fo\Resources\KontrakResource\Pages;

use App\Filament\Fo\Resources\KontrakResource;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use App\Support\Rupiah;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditKontrak extends EditRecord
{
    protected static string $resource = KontrakResource::class;

    /** Skema Bayar sebelum disimpan — untuk deteksi "ganti skema" di afterSave. */
    private ?int $oldSkemaId = null;

    /**
     * SPR (final) wajib balance: total Tagihan + Biaya HARUS sama dengan Nilai
     * Kontrak (harga unit) — selisih 0. Bila tidak, batalkan simpan. SPRS (draft)
     * masih boleh belum pas karena sedang disusun.
     */
    protected function beforeSave(): void
    {
        // Catat skema lama (record belum diupdate) untuk deteksi ganti skema.
        $this->oldSkemaId = (int) $this->record->skema_id;

        if ($this->record->tahap !== 'SPR') {
            return;
        }

        // KPR: Pencairan KPR auto-menyesuaikan (termasuk Tambah DP) → selisih efektif 0,
        // tak perlu dihalangi. Cek selisih hanya untuk non-KPR.
        if (strtoupper((string) ($this->data['cara_bayar'] ?? $this->record->cara_bayar)) === 'KPR') {
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

    /** GMC (gimmick FREE) selalu mengikuti gimmick kontrak — sinkronkan setelah simpan. */
    protected function afterSave(): void
    {
        // Ganti Skema Bayar → termin di-reset ke master skema baru + Jadwal Tagihan
        // DI-GENERATE ULANG (tergantikan, bukan ditambah termin). Juga heal bila Jadwal
        // riil kosong. Lalu reload.
        $skemaGanti = $this->oldSkemaId !== null && (int) $this->record->skema_id !== $this->oldSkemaId;
        $kosong = ! $this->record->tagihan()->exists();
        if ($skemaGanti || $kosong) {
            app(KontrakGeneratorService::class)->tagihan($this->record);
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));

            return;
        }

        // Pencairan KPR otomatis = Harga − semua tagihan lain (termasuk Tambah DP) di DB.
        // Reload form bila Pencairan disesuaikan agar state repeater Livewire tidak basi.
        if ($this->recomputePencairanKprDb()) {
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
        }
    }

    /**
     * Hitung ulang Pencairan KPR di DB = Harga − Σ tagihan lain non-GMC (termasuk
     * Tambah DP). Mengembalikan true bila nilainya berubah. Hanya kontrak KPR.
     */
    private function recomputePencairanKprDb(): bool
    {
        if (strtoupper((string) $this->record->cara_bayar) !== 'KPR') {
            return false;
        }
        $tagihan = $this->record->tagihan()->get();
        $kpr = $tagihan->first(fn ($t) => $t->tipe === 'ANG' && str_contains(strtoupper((string) $t->nama_tagihan), 'PENCAIRAN'))
            ?? $tagihan->first(fn ($t) => $t->tipe === 'ANG');
        if (! $kpr) {
            return false;
        }
        $harga = (float) $this->record->total_kontrak;
        $lain = $tagihan->reject(fn ($t) => $t->id === $kpr->id || $t->tipe === 'GMC')
            ->sum(fn ($t) => (float) $t->nilai);
        $baru = max(0, round($harga - $lain));

        if (abs((float) $kpr->nilai - $baru) >= 1) {
            $kpr->update(['nilai' => $baru]);

            return true;
        }

        return false;
    }

    /**
     * Tombol "Tambah DP" — DP tambahan dari customer yang OTOMATIS mengurangi
     * Pencairan KPR. Membuat baris tipe TDP + hitung ulang Pencairan + reload.
     */
    protected function tambahDpAction(): Actions\Action
    {
        return Actions\Action::make('tambahDp')->label('Tambah DP')->icon('heroicon-o-plus-circle')->color('warning')
            ->visible(fn () => strtoupper((string) $this->record->cara_bayar) === 'KPR')
            ->modalHeading('Tambah DP (kurangi Pencairan KPR)')
            ->modalDescription('DP tambahan dari customer. Pencairan KPR otomatis berkurang sebesar nominal ini, total tetap = harga unit.')
            ->modalSubmitActionLabel('Tambahkan')
            ->form([
                \Filament\Forms\Components\TextInput::make('nominal')->label('Nominal Tambah DP')->numeric()->rupiah()->required()->minValue(1),
                DatePicker::make('jatuh_tempo')->label('Jatuh Tempo')->default(now()),
            ])
            ->action(function (array $data) {
                $nominal = (float) \App\Support\Rupiah::toFloat($data['nominal'] ?? 0);
                if ($nominal <= 0) {
                    return;
                }
                $urut = (int) ($this->record->tagihan()->max('urutan') ?? 0) + 1;
                $this->record->tagihan()->create([
                    'urutan' => max(60, $urut),
                    'tipe' => 'TDP',
                    'nama_tagihan' => 'TAMBAH DP',
                    'jatuh_tempo' => $data['jatuh_tempo'] ?? now(),
                    'nilai' => $nominal,
                    'pelunasan' => 0,
                ]);
                $this->recomputePencairanKprDb();
                Notification::make()->success()->title('Tambah DP Rp '.number_format($nominal, 0, ',', '.'))
                    ->body('Pencairan KPR otomatis dikurangi.')->send();
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    /** Tombol "Batal Tambah DP" — hapus semua baris Tambah DP & kembalikan Pencairan KPR. */
    protected function cancelTambahDpAction(): Actions\Action
    {
        return Actions\Action::make('cancelTambahDp')->label('Batal Tambah DP')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->visible(fn () => strtoupper((string) $this->record->cara_bayar) === 'KPR'
                && $this->record->tagihan()->where('tipe', 'TDP')->exists())
            ->requiresConfirmation()
            ->modalHeading('Batalkan Tambah DP')
            ->modalDescription('Semua baris Tambah DP dihapus & Pencairan KPR dikembalikan.')
            ->action(function () {
                $this->record->tagihan()->where('tipe', 'TDP')->delete();
                $this->recomputePencairanKprDb();
                Notification::make()->success()->title('Tambah DP dibatalkan')
                    ->body('Pencairan KPR dikembalikan.')->send();
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    /**
     * Pengajuan Diskon dari tab Diskon. Diskon mengurangi harga unit → Nilai Kontrak.
     * Keduanya lewat Approval, diterapkan otomatis (applyDiskon: set diskon/total/ppn +
     * bentuk ulang Jadwal Tagihan) setelah disetujui:
     *   - SPR (final): jenis DISKON — 2 level (L1 → L2/GM).
     *   - SPRS (draft): jenis DISKON_SPRS — single-level langsung GM.
     */
    public function submitDiskon(array $data): void
    {
        $k = $this->record;
        $diskon = Rupiah::toFloat($data['diskon_baru'] ?? 0);
        $gross = (float) $k->harga_jual;
        $total = max(0, $gross - $diskon);
        $alasan = trim((string) ($data['alasan'] ?? ''));
        $rp = fn (float $v) => 'Rp '.number_format($v, 0, ',', '.');

        $isSpr = $k->tahap === 'SPR';
        $jenis = $isSpr ? 'DISKON' : 'DISKON_SPRS';

        $approval = Approval::create([
            'tgl_approval' => now(),
            'jenis' => $jenis,
            'subject_type' => Kontrak::class,
            'subject_id' => $k->id,
            'amount_dampak' => $total - (float) $k->total_kontrak,
            'alasan' => 'Diskon '.($isSpr ? 'SPR' : 'SPRS').' '.$k->no_kontrak.($alasan !== '' ? ' — '.$alasan : ''),
            'detail_lama' => ['diskon' => (float) $k->diskon, 'nilai_kontrak' => (float) $k->total_kontrak],
            'detail_baru' => ['nilai_diskon' => $diskon, 'nilai_kontrak_baru' => $total],
            'status' => 'DRAFT',
            'requested_by' => auth()->id(),
        ]);
        app(ApprovalService::class)->submit($approval);

        $alur = $isSpr ? 'menunggu persetujuan (2 level)' : 'menunggu persetujuan GM (single-level)';
        Notification::make()->success()
            ->title('Diajukan ke Approval Diskon '.($isSpr ? 'SPR' : 'SPRS').' — '.$approval->no_approval)
            ->body('Diskon '.$rp($diskon).' → Nilai Kontrak '.$rp($total).' '.$alur.'. Diterapkan otomatis setelah disetujui.')
            ->persistent()->send();
    }

    /**
     * Koreksi Sales/Agent kontrak dari tab Sales (mis. salah input dari awal).
     * Komisi yang BELUM cair (bukan PAID/BATAL) milik sales lama ikut dipindah ke
     * sales baru; komisi yang sudah PAID tidak diubah.
     */
    public function submitSales(array $data): void
    {
        $k = $this->record;
        $newAgent = (int) ($data['agent_id'] ?? 0);
        $oldAgent = (int) $k->agent_id;
        if (! $newAgent) {
            return;
        }
        if ($newAgent === $oldAgent) {
            Notification::make()->info()->title('Sales tidak berubah')->send();

            return;
        }

        $oldName = $k->agent?->name;
        $k->update(['agent_id' => $newAgent]);

        // Pindahkan komisi yang belum cair dari sales lama → sales baru.
        $moved = $k->komisi()->where('agent_id', $oldAgent)
            ->whereNotIn('status', ['PAID', 'BATAL'])->update(['agent_id' => $newAgent]);

        $newName = \App\Modules\FO\Komisi\Models\Agent::find($newAgent)?->name;
        Notification::make()->success()
            ->title('Sales kontrak diperbarui')
            ->body('Sales '.$k->no_kontrak.' diubah'.($oldName ? ' dari '.$oldName : '').' menjadi '.$newName.'.'
                .($moved ? ' '.$moved.' baris komisi (belum cair) ikut dipindah.' : ''))
            ->send();

        // Pakai resource halaman saat ini (SPRS=KontrakResource / SPR=SprResource) agar
        // redirect tidak nyasar ke menu yang men-scope tahap lain (404).
        $this->redirect($this->getResource()::getUrl('edit', ['record' => $k]));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->freeDpAction(),
            $this->cancelFreeDpAction(),
            $this->cancelSubsidiAction(),
            $this->tambahDpAction(),
            $this->cancelTambahDpAction(),

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
                    // Unit terjual → Faktur Penjualan otomatis terbit (idempotent).
                    \App\Modules\FO\FinanceAR\Services\FakturPenjualanService::ensureForKontrak($this->record);
                    Notification::make()->success()
                        ->title('Disimpan sebagai SPR')
                        ->body('Kontrak '.$this->record->no_kontrak.' kini SPR — unit TERJUAL & Faktur Penjualan terbit.')
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

    /** Skema kontrak ini = KPR FREE DP (mode ANGSURAN1, auto free DP)? */
    private function isFreeDpScheme(): bool
    {
        return ($this->record->skema?->bf_dipotong_di ?? '') === 'ANGSURAN1';
    }

    /**
     * Free DP — ganti skema ke KPR FREE DP lalu generate ulang. DP yang jatuh temponya
     * SETELAH Tgl Berkas Lengkap menjadi FREE (UANG MASUK); Tgl Berkas Lengkap ditentukan
     * dari modul Collection (saat null = berkas lengkap di awal → SEMUA DP free).
     * Tampil untuk KPR non-FREE-DP (mis. DP3X).
     */
    protected function freeDpAction(): Actions\Action
    {
        return Actions\Action::make('freeDp')->label('Free DP')->icon('heroicon-o-gift')->color('warning')
            ->visible(fn () => strtoupper((string) $this->record->cara_bayar) === 'KPR'
                && ($this->record->skema?->bf_dipotong_di ?? '') === 'DP_DISEBAR')
            ->requiresConfirmation()
            ->modalHeading('Terapkan Free DP')
            ->modalDescription('Skema diganti ke KPR FREE DP. DP yang jatuh temponya setelah Tgl Berkas Lengkap jadi FREE (UANG MASUK). Tgl Berkas Lengkap diatur dari modul Collection; bila belum diisi → semua DP free.')
            ->action(function () {
                $free = \App\Modules\FO\Adminjual\Models\Skema::query()
                    ->where('name', 'KPR FREE DP')->orWhere('code', 'KPR-FREE-DP')->first();
                if (! $free) {
                    Notification::make()->danger()->title('Skema KPR FREE DP tidak ditemukan')->send();

                    return;
                }
                $this->record->update(['skema_id' => $free->id]);
                app(KontrakGeneratorService::class)->tagihan($this->record);
                Notification::make()->success()->title('Free DP diterapkan → KPR FREE DP')->send();
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    /**
     * Batal Free DP (khusus skema KPR FREE DP) — ganti skema ke KPR DP 3X lalu
     * Jadwal Tagihan di-generate ulang (DP kembali dibayar + subsidi 20%).
     */
    protected function cancelFreeDpAction(): Actions\Action
    {
        return Actions\Action::make('cancelFreeDp')->label('Batal Free DP')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->visible(fn () => strtoupper((string) $this->record->cara_bayar) === 'KPR' && $this->isFreeDpScheme())
            ->requiresConfirmation()
            ->modalHeading('Batalkan Free DP')
            ->modalDescription('Skema Bayar diganti ke KPR DP 3X & Jadwal Tagihan dihitung ulang (DP kembali dibayar customer + subsidi 20%).')
            ->action(function () {
                $dp3x = \App\Modules\FO\Adminjual\Models\Skema::query()
                    ->where('name', 'KPR DP 3X')->orWhere('code', 'KPR-DP-3X')->first();
                if (! $dp3x) {
                    Notification::make()->danger()->title('Skema KPR DP 3X tidak ditemukan')->send();

                    return;
                }
                $this->record->update(['skema_id' => $dp3x->id, 'tgl_berkas_lengkap' => null]);
                app(KontrakGeneratorService::class)->tagihan($this->record);
                Notification::make()->success()->title('Free DP dibatalkan → KPR DP 3X')->send();
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    /**
     * Cancel Subsidi (KPR DP3X) — hitung ulang Jadwal Tagihan TANPA subsidi 20%
     * developer: DP kembali penuh & baris "UANG MASUK" dihapus. Untuk mengembalikan
     * subsidi, gunakan "Generate ulang Jadwal Tagihan".
     */
    protected function cancelSubsidiAction(): Actions\Action
    {
        return Actions\Action::make('cancelSubsidi')->label('Cancel Subsidi')->icon('heroicon-o-x-mark')->color('gray')
            // Hanya untuk DP3X (DP_DISEBAR). UANG MASUK milik FREE DP (ANGSURAN1) dikelola via Batal Free DP.
            ->visible(fn () => ($this->record->skema?->bf_dipotong_di ?? '') === 'DP_DISEBAR'
                && $this->record->tagihan()->where('tipe', 'ADM')->where('nama_tagihan', 'UANG MASUK')->exists())
            ->requiresConfirmation()
            ->modalHeading('Cancel Subsidi (Free 20% Developer)')
            ->modalDescription('Jadwal Tagihan dihitung ulang TANPA subsidi 20% developer (DP kembali penuh, baris "UANG MASUK" dihapus). Untuk mengembalikan, klik "Generate ulang Jadwal Tagihan".')
            ->action(function () {
                app(KontrakGeneratorService::class)->tagihan($this->record, true, false);
                Notification::make()->success()->title('Subsidi 20% dibatalkan')
                    ->body('Jadwal Tagihan dihitung tanpa subsidi developer.')->send();
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    /** Generate ulang satu tab dari masternya, lalu muat ulang form. */
    protected function regen(string $which): void
    {
        // Jadwal Tagihan dibentuk dari Skema Bayar yang SEDANG dipilih di form —
        // persist skema (+termin master skema baru) dulu agar tidak memakai skema lama.
        if ($which === 'tagihan') {
            $this->persistSkemaPilihan();
        }

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

    /**
     * Persist Skema Bayar yang dipilih di form ke record sebelum generate Jadwal
     * Tagihan — agar generator memakai master skema BARU (termin selalu dari master).
     */
    private function persistSkemaPilihan(): void
    {
        $skemaId = $this->data['skema_id'] ?? null;
        if (! $skemaId || (int) $skemaId === (int) $this->record->skema_id) {
            return;
        }

        $this->record->skema_id = (int) $skemaId;
        $this->record->save();
        $this->record->refresh()->loadMissing('skema.lines');
    }
}
