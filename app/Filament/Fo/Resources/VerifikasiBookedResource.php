<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\VerifikasiBookedResource\Pages;
use App\Modules\FO\Booked\Services\BookedVerificationService;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Verifikasi Data — Booked Manual. Tim cek mutasi bank (transfer), lalu Approve:
 * pilih unit asli + nominal booking fee → otomatis bentuk Kontrak tahap SPRS.
 */
class VerifikasiBookedResource extends Resource
{
    protected static ?string $model = NupRegistration::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Booked Manual';

    protected static ?string $slug = 'verifikasi-booked';

    protected static ?string $modelLabel = 'Verifikasi Booked';

    protected static ?string $pluralModelLabel = 'Verifikasi Booked';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('jenis', 'BOOKED');
    }

    public static function table(Table $table): Table
    {
        // Lepas unit yang masa hold-nya sudah lewat tanggal setup — langsung saat
        // halaman dibuka (tak menunggu scheduler), sesuai periode Setup Hold.
        app(\App\Modules\FO\Booked\Services\HoldService::class)->releaseExpired();

        return $table
            ->query(NupRegistration::query()->where('jenis', 'BOOKED')->with(['customer', 'transferAnonim.bank']))
            ->columns([
                Tables\Columns\TextColumn::make('tgl_daftar')->label('Tgl Daftar')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('nama_pendaftar')->label('Nama')->searchable()->description(fn (NupRegistration $r) => $r->nik),
                Tables\Columns\TextColumn::make('pilihan_unit_1')->label('Unit Diinginkan')->placeholder('—'),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara Bayar')->badge(),
                Tables\Columns\TextColumn::make('ref_sales_name')->label('Sales')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('transferAnonim.amount')->label('Bukti Transfer')->placeholder('— belum dicocokkan')
                    ->money('IDR')->description(fn (NupRegistration $r) => $r->transferAnonim?->coaBankName() ?: $r->transferAnonim?->bank?->name)->color('success'),
                Tables\Columns\TextColumn::make('verif_status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'APPROVED' => 'success', 'REJECTED' => 'danger', 'MENUNGGU_BAYAR' => 'warning', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verif_status')->label('Status')->options([
                    'MENUNGGU_BAYAR' => 'Menunggu Verifikasi', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected',
                ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('download_semua')->label('Download Semua (ZIP)')->icon('heroicon-o-archive-box-arrow-down')
                        ->url(fn (NupRegistration $r) => route('fo.customer.files-zip', $r->customer_id))
                        ->visible(fn (NupRegistration $r) => (bool) ($r->customer?->ktp_file_path || $r->customer?->npwp_file_path || $r->customer?->ktp_pasangan_file_path || $r->customer?->npwp_pasangan_file_path)),
                    Tables\Actions\Action::make('lihat_ktp')->label('Lihat KTP')->icon('heroicon-o-eye')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp']), shouldOpenInNewTab: true)
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_file_path),
                    Tables\Actions\Action::make('unduh_ktp')->label('Unduh KTP')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_file_path),
                    Tables\Actions\Action::make('lihat_npwp')->label('Lihat NPWP')->icon('heroicon-o-eye')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'npwp']), shouldOpenInNewTab: true)
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->npwp_file_path),
                    Tables\Actions\Action::make('unduh_npwp')->label('Unduh NPWP')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'npwp']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->npwp_file_path),
                    Tables\Actions\Action::make('unduh_ktp_pasangan')->label('Unduh KTP Pasangan')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp_pasangan']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_pasangan_file_path),
                    Tables\Actions\Action::make('unduh_npwp_pasangan')->label('Unduh NPWP Pasangan')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'npwp_pasangan']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->npwp_pasangan_file_path),
                    Tables\Actions\Action::make('lihat_bukti')->label('Lihat Bukti Transfer')->icon('heroicon-o-eye')->color('success')
                        ->url(fn (NupRegistration $r) => route('fo.booked.bukti', $r->id), shouldOpenInNewTab: true)
                        ->visible(fn (NupRegistration $r) => (bool) $r->bukti_transfer_path),
                    Tables\Actions\Action::make('unduh_bukti')->label('Unduh Bukti Transfer')->icon('heroicon-o-arrow-down-tray')->color('success')
                        ->url(fn (NupRegistration $r) => route('fo.booked.bukti', $r->id).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->bukti_transfer_path),
                ])->label('Berkas')->icon('heroicon-o-paper-clip')->button()->color('gray'),
                // Hold Unit sementara — HANYA muncul saat ada periode Setup Hold aktif
                // (sebelum tanggal periode, tombol disembunyikan). Sales reservasi unit
                // sampai finance cocokkan transfer; auto-tersedia bila lewat masa hold.
                Tables\Actions\Action::make('hold')->label('Hold Unit')->icon('heroicon-o-pause-circle')->color('warning')
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'MENUNGGU_BAYAR'
                        && app(\App\Modules\FO\Booked\Services\HoldService::class)->isHoldActive()
                        && ! \App\Modules\FO\MarketingSales\Models\Unit::where('hold_registration_id', $r->id)->where('status', 'HOLD')->exists())
                    ->modalHeading('Hold Unit Sementara')
                    ->modalDescription(fn (NupRegistration $r) => 'Unit diinginkan: '.($r->pilihan_unit_1 ?: '—').'. Berlaku sampai akhir periode hold; otomatis TERSEDIA lagi bila tak ada transfer cocok.')
                    ->fillForm(fn (NupRegistration $r) => ['unit_id' => self::matchUnitId($r)])
                    ->form([
                        Forms\Components\Select::make('unit_id')->label('Unit (TERSEDIA)')->required()->searchable()
                            ->options(fn (NupRegistration $r) => self::unitOptions($r))
                            ->getOptionLabelUsing(fn ($value) => ($u = \App\Modules\FO\MarketingSales\Models\Unit::find($value)) ? ($u->kode_unit ?: trim(($u->blok ? $u->blok.' / ' : '').$u->no_unit)) : null)
                            ->helperText('Default mengikuti unit diinginkan pendaftar. Pilih unit yang TERSEDIA.'),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            $unit = \App\Modules\FO\MarketingSales\Models\Unit::findOrFail($data['unit_id']);
                            app(\App\Modules\FO\Booked\Services\HoldService::class)->holdUnit($r->id, $unit, auth()->id());
                            Notification::make()->success()->title('Unit di-hold sementara')
                                ->body('Unit '.($unit->kode_unit ?: $unit->no_unit).' di-hold s/d '.optional($unit->fresh()->hold_until)->format('d M Y H:i').'.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal hold')->body($e->getMessage())->send();
                        }
                    }),
                // Cancel Hold — lepas hold manual (sebelum booked) → unit TERSEDIA lagi.
                Tables\Actions\Action::make('cancel_hold')->label('Cancel Hold')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (NupRegistration $r) => ! $r->reservasi_id
                        && \App\Modules\FO\MarketingSales\Models\Unit::where('hold_registration_id', $r->id)->where('status', 'HOLD')->exists())
                    ->requiresConfirmation()
                    ->modalHeading('Cancel Hold Unit')
                    ->modalDescription(fn (NupRegistration $r) => 'Lepas hold unit untuk '.($r->nama_pendaftar ?: $r->customer?->full_name ?: 'pendaftar ini').'? Unit kembali TERSEDIA.')
                    ->action(function (NupRegistration $r) {
                        $unit = \App\Modules\FO\MarketingSales\Models\Unit::where('hold_registration_id', $r->id)->where('status', 'HOLD')->first();
                        if ($unit) {
                            app(\App\Modules\FO\Booked\Services\HoldService::class)->releaseUnit($unit);
                            Notification::make()->success()->title('Hold dibatalkan')
                                ->body('Unit '.($unit->kode_unit ?: $unit->no_unit).' kembali TERSEDIA.')->send();
                        }
                    }),
                // Langkah 1 — Approve (cek mutasi bank). Belum pilih unit.
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'MENUNGGU_BAYAR')
                    ->modalHeading('Approve — cocokkan bukti transfer (finance)')
                    ->modalDescription('Pilih Transfer Anonim yang sudah diidentifikasi finance untuk customer ini. Nominal otomatis dari transfer. Pilih unit di langkah berikutnya.')
                    ->form([
                        Forms\Components\Placeholder::make('info')->label('Unit Diinginkan Customer')
                            ->content(fn (NupRegistration $r) => $r->pilihan_unit_1 ?: '—'),
                        Forms\Components\Select::make('transfer_anonim_id')->label('Transfer Anonim (bukti finance — UTJ/Booking Fee)')->required()->searchable()
                            ->options(fn () => self::transferOptions(TransferAnonim::query()->with('bank')
                                ->where('status', '!=', 'SELESAI')->orderByDesc('tgl_transfer')->limit(50)->get()))
                            ->getSearchResultsUsing(fn (string $search) => self::transferOptions(
                                TransferAnonim::query()->with('bank')->where('status', '!=', 'SELESAI')
                                    ->where(function ($q) use ($search) {
                                        $q->whereRaw("DATE_FORMAT(tgl_transfer, '%d %b %Y') LIKE ?", ['%'.$search.'%'])
                                            ->orWhere('keterangan_bank', 'like', '%'.$search.'%')
                                            ->orWhere('amount', 'like', '%'.$search.'%')
                                            ->orWhereHas('bank', fn ($b) => $b->where('name', 'like', '%'.$search.'%'));
                                    })->orderByDesc('tgl_transfer')->limit(50)->get()))
                            ->getOptionLabelUsing(fn ($value) => ($t = TransferAnonim::with('bank')->find($value)) ? self::transferLabel($t) : null)
                            ->helperText('Cari per tanggal / bank / nominal / berita. Nominal (booking fee) otomatis ikut transfer terpilih.'),
                        Forms\Components\Textarea::make('note')->label('Catatan Verifikasi')->rows(2),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            $txId = ((int) ($data['transfer_anonim_id'] ?? 0)) ?: null;
                            $bookingFee = $txId ? (float) (TransferAnonim::find($txId)?->amount ?? 0) : 0;
                            app(BookedVerificationService::class)->approve($r, $bookingFee, $data['note'] ?? null, $txId);
                            Notification::make()->success()->title('Disetujui → lanjut Pilih Unit')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal approve')->body($e->getMessage())->send();
                        }
                    }),
                // Held unit + sudah Approve (transfer cocok) → selesaikan langsung jadi
                // booked (Kontrak SPRS + TTS) pakai unit yang di-hold, tanpa map.
                Tables\Actions\Action::make('booked_hold')->label('Selesaikan Booked (Unit Hold)')->icon('heroicon-o-home-modern')->color('primary')
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'APPROVED' && ! $r->reservasi_id
                        && \App\Modules\FO\MarketingSales\Models\Unit::where('hold_registration_id', $r->id)->where('status', 'HOLD')->exists())
                    ->modalHeading('Selesaikan Booked — pakai unit yang di-hold')
                    ->form([
                        Forms\Components\Select::make('skema_id')->label('Skema Bayar')->required()->searchable()
                            ->options(fn () => \App\Modules\FO\Adminjual\Models\Skema::orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            $unit = \App\Modules\FO\MarketingSales\Models\Unit::where('hold_registration_id', $r->id)->where('status', 'HOLD')->firstOrFail();
                            app(BookedVerificationService::class)->pilihUnit($r, $unit->id, (int) $data['skema_id']);
                            Notification::make()->success()->title('Booked — Kontrak SPRS + TTS terbentuk')
                                ->body('Unit '.($unit->kode_unit ?: $unit->no_unit).' kini BOOKING.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
                        }
                    }),
                // Langkah 2 — Pilih Unit → buka halaman tabel unit (pilih cluster → klik unit).
                // Disembunyikan bila ada unit yang di-hold untuk reg ini (pakai aksi di atas).
                Tables\Actions\Action::make('pilih_unit')->label('Pilih Unit')->icon('heroicon-o-home-modern')->color('primary')
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'APPROVED' && ! $r->reservasi_id
                        && ! \App\Modules\FO\MarketingSales\Models\Unit::where('hold_registration_id', $r->id)->where('status', 'HOLD')->exists())
                    ->url(fn (NupRegistration $r) => '/fo/pilih-unit-booked?reg='.$r->id),
                Tables\Actions\Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (NupRegistration $r) => ! in_array($r->verif_status, ['APPROVED', 'REJECTED']))
                    ->form([Forms\Components\Textarea::make('note')->label('Alasan')->required()->rows(2)])
                    ->action(function (NupRegistration $r, array $data) {
                        app(BookedVerificationService::class)->reject($r, $data['note']);
                        Notification::make()->warning()->title('Pendaftaran ditolak')->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('tgl_daftar', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVerifikasiBookeds::route('/'),
        ];
    }

    /** Label pilihan Transfer Anonim: "tgl · bank · Rp nominal · berita". */
    protected static function transferLabel(TransferAnonim $t): string
    {
        return $t->tgl_transfer?->format('d M Y').' · '.($t->coaBankName() ?: ($t->bank?->name ?? 'Bank'))
            .' · Rp '.number_format((float) $t->amount, 0, ',', '.')
            .($t->keterangan_bank ? ' · '.$t->keterangan_bank : '');
    }

    /** @return array<int,string> [id => label] dari koleksi TransferAnonim. */
    protected static function transferOptions(\Illuminate\Support\Collection $rows): array
    {
        return $rows->mapWithKeys(fn (TransferAnonim $t) => [$t->id => self::transferLabel($t)])->all();
    }

    /** Unit TERSEDIA untuk di-hold (default project pendaftar). */
    protected static function unitOptions(NupRegistration $r): array
    {
        return \App\Modules\FO\MarketingSales\Models\Unit::query()
            ->where('status', 'TERSEDIA')
            ->when($r->project_pref_id, fn ($q) => $q->where('project_id', $r->project_pref_id))
            ->orderBy('kode_unit')->limit(300)->get()
            ->mapWithKeys(fn ($u) => [$u->id => ($u->kode_unit ?: trim(($u->blok ? $u->blok.' / ' : '').$u->no_unit))])
            ->all();
    }

    /** Tebak unit TERSEDIA yang cocok dengan "Unit Diinginkan" (teks pendaftar). */
    protected static function matchUnitId(NupRegistration $r): ?int
    {
        $want = trim((string) $r->pilihan_unit_1);
        if ($want === '') {
            return null;
        }

        return \App\Modules\FO\MarketingSales\Models\Unit::query()
            ->where('status', 'TERSEDIA')
            ->when($r->project_pref_id, fn ($q) => $q->where('project_id', $r->project_pref_id))
            ->where(fn ($q) => $q->where('kode_unit', 'like', '%'.$want.'%')
                ->orWhere('no_unit', 'like', '%'.$want.'%')
                ->orWhere('alamat', 'like', '%'.$want.'%'))
            ->value('id');
    }
}
