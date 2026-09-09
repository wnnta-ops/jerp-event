<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\BoRoleResource\Pages;
use App\Models\Bo\BoPermission;
use App\Models\Bo\BoRole;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Grup Akses BO — kelola role & permission aplikasi BO / Java Core System
 * (DB `ipro_bo`) dari panel System jerp. Terpisah dari Grup Akses FO
 * (Spatie\Role di DB jerp); keduanya diatur dari satu tempat.
 */
class BoRoleResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = BoRole::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Pengguna & Akses';

    protected static ?int $navigationSort = 30;

    // Tampil sebagai sub-menu di bawah "Grup Akses" (FO) agar menu ringkas & rapih.
    protected static ?string $navigationParentItem = 'Grup Akses';

    protected static ?string $modelLabel = 'Grup Akses BO';

    protected static ?string $pluralModelLabel = 'Grup Akses BO';

    protected static ?string $navigationLabel = 'Grup Akses BO';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nama Grup Akses')->required()
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?BoRole $record) => $record?->is_system)
                    ->helperText(fn (?BoRole $record) => $record?->is_system ? 'Grup bawaan sistem — nama tak dapat diubah.' : null),
                Forms\Components\TextInput::make('guard_name')->label('Guard')->default('web')->disabled()->dehydrated(),
                Forms\Components\Textarea::make('description')->label('Keterangan')->rows(2)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Hak Akses (Permission) BO')
                ->description('Per modul → sub-menu → aksi (Lihat/Buat/Ubah/Hapus/Setujui/…), seperti Grup Akses FO. Perubahan tersimpan ke DB ipro_bo; berlaku di app BO setelah cache izin di-reset (maks 24 jam).')
                ->schema(self::permissionMatrixBo()),
        ]);
    }

    /** Label modul BO yang lebih manusiawi (fallback: headline nama modul). */
    private const MODULE_LABELS = [
        'ap' => 'AP · Hutang', 'ar' => 'AR · Piutang', 'approval' => 'Approval',
        'bankloan' => 'Bank Loan (KPR)', 'bs' => 'Business Support', 'budget' => 'Anggaran',
        'businesssupport' => 'Business Support', 'cashbook' => 'Buku Kas', 'cogs' => 'COGS / HPP',
        'collection' => 'Collection', 'commission' => 'Komisi', 'contractor' => 'Kontraktor',
        'crm' => 'CRM', 'datacenter' => 'Data Center', 'finance' => 'Finance',
        'gl' => 'GL · Buku Besar', 'incentive' => 'Insentif', 'legal' => 'Legal',
        'master' => 'Master Data', 'reporting' => 'Laporan', 'sales' => 'Sales',
        'security' => 'Keamanan', 'setupsales' => 'Setup Sales', 'system' => 'Sistem', 'tax' => 'Pajak',
    ];

    /** Label aksi (segmen terakhir permission) dalam Bahasa Indonesia. */
    private const ACTION_LABELS = [
        'view' => 'Lihat', 'show' => 'Lihat', 'index' => 'Lihat', 'list' => 'Lihat', 'read' => 'Lihat',
        'create' => 'Buat', 'store' => 'Buat', 'add' => 'Buat',
        'update' => 'Ubah', 'edit' => 'Ubah',
        'delete' => 'Hapus', 'destroy' => 'Hapus', 'remove' => 'Hapus',
        'approve' => 'Setujui', 'reject' => 'Tolak', 'verify' => 'Verifikasi', 'confirm' => 'Konfirmasi',
        'print' => 'Cetak', 'export' => 'Ekspor', 'download' => 'Unduh', 'import' => 'Impor',
        'manage' => 'Kelola', 'request' => 'Ajukan', 'submit' => 'Ajukan',
        'pay' => 'Bayar', 'void' => 'Void', 'cancel' => 'Batal', 'close' => 'Tutup', 'reopen' => 'Buka Lagi',
        'issue' => 'Terbitkan', 'release' => 'Rilis', 'post' => 'Posting', 'reverse' => 'Reversal',
        'toggle' => 'Aktif/Nonaktif', 'assign' => 'Tetapkan', 'promote' => 'Promosikan', 'reschedule' => 'Jadwal Ulang',
    ];

    /** Urutan aksi umum agar kolom konsisten antar-baris (mirip FO). */
    private const ACTION_ORDER = [
        'view', 'show', 'index', 'list', 'create', 'store', 'update', 'edit',
        'delete', 'destroy', 'approve', 'reject', 'print', 'export',
    ];

    /**
     * Matrix izin BO gaya FO: Section per MODUL → baris per SUB-MENU (area) →
     * centang per AKSI. Izin BO berformat module.area.action, jadi tiap izin
     * tetap satu centang (tak ada yang hilang), tapi tersusun rapi & ringkas.
     *
     * @return array<int,\Filament\Forms\Components\Component>
     */
    public static function permissionMatrixBo(): array
    {
        $perms = BoPermission::query()->orderBy('module')->orderBy('name')
            ->get(['id', 'name', 'module', 'label']);

        // module => area => [ ['id'=>.., 'action'=>..], ... ]
        $tree = [];
        foreach ($perms as $p) {
            [$module, $area, $action] = self::splitPerm($p->name, (string) $p->module);
            $tree[$module][$area][] = ['id' => $p->id, 'action' => $action];
        }

        $sections = [];
        foreach ($tree as $module => $areas) {
            $rows = [];
            foreach ($areas as $area => $items) {
                usort($items, fn ($a, $b) => self::actionRank($a['action']) <=> self::actionRank($b['action']));
                $options = [];
                foreach ($items as $it) {
                    $options[$it['id']] = self::actionLabel($it['action']);
                }

                $rows[] = Forms\Components\Grid::make(12)->schema([
                    Forms\Components\Placeholder::make('lbl_'.$module.'_'.Str::slug($area, '_'))
                        ->label('')->content(self::humanizeArea($area))->columnSpan(3),
                    Forms\Components\CheckboxList::make('perm.'.self::areaKey($module, $area))
                        ->label('')->options($options)->columns(6)->gridDirection('row')
                        ->bulkToggleable()->columnSpan(9),
                ]);
            }

            $sections[] = Forms\Components\Section::make(self::humanizeModule((string) $module))
                ->description(count($areas).' sub-menu')
                ->collapsible()->collapsed()
                ->schema($rows);
        }

        return $sections;
    }

    /** Pecah nama izin → [module, area, action]. Aman utk 2/3/4 segmen. */
    public static function splitPerm(string $name, string $module = ''): array
    {
        $seg = explode('.', $name);
        $module = $module !== '' ? $module : ($seg[0] ?? $name);
        $rest = Str::startsWith($name, $module.'.') ? Str::after($name, $module.'.') : implode('.', array_slice($seg, 1));
        $restSeg = $rest === '' ? [] : explode('.', $rest);
        $action = $restSeg ? (string) end($restSeg) : $name;
        $area = count($restSeg) > 1 ? implode('.', array_slice($restSeg, 0, -1)) : 'umum';

        return [$module, $area, $action];
    }

    /** Kunci state per area (aman dari titik). */
    public static function areaKey(string $module, string $area): string
    {
        return $module.'__'.str_replace('.', '_', $area);
    }

    /** Kunci area dari sebuah nama izin (dipakai prefill Edit). */
    public static function areaKeyFor(string $name, string $module = ''): string
    {
        [$m, $a] = self::splitPerm($name, $module);

        return self::areaKey($m, $a);
    }

    private static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? Str::headline($action);
    }

    private static function actionRank(string $action): int
    {
        $i = array_search($action, self::ACTION_ORDER, true);

        return $i === false ? 99 : (int) $i;
    }

    private static function humanizeModule(string $module): string
    {
        return self::MODULE_LABELS[$module] ?? Str::headline($module);
    }

    private static function humanizeArea(string $area): string
    {
        return $area === 'umum' ? 'Umum' : Str::headline(str_replace('.', ' ', $area));
    }

    /** Kumpulkan id permission terpilih dari state matrix 'perm'. @return array<int,int> */
    public static function collectPermissionIds(?array $perm): array
    {
        return collect($perm ?? [])->flatten()->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** tenant_id default untuk role baru: ikut tenant role yang sudah ada. */
    public static function defaultTenantId(): ?int
    {
        return BoRole::query()->whereNotNull('tenant_id')->value('tenant_id');
    }

    /** Ingatkan admin bahwa cache izin app BO perlu reset agar perubahan berlaku. */
    public static function flashCacheNotice(): void
    {
        Notification::make()
            ->warning()
            ->title('Tersimpan ke database BO')
            ->body('Perubahan berlaku di aplikasi BO setelah cache izin di-reset (otomatis maks 24 jam, atau `permission:cache-reset` di app BO).')
            ->persistent()
            ->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama Grup Akses')->searchable()->sortable()->weight('bold'),
                Tables\Columns\IconColumn::make('is_system')->label('Bawaan')->boolean()->alignCenter(),
                Tables\Columns\TextColumn::make('description')->label('Keterangan')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('permissions_count')->counts('permissions')->label('Jml Izin')->badge()->alignCenter(),
                Tables\Columns\TextColumn::make('users_count')->counts('users')->label('Jml Pengguna')->badge()->color('info')->alignCenter(),
            ])
            ->actions([
                Tables\Actions\Action::make('duplikasi')->label('Duplikasi')->icon('heroicon-o-document-duplicate')->color('gray')
                    ->form([Forms\Components\TextInput::make('name')->label('Nama grup baru')->required()
                        ->default(fn (BoRole $record) => $record->name.' (copy)')])
                    ->action(function (BoRole $record, array $data) {
                        $new = BoRole::create([
                            'name' => $data['name'],
                            'guard_name' => $record->guard_name,
                            'description' => $record->description,
                            'tenant_id' => $record->tenant_id,
                            'is_system' => false,
                        ]);
                        $new->permissions()->sync($record->permissions->pluck('id')->all());
                        self::flashCacheNotice();
                        Notification::make()->success()->title('Grup diduplikasi: '.$new->name)
                            ->body($record->permissions->count().' izin disalin.')->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (BoRole $record) => $record->is_system),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoRoles::route('/'),
            'create' => Pages\CreateBoRole::route('/create'),
            'edit' => Pages\EditBoRole::route('/{record}/edit'),
        ];
    }
}
