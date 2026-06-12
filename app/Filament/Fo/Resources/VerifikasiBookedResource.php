<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\VerifikasiBookedResource\Pages;
use App\Modules\FO\Booked\Services\BookedVerificationService;
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
        return $table
            ->query(NupRegistration::query()->where('jenis', 'BOOKED')->with('customer'))
            ->columns([
                Tables\Columns\TextColumn::make('tgl_daftar')->label('Tgl Daftar')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('nama_pendaftar')->label('Nama')->searchable()->description(fn (NupRegistration $r) => $r->nik),
                Tables\Columns\TextColumn::make('pilihan_unit_1')->label('Unit Diinginkan')->placeholder('—'),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara Bayar')->badge(),
                Tables\Columns\TextColumn::make('ref_sales_name')->label('Sales')->placeholder('—')->toggleable(),
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
                        ->visible(fn (NupRegistration $r) => (bool) ($r->customer?->ktp_file_path || $r->customer?->npwp_file_path || $r->customer?->ktp_pasangan_file_path)),
                    Tables\Actions\Action::make('lihat_ktp')->label('Lihat KTP')->icon('heroicon-o-eye')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp']), shouldOpenInNewTab: true)
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_file_path),
                    Tables\Actions\Action::make('unduh_ktp')->label('Unduh KTP')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_file_path),
                    Tables\Actions\Action::make('unduh_npwp')->label('Unduh NPWP')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'npwp']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->npwp_file_path),
                    Tables\Actions\Action::make('unduh_ktp_pasangan')->label('Unduh KTP Pasangan')->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (NupRegistration $r) => route('fo.customer.file', [$r->customer_id, 'ktp_pasangan']).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->customer?->ktp_pasangan_file_path),
                    Tables\Actions\Action::make('lihat_bukti')->label('Lihat Bukti Transfer')->icon('heroicon-o-eye')->color('success')
                        ->url(fn (NupRegistration $r) => route('fo.booked.bukti', $r->id), shouldOpenInNewTab: true)
                        ->visible(fn (NupRegistration $r) => (bool) $r->bukti_transfer_path),
                    Tables\Actions\Action::make('unduh_bukti')->label('Unduh Bukti Transfer')->icon('heroicon-o-arrow-down-tray')->color('success')
                        ->url(fn (NupRegistration $r) => route('fo.booked.bukti', $r->id).'?download=1')
                        ->visible(fn (NupRegistration $r) => (bool) $r->bukti_transfer_path),
                ])->label('Berkas')->icon('heroicon-o-paper-clip')->button()->color('gray'),
                // Langkah 1 — Approve (cek mutasi bank). Belum pilih unit.
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'MENUNGGU_BAYAR')
                    ->modalDescription('Setujui setelah cek mutasi bank. Pilih unit dilakukan di langkah berikutnya.')
                    ->form([
                        Forms\Components\Placeholder::make('info')->label('Unit Diinginkan Customer')
                            ->content(fn (NupRegistration $r) => $r->pilihan_unit_1 ?: '—'),
                        Forms\Components\TextInput::make('booking_fee')->label('Nominal Transfer / Booking Fee (Rp)')->numeric()->default(0)
                            ->helperText('Sesuai mutasi bank yang sudah dicek.'),
                        Forms\Components\Textarea::make('note')->label('Catatan Verifikasi')->rows(2),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            app(BookedVerificationService::class)->approve($r, (float) ($data['booking_fee'] ?? 0), $data['note'] ?? null);
                            Notification::make()->success()->title('Disetujui → lanjut Pilih Unit')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal approve')->body($e->getMessage())->send();
                        }
                    }),
                // Langkah 2 — Pilih Unit → buka halaman tabel unit (pilih cluster → klik unit).
                Tables\Actions\Action::make('pilih_unit')->label('Pilih Unit')->icon('heroicon-o-home-modern')->color('primary')
                    ->visible(fn (NupRegistration $r) => $r->verif_status === 'APPROVED' && ! $r->reservasi_id)
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
}
