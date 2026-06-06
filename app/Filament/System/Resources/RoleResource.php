<?php

namespace App\Filament\System\Resources;

use App\Filament\System\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Pengguna & Akses';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Role')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama role')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('Contoh: super_admin, finance_manager, sales_staff'),
                    Forms\Components\TextInput::make('guard_name')
                        ->label('Guard')
                        ->required()
                        ->default('web'),
                ]),

            Forms\Components\Section::make('Permissions')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label('Permissions')
                        ->relationship('permissions', 'name')
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(2)
                        ->gridDirection('row'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama role')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('guard_name')
                    ->label('Guard')
                    ->badge(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Jumlah permission')
                    ->counts('permissions')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('Jumlah pengguna')
                    ->counts('users')
                    ->alignCenter(),
            ])
            ->actions([
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
