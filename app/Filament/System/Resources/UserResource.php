<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\UserResource\Pages;
use App\Models\Bo\BoRole;
use App\Models\Bo\BoUser;
use App\Models\User;
use App\Modules\BO\IproBridge\Services\IproMasterSync;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Pengguna & Akses';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Username')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('full_name')
                        ->label('Nama lengkap')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\Select::make('tenant_id')
                        ->label('Tenant')
                        ->relationship('tenant', 'name')
                        ->required()
                        ->default(1)
                        ->searchable()
                        ->preload(),
                ]),

            Forms\Components\Section::make('Keamanan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Kosongkan jika tidak ingin mengubah password.' : null)
                        ->maxLength(255),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->required()
                        ->default(true),
                ]),

            Forms\Components\Section::make('Hak Akses (Grup Akses)')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('Grup Akses')
                        ->multiple()
                        ->relationship('roles', 'name')
                        ->preload()
                        ->searchable()
                        ->helperText('Akses dasar mengikuti Grup Akses. Untuk tambahan lintas-modul, isi "Hak Akses Khusus" di bawah.'),
                ]),

            Forms\Components\Section::make('Grup Akses BO (Java Core System)')
                ->description('BO punya daftar grup aksesnya sendiri di database ipro_bo. Pengguna dicocokkan lewat EMAIL yang sama.')
                ->schema([
                    Forms\Components\Placeholder::make('bo_belum_disimpan')
                        ->label('')
                        ->content('Simpan dulu penggunanya, lalu buka kembali halaman ini untuk mengatur grup akses BO.')
                        ->visible(fn (?User $record) => $record === null),

                    Forms\Components\Placeholder::make('bo_tidak_ada')
                        ->label('')
                        ->content('Belum ada akun BO dengan email ini, jadi grup aksesnya belum bisa diatur dari sini. Buat akunnya dulu di aplikasi BO — pembuatan akun butuh password & tenant, dan itu tetap dikerjakan di sana.')
                        ->visible(fn (?User $record) => $record !== null && static::penggunaBo($record) === null),

                    Forms\Components\Select::make('grup_bo')
                        ->label('Grup Akses BO')
                        ->multiple()
                        ->options(fn () => BoRole::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->preload()
                        ->searchable()
                        ->helperText('Terpisah dari Grup Akses di atas: yang itu mengatur menu FO, yang ini mengatur menu di aplikasi BO.')
                        ->visible(fn (?User $record) => $record !== null && static::penggunaBo($record) !== null),
                ]),

            Forms\Components\Section::make('Hak Akses Khusus (lintas modul) — opsional')
                ->description('Tambahan hak akses untuk pengguna INI di luar Grup Akses (mis. anggota FAT yang butuh akses lintas modul secara dinamik). Akses efektif = Grup Akses + centang di sini.')
                ->collapsible()->collapsed()
                ->schema(RoleResource::permissionMatrix()),
        ]);
    }

    /**
     * Pengguna BO yang sepadan, dicocokkan lewat email.
     *
     * Email dipakai sebagai penghubung karena itu pula yang dipakai SSO FO→BO —
     * tidak ada kolom penaut antar-database. Hasilnya diingat per pengguna supaya
     * form yang memanggilnya berkali-kali tidak menembak ipro_bo berulang.
     *
     * @var array<string,\App\Models\Bo\BoUser|null>
     */
    protected static array $cacheBo = [];

    public static function penggunaBo(?User $user): ?BoUser
    {
        $email = $user?->email;
        if (! $email) {
            return null;
        }

        return static::$cacheBo[$email] ??= BoUser::query()->where('email', $email)->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nama lengkap')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Grup Akses')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Login terakhir')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->multiple(),
            ])
            ->actions([
                // Duplikasi pengguna — salin Grup Akses + Hak Akses Khusus, tinggal
                // ganti nama/email + tambah akses lain. Efisien untuk lintas akses.
                Tables\Actions\Action::make('duplikasi')->label('Duplikasi')->icon('heroicon-o-document-duplicate')->color('gray')
                    ->modalHeading('Duplikasi Pengguna')
                    ->modalDescription(fn (User $record) => 'Grup Akses + Hak Akses Khusus dari "'.$record->full_name.'" akan disalin ke pengguna baru.')
                    ->form([
                        Forms\Components\TextInput::make('name')->label('Username')->required()->unique('users', 'name'),
                        Forms\Components\TextInput::make('full_name')->label('Nama Lengkap')->required()
                            ->default(fn (User $record) => $record->full_name.' (copy)'),
                        Forms\Components\TextInput::make('email')->label('Email')->email()->required()->unique('users', 'email'),
                        Forms\Components\TextInput::make('password')->label('Kata Sandi')->password()->required()->minLength(6),
                    ])
                    ->action(function (User $record, array $data) {
                        $new = User::create([
                            'name' => $data['name'],
                            'full_name' => $data['full_name'],
                            'email' => $data['email'],
                            'password' => $data['password'], // cast 'hashed' otomatis hash
                            'tenant_id' => $record->tenant_id,
                            'is_active' => true,
                        ]);
                        $new->syncRoles($record->roles);
                        $new->syncPermissions($record->getDirectPermissions());

                        Notification::make()->success()->title('Pengguna diduplikasi: '.$new->name)
                            ->body('Grup Akses ('.$record->roles->count().') + hak akses khusus ('.$record->getDirectPermissions()->count().') disalin. Tinggal tambah akses lain bila perlu.')
                            ->send();
                    }),
                // Provision/selaraskan kembaran user BO (ipro_bo, by email) agar SSO FO→BO cocok.
                Tables\Actions\Action::make('sinkronBo')->label('Sinkronkan ke BO')
                    ->icon('heroicon-o-arrows-right-left')->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Sinkronkan ke BO')
                    ->modalDescription(fn (User $record) => 'Buat/selaraskan kembaran pengguna BO ber-email "'.$record->email.'" agar login SSO FO→BO cocok.')
                    ->action(function (User $record) {
                        try {
                            $id = app(IproMasterSync::class)->syncUser($record);
                            $id
                                ? Notification::make()->success()->title('Tersinkron ke BO')
                                    ->body('Pengguna BO (id '.$id.') untuk '.$record->email.' siap. Berlaku penuh setelah cache izin BO di-reset.')->send()
                                : Notification::make()->warning()->title('Dilewati')
                                    ->body('Pengguna tanpa email tak dapat disinkron ke BO.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal sinkron ke BO')->body($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('sinkronBoBulk')->label('Sinkronkan ke BO')
                        ->icon('heroicon-o-arrows-right-left')->color('warning')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $svc = app(IproMasterSync::class);
                            $ok = $skip = $fail = 0;
                            foreach ($records as $r) {
                                try {
                                    $svc->syncUser($r) ? $ok++ : $skip++;
                                } catch (\Throwable $e) {
                                    $fail++;
                                }
                            }
                            Notification::make()->success()->title('Sinkron BO selesai')
                                ->body("Berhasil: {$ok} · Dilewati: {$skip} · Gagal: {$fail}. Berlaku penuh setelah cache izin BO di-reset.")->send();
                        })->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('full_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
