<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Survei\Jobs\KirimWebhookSurvei;
use App\Modules\FO\Survei\Models\WebhookKeluar;
use App\Modules\FO\Survei\Services\WebhookSurvei as Webhook;
use App\Support\Akses;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Pengaturan → Webhook Survei: catatan notifikasi perubahan SPR yang dikirim J-ERP ke
 * aplikasi Survei Kepuasan Customer — terkirim, ditolak, atau masih menunggu — beserta
 * jawaban server survei. Yang gagal/ditolak bisa dikirim ulang (izin ubah).
 */
class WebhookSurvei extends Page implements HasTable
{
    use InteractsWithTable;
    use MematuhiIzinHalaman;

    protected static ?string $slug = 'webhook-survei';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Webhook Survei';

    protected static string $view = 'filament.fo.pages.webhook-survei';

    public static function bolehKirimUlang(): bool
    {
        return Akses::boleh('fo.webhook_survei.update');
    }

    public function getAktif(): bool
    {
        return Webhook::aktif();
    }

    public function getTujuan(): string
    {
        return (string) config('services.survei.webhook_url');
    }

    /** @return array<string,int> */
    public function getHitung(): array
    {
        return WebhookKeluar::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->all();
    }

    private static function kirimUlang(WebhookKeluar $w): void
    {
        $w->update(['status' => 'antre']);
        KirimWebhookSurvei::dispatch($w->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WebhookKeluar::query())
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Dicatat')->dateTime('d M Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('kunci')->label('No. SPR')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('event')->label('Event')->badge()
                    ->color(fn (string $state) => match ($state) { 'spr.created' => 'success', 'spr.cancelled', 'spr.deleted' => 'danger', default => 'info' }),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => WebhookKeluar::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'terkirim' => 'success', 'ditolak', 'gagal' => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('percobaan')->label('Percobaan')->alignCenter(),
                Tables\Columns\TextColumn::make('kode_http')->label('HTTP')->alignCenter()->placeholder('—'),
                Tables\Columns\TextColumn::make('jawaban')->label('Jawaban server survei')->limit(80)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('terkirim_pada')->label('Terkirim')->dateTime('d M Y H:i:s')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(WebhookKeluar::STATUS),
                Tables\Filters\SelectFilter::make('event')->options([
                    'spr.created' => 'spr.created', 'spr.updated' => 'spr.updated',
                    'spr.cancelled' => 'spr.cancelled', 'spr.deleted' => 'spr.deleted',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('isi')->label('Isi')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (WebhookKeluar $r) => 'Webhook ' . $r->event . ' · ' . $r->kunci)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (WebhookKeluar $r) => view('filament.fo.pages.webhook-survei-isi', ['r' => $r])),
                Tables\Actions\Action::make('ulang')->label('Kirim ulang')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (WebhookKeluar $r) => in_array($r->status, ['gagal', 'ditolak'], true) && self::bolehKirimUlang())
                    ->requiresConfirmation()
                    ->modalDescription('Dikirim ulang dengan id yang sama — aplikasi survei menolak duplikat, jadi aman.')
                    ->action(function (WebhookKeluar $r) {
                        self::kirimUlang($r);
                        Notification::make()->success()->title('Dikirim ulang ke antrean')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('ulangTerpilih')->label('Kirim ulang terpilih')->icon('heroicon-o-arrow-path')
                    ->visible(fn () => self::bolehKirimUlang())->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $n = 0;
                        foreach ($records as $r) {
                            if (in_array($r->status, ['gagal', 'ditolak'], true)) {
                                self::kirimUlang($r);
                                $n++;
                            }
                        }
                        Notification::make()->success()->title($n . ' webhook dikirim ulang')->send();
                    })->deselectRecordsAfterCompletion(),
            ]);
    }
}
