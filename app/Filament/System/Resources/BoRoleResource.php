<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\BoRoleResource\Pages;
use App\Filament\System\Support\PetaMenuBo;
use App\Models\Bo\BoPermission;
use App\Models\Bo\BoRole;
use Filament\Forms;
use Filament\Forms\Components\Component;
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
                ->description('Disusun seperti menu BO: Modul → Sub-modul → Sub-sub-modul → aksi. Tiap sub-modul punya tombol Pilih semua. Tersimpan ke DB ipro_bo; berlaku di aplikasi BO paling lama 1 menit kemudian.')
                ->schema(self::permissionMatrixBo()),
        ]);
    }

    /**
     * Seksi matriks = MENU BO yang sebenarnya, tiga tingkat seperti sidebarnya:
     * Modul → Sub-modul → Sub-sub-modul. Petanya ada di PetaMenuBo, satu berkas
     * tersendiri karena isinya 174 baris data — bukan logika.
     */

    /** Label aksi (segmen terakhir permission) dalam Bahasa Indonesia. */
    private const ACTION_LABELS = [
        'view' => 'Lihat', 'show' => 'Lihat', 'index' => 'Lihat', 'list' => 'Lihat', 'read' => 'Lihat',
        'create' => 'Buat', 'store' => 'Buat', 'add' => 'Buat', 'registrasi' => 'Registrasi',
        'update' => 'Ubah', 'edit' => 'Ubah', 'repair' => 'Perbaiki Data',
        'delete' => 'Hapus', 'destroy' => 'Hapus', 'remove' => 'Hapus',
        'approve' => 'Setujui', 'reject' => 'Tolak', 'verify' => 'Verifikasi', 'confirm' => 'Konfirmasi',
        'print' => 'Cetak', 'export' => 'Ekspor', 'download' => 'Unduh', 'import' => 'Impor',
        'upload' => 'Unggah', 'excel' => 'Excel', 'pdf' => 'PDF', 'report' => 'Laporan',
        'manage' => 'Kelola', 'request' => 'Ajukan', 'submit' => 'Ajukan', 'merge' => 'Gabungkan',
        'pay' => 'Bayar', 'void' => 'Void', 'cancel' => 'Batal', 'close' => 'Tutup', 'reopen' => 'Buka Lagi',
        'issue' => 'Terbitkan', 'release' => 'Rilis', 'post' => 'Posting', 'posting' => 'Posting',
        'reverse' => 'Reversal', 'finalize' => 'Finalisasi', 'settle' => 'Lunasi', 'allocate' => 'Alokasikan',
        'toggle' => 'Aktif/Nonaktif', 'assign' => 'Tetapkan', 'promote' => 'Promosikan', 'reschedule' => 'Jadwal Ulang',
        'override' => 'Lintas Unit Kerja', 'anonymize' => 'Anonimkan', 'sensitive' => 'Data Sensitif',
        'block' => 'Blokir', 'year' => 'Tutup Tahun', 'recognize' => 'Akui',

        // ── Aksi khas Bon Sementara ──
        // Dulu tampil mentah ("Realize", "Received Date", "Print Realisasi") sehingga
        // tidak terbaca bahwa ketiganya milik tiga pihak yang berbeda.
        'realize' => 'Realisasi', 'refund' => 'Refund', 'reimburse' => 'Reimburse',
        'received-date' => 'Tgl Terima Dokumen', 'print-realisasi' => 'Cetak Realisasi', 'card' => 'Kartu',

        // ── Aksi khas Cek Giro ──
        'clear' => 'Cair', 'bounce' => 'Tolakan', 'handover' => 'Serah Terima',

        // ── Aksi khas KPR & lain-lain ──
        'hold' => 'Tahan', 'schedule' => 'Jadwalkan', 'complete' => 'Selesaikan',
        'register' => 'Daftarkan', 'use' => 'Pakai', 'return' => 'Kembalikan',
        'numerator' => 'Penomoran', 'parameter' => 'Parameter', 'activate' => 'Aktifkan',
        'bulk_import' => 'Impor Massal', 'payment' => 'Pembayaran', 'price' => 'Harga',
    ];

    /** Urutan aksi umum agar kolom konsisten antar-baris (mirip FO). */
    private const ACTION_ORDER = [
        'view', 'show', 'index', 'list', 'create', 'store', 'update', 'edit',
        'delete', 'destroy', 'approve', 'reject', 'print', 'export',
    ];

    /**
     * Matriks izin BO — TIGA TINGKAT, persis sidebar BO:
     *
     *     Modul            seksi yang bisa dilipat   (Cash Book)
     *       Sub-modul      kelompok di dalamnya      (Transaksi Bon Sementara)
     *         Sub-sub-modul + centang aksinya        (Bon Sementara · Lihat Buat Setujui …)
     *
     * Sebelumnya hanya dua tingkat, dan nama barisnya diambil dari potongan nama izin —
     * "Sip", "Advance", "Bankrec", "Cheque Out". Orang yang menyetel peran harus menebak
     * izin mana untuk menu mana, dan tebakan yang salah di layar hak akses berarti
     * seseorang memegang wewenang yang tidak dimaksudkan. Nama menunya kini diambil dari
     * PetaMenuBo, yang ditelusuri dari menu → route → controller aplikasi BO sendiri.
     *
     * SATU IZIN TETAP SATU CENTANG dan kunci state-nya TIDAK berubah
     * (`{awalan}.{modul}__{area}`) — yang berubah hanya susunan & namanya di layar,
     * sehingga izin yang sudah tersimpan tetap terbaca apa adanya.
     *
     * @param  string  $awalanState  Prefix state form. Halaman Grup Akses BO memakai
     *                               'perm' (izin milik GRUP); halaman Pengguna memakai
     *                               'permBo' (izin KHUSUS satu orang). Dipisah supaya
     *                               dua matriks bisa hidup di satu form tanpa saling
     *                               menimpa isian.
     * @return array<int,Component>
     */
    public static function permissionMatrixBo(string $awalanState = 'perm'): array
    {
        $perms = BoPermission::query()->orderBy('module')->orderBy('name')
            ->get(['id', 'name', 'module', 'label']);

        // modul => sub-modul => nama menu => [ ['id'=>.., 'action'=>..], ... ]
        $pohon = [];
        // Kunci state tiap baris, supaya tombol "Pilih semua" se-sub-modul tahu
        // field mana saja yang harus diisinya.
        $kunci = [];

        foreach ($perms as $p) {
            [$module, $area, $action] = self::splitPerm($p->name, (string) $p->module);
            $kelompok = $module.'.'.$area;
            [$modul, $sub, $menu] = PetaMenuBo::untuk($kelompok, self::humanizeArea($area));

            $pohon[$modul][$sub][$menu][] = ['id' => $p->id, 'action' => $action];
            $kunci[$modul][$sub][$menu] = $awalanState.'.'.self::areaKey($module, $area);
        }

        uksort($pohon, fn ($a, $b) => [PetaMenuBo::urutanModul((string) $a), (string) $a]
            <=> [PetaMenuBo::urutanModul((string) $b), (string) $b]);

        $sections = [];
        foreach ($pohon as $modul => $subModul) {
            ksort($subModul);
            $isi = [];
            $jml = 0;

            foreach ($subModul as $sub => $menus) {
                ksort($menus);
                $baris = [];
                // nama field => semua id izin di bawahnya; dipakai aksi Pilih semua.
                $semua = [];

                foreach ($menus as $menu => $items) {
                    usort($items, fn ($a, $b) => self::actionRank($a['action']) <=> self::actionRank($b['action']));
                    $options = [];
                    foreach ($items as $it) {
                        $options[$it['id']] = self::actionLabel($it['action']);
                    }
                    $jml += count($options);

                    $field = $kunci[$modul][$sub][$menu];
                    $semua[$field] = array_keys($options);

                    $baris[] = Forms\Components\Grid::make(12)->schema([
                        Forms\Components\Placeholder::make('lbl_'.Str::slug($awalanState.'-'.$modul.'-'.$sub.'-'.$menu, '_'))
                            ->label('')->content($menu)->columnSpan(3),
                        Forms\Components\CheckboxList::make($field)
                            ->label('')->options($options)->columns(6)->gridDirection('row')
                            ->bulkToggleable()->columnSpan(9),
                    ]);
                }

                // Sub-modul tanpa nama = modul yang memang tidak bertingkat dua di sidebar
                // (Master, Data Center, Inventory, …). Barisnya menempel langsung ke modul;
                // membungkusnya dalam kotak kosong hanya menambah tingkat yang tidak ada.
                if ((string) $sub === '') {
                    $isi = array_merge($isi, $baris);

                    continue;
                }

                $isi[] = Forms\Components\Section::make((string) $sub)
                    ->compact()
                    ->headerActions([
                        Forms\Components\Actions\Action::make('semua_'.Str::slug($awalanState.'-'.$modul.'-'.$sub, '_'))
                            ->label('Pilih semua')->link()->size('sm')
                            ->action(function (Forms\Set $set) use ($semua) {
                                foreach ($semua as $field => $ids) {
                                    $set($field, $ids);
                                }
                            }),
                        Forms\Components\Actions\Action::make('kosong_'.Str::slug($awalanState.'-'.$modul.'-'.$sub, '_'))
                            ->label('Kosongkan')->link()->size('sm')->color('gray')
                            ->action(function (Forms\Set $set) use ($semua) {
                                foreach ($semua as $field => $ids) {
                                    $set($field, []);
                                }
                            }),
                    ])
                    ->schema($baris);
            }

            $sections[] = Forms\Components\Section::make((string) $modul)
                ->description(count($subModul).' sub-modul · '.$jml.' izin')
                ->collapsible()->collapsed()
                ->schema($isi);
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
            ->success()
            ->title('Tersimpan ke database BO')
            ->body('Berlaku di aplikasi BO dalam waktu paling lama 1 menit — cukup muat ulang halamannya di sana.')
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
