<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Pengguna & Akses';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Grup Akses';

    protected static ?string $pluralModelLabel = 'Grup Akses';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nama Grup Akses')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('guard_name')->label('Guard')->default('web')->disabled()->dehydrated(),
            ]),

            // Akses modul dipisah dari matriks aksi karena bentuknya memang lain:
            // ia menjawab "boleh masuk pintunya?", bukan "boleh melakukan apa di
            // dalamnya?". Tanpa bagian ini, ketujuh izin modul hanya bisa diberikan
            // lewat CLI — ada di database tetapi tak punya satu pun kotak centang.
            Forms\Components\Section::make('Akses Modul (Dashboard)')
                ->description('Menentukan kartu mana yang tampil di halaman pilih modul — sekaligus menjaga rutenya, bukan sekadar menyembunyikan kartunya.')
                ->schema([Forms\Components\Grid::make(4)->schema(self::modulCheckbox())]),

            Forms\Components\Section::make('Hak Akses (Permission)')
                ->description('Centang aksi per menu. Index=Lihat, Store=Buat, Update=Ubah, Destroy=Hapus, Print=Cetak, Approve. Tiap modul/menu punya akses sendiri.')
                ->schema(self::permissionMatrix()),
        ]);
    }

    /** @return array<int,Component> Satu kotak per modul dashboard. */
    public static function modulCheckbox(): array
    {
        $label = [
            'fo' => 'Front Office',
            'bo' => 'Back Office',
            'backup' => 'Backup',
            'ai' => 'Asisten AI',
            'legal' => 'Legal',
            'sales' => 'Sales',
            'system' => 'System',
        ];

        $kotak = [];
        foreach ((array) config('akses.modul', []) as $kunci => $izin) {
            $kotak[] = Forms\Components\Checkbox::make('m.'.str_replace('.', '~', $izin))
                ->label($label[$kunci] ?? $kunci);
        }

        return $kotak;
    }

    /** @return array<int,Component> Section per modul, baris area × 6 aksi. */
    public static function permissionMatrix(): array
    {
        $cfg = config('access_matrix');
        $actions = $cfg['actions'];
        $sections = [];

        foreach ($cfg['modules'] as $module => $areas) {
            $rows = [];
            foreach ($areas as $areaKey => $label) {
                $cols = [Forms\Components\Placeholder::make('lbl_'.str_replace('.', '_', $areaKey))
                    ->label('')->content($label)];
                foreach (array_keys($actions) as $action) {
                    $cols[] = Forms\Components\Checkbox::make('m.'.str_replace('.', '~', $areaKey.'.'.$action))
                        ->label(ucfirst($action));
                }
                $rows[] = Forms\Components\Grid::make(7)->schema($cols);
            }
            $sections[] = Forms\Components\Section::make($module)->collapsible()->collapsed()->schema($rows);
        }

        return $sections;
    }

    /** Daftar permission name terpilih dari state matrix 'm'. @return array<int,string> */
    public static function collectPermissions(array $m): array
    {
        return collect($m)->filter()->keys()
            ->map(fn ($k) => str_replace('~', '.', $k))->values()->all();
    }

    /**
     * Semua nama izin yang PUNYA kotak centang di matriks ini.
     *
     * Dipakai untuk melindungi izin yang tak terwakili di sini. Matriks ini hanya
     * memuat 330 izin, sedangkan sistem punya 380 — sisanya milik matriks BO yang
     * terpisah. Karena penyimpanan memakai syncPermissions() yang MENGGANTI seluruh
     * daftar, menekan Simpan di sini akan menghapus izin BO sebuah peran tanpa
     * peringatan apa pun. Yang di luar matriks harus dipertahankan apa adanya.
     *
     * @return array<int,string>
     */
    public static function namaIzinDiMatriks(): array
    {
        $cfg = config('access_matrix');
        $nama = [];

        foreach ($cfg['modules'] as $areas) {
            foreach (array_keys($areas) as $area) {
                foreach (array_keys($cfg['actions']) as $aksi) {
                    $nama[] = $area.'.'.$aksi;
                }
            }
        }

        // Izin modul kini PUNYA kotak, jadi ia harus dihitung sebagai bagian matriks.
        // Kalau tidak, melepas centangnya tidak akan mencabut apa pun — ia akan
        // dianggap "izin di luar matriks" lalu dipertahankan diam-diam.
        foreach ((array) config('akses.modul', []) as $izin) {
            $nama[] = $izin;
        }

        return $nama;
    }

    /**
     * Gabungkan centang dari matriks dengan izin lama yang tak terwakili di sini.
     *
     * @param  \Illuminate\Support\Collection<int,\Spatie\Permission\Models\Permission>  $izinLama
     * @param  array<int,string>  $terpilih
     * @return array<int,string>
     */
    public static function gabungIzin($izinLama, array $terpilih): array
    {
        $diMatriks = self::namaIzinDiMatriks();

        $diluarMatriks = collect($izinLama)->pluck('name')
            ->reject(fn (string $n) => in_array($n, $diMatriks, true))
            ->all();

        return array_values(array_unique(array_merge($terpilih, $diluarMatriks)));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama Grup Akses')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('permissions_count')->counts('permissions')->label('Jml Permission')->badge()->alignCenter(),
                Tables\Columns\TextColumn::make('users_count')->counts('users')->label('Jml Pengguna')->badge()->color('info')->alignCenter(),
            ])
            ->actions([
                Tables\Actions\Action::make('duplikasi')->label('Duplikasi')->icon('heroicon-o-document-duplicate')->color('gray')
                    ->form([Forms\Components\TextInput::make('name')->label('Nama grup baru')->required()
                        ->default(fn (Role $record) => $record->name.' (copy)')])
                    ->action(function (Role $record, array $data) {
                        $new = Role::create(['name' => $data['name'], 'guard_name' => $record->guard_name]);
                        $new->syncPermissions($record->permissions);
                        Notification::make()->success()->title('Grup diduplikasi: '.$new->name)
                            ->body($record->permissions->count().' permission disalin.')->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
