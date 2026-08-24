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

            Forms\Components\Section::make('Hak Akses (Permission)')
                ->description('Centang aksi per menu. Index=Lihat, Store=Buat, Update=Ubah, Destroy=Hapus, Print=Cetak, Approve. Tiap modul/menu punya akses sendiri.')
                ->schema(self::permissionMatrix()),
        ]);
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
