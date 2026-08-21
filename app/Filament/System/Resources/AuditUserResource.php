<?php

namespace App\Filament\System\Resources;

use App\Filament\System\Resources\AuditUserResource\Pages;
use App\Models\Session;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Audit User — manajemen sesi aktif. Menampilkan user yang sedang login dan
 * memungkinkan IT Admin melakukan logout paksa (menghapus baris sesi sehingga
 * user harus login ulang). Berada di grup navigasi "Audit", tepat di bawah
 * Audit Log. Membutuhkan SESSION_DRIVER=database (tabel `sessions`).
 */
class AuditUserResource extends Resource
{
    protected static ?string $model = Session::class;

    protected static ?string $navigationIcon = 'heroicon-o-power';

    protected static ?string $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Audit User';

    protected static ?string $modelLabel = 'Sesi User';

    protected static ?string $pluralModelLabel = 'Audit User';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Hanya sesi yang sudah login (punya user_id) — sesi tamu diabaikan.
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('user_id'))
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')
                    ->label('Pengguna')
                    ->description(fn (Session $record): ?string => $record->user?->email)
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label('Perangkat')
                    ->formatStateUsing(fn (?string $state): string => static::ringkasUserAgent($state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_activity')
                    ->label('Aktif terakhir')
                    ->formatStateUsing(fn (?int $state): string => $state ? Carbon::createFromTimestamp($state, config('app.timezone'))->diffForHumans() : '—')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_current')
                    ->label('Sesi ini')
                    ->state(fn (Session $record): bool => $record->id === session()->getId())
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('forceLogout')
                    ->label('Logout Paksa')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Logout paksa pengguna ini?')
                    ->modalDescription('Sesi akan dihapus dan pengguna harus login ulang.')
                    // Cegah admin me-logout sesi miliknya sendiri yang sedang aktif.
                    ->hidden(fn (Session $record): bool => $record->id === session()->getId())
                    ->action(fn (Session $record) => static::forceLogout(collect([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('forceLogoutBulk')
                    ->label('Logout Paksa')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => static::forceLogout($records))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('last_activity', 'desc')
            ->emptyStateHeading('Tidak ada sesi aktif')
            ->poll('30s');
    }

    /**
     * Hapus sesi terpilih (logout paksa) dan catat ke Audit Log. Sesi admin yang
     * sedang aktif dilewati agar admin tidak menendang dirinya sendiri.
     *
     * @param  Collection<int, Session>  $sessions
     */
    protected static function forceLogout(Collection $sessions): void
    {
        $currentId = session()->getId();
        $count = 0;

        foreach ($sessions as $session) {
            if ($session->id === $currentId) {
                continue;
            }

            $user = $session->user;
            $ip = $session->ip_address;
            $session->delete();
            $count++;

            activity('auth')
                ->causedBy(auth()->user())
                ->performedOn($user ?? auth()->user())
                ->withProperties(['ip' => $ip, 'target_user' => $user?->full_name ?? $user?->email])
                ->event('force_logout')
                ->log('Logout paksa oleh admin'.($user ? ' — '.($user->full_name ?? $user->email) : ''));
        }

        Notification::make()
            ->title($count > 0 ? "{$count} sesi di-logout paksa." : 'Tidak ada sesi yang dihentikan.')
            ->success()
            ->send();
    }

    protected static function ringkasUserAgent(?string $ua): string
    {
        if (! $ua) {
            return '—';
        }

        return match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => Str::limit($ua, 40),
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditUsers::route('/'),
        ];
    }
}
