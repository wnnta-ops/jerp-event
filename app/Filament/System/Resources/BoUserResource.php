<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\BoUserResource\Pages;
use App\Models\Bo\BoRole;
use App\Models\Bo\BoUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Pengguna BO — lihat pengguna aplikasi BO / Java Core System (DB `ipro_bo`)
 * dan atur GRUP AKSES-nya dari panel System. Pembuatan/penghapusan akun tetap
 * di app BO (butuh password/tenant/verifikasi email); di sini read-only atas
 * akun + kelola role & status aktif.
 */
class BoUserResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = BoUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Pengguna & Akses';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Pengguna BO';

    protected static ?string $pluralModelLabel = 'Pengguna BO';

    protected static ?string $navigationLabel = 'Pengguna BO';

    // Disembunyikan dari menu: pengguna BO kini kembaran otomatis pengguna FO
    // (dibuat/diselaraskan sendiri) → cukup satu menu "Pengguna". Resource tetap
    // ada (bisa diakses via URL) bila sewaktu-waktu perlu.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas (dari aplikasi BO)')
                ->description('Data akun dikelola di aplikasi BO — hanya-baca di sini.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama')->disabled(),
                    Forms\Components\TextInput::make('username')->label('Username')->disabled(),
                    Forms\Components\TextInput::make('email')->label('Email')->disabled(),
                    Forms\Components\TextInput::make('employee_nip')->label('NIP')->disabled(),
                ]),

            Forms\Components\Section::make('Akses')
                ->schema([
                    Forms\Components\Toggle::make('is_active')->label('Aktif')
                        ->helperText('Nonaktifkan untuk mencegah login pengguna ini di app BO.'),
                    Forms\Components\CheckboxList::make('roles')
                        ->label('Grup Akses BO')
                        ->relationship('roles', 'name')
                        ->columns(2)
                        ->bulkToggleable()
                        ->helperText('Perubahan berlaku di app BO paling lama 1 menit kemudian.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('username')->label('Username')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Grup Akses')->badge()->color('info')
                    ->placeholder('— belum ada —'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter(),
                Tables\Columns\IconColumn::make('is_blocked')->label('Diblokir')->boolean()
                    ->trueColor('danger')->falseColor('gray')->alignCenter()->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
                Tables\Filters\SelectFilter::make('roles')->label('Grup Akses')
                    ->relationship('roles', 'name')->multiple(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Atur Akses'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoUsers::route('/'),
            'edit' => Pages\EditBoUser::route('/{record}/edit'),
        ];
    }

    /** @return array<int,string> id=>nama grup akses BO, untuk pilihan. */
    public static function roleOptions(): array
    {
        return BoRole::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
