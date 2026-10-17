<?php

namespace App\Filament\System\Resources\UserResource\Pages;

use App\Filament\System\Resources\BoRoleResource;
use App\Filament\System\Resources\RoleResource;
use App\Filament\System\Resources\UserResource;
use App\Modules\BO\IproBridge\Services\IproMasterSync;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Hak khusus FO pilihan. null = matriksnya tidak dibuka (sakelar "Ubah hak akses khusus"
     * mati) — izin khusus yang ada dibiarkan, BUKAN dikosongkan.
     *
     * @var array<int,string>|null
     */
    protected ?array $pendingPerms = null;

    /** Potret hak khusus sebelum disimpan — sumber untuk mempertahankan yang di luar matriks. */
    protected $izinSebelumnya;

    /**
     * Grup akses BO pilihan, ditahan sampai afterSave.
     *
     * null berarti bidangnya memang tidak ditampilkan (tak ada akun BO dengan
     * email ini) — beda dari array kosong yang berarti "cabut semua grupnya".
     *
     * @var array<int,int|string>|null
     */
    protected ?array $grupBo = null;

    /**
     * Izin KHUSUS BO pilihan, ditahan sampai afterSave. Sama seperti $grupBo,
     * null berarti bidangnya tidak ditampilkan — bukan "kosongkan semuanya".
     *
     * @var array<int,int>|null
     */
    protected ?array $izinBo = null;

    protected function getHeaderActions(): array
    {
        return [
            // Sama dengan tombol "Sinkronkan ke BO" di daftar pengguna.
            Actions\Action::make('sinkronBo')->label('Sinkronkan ke BO')
                ->icon('heroicon-o-arrows-right-left')->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sinkronkan ke BO')
                ->modalDescription(fn () => 'Buat/selaraskan kembaran pengguna BO ber-email "'.$this->record->email.'" agar login SSO FO→BO cocok.')
                ->action(function () {
                    try {
                        $id = app(IproMasterSync::class)->syncUser($this->record);
                        $id
                            ? Notification::make()->success()->title('Tersinkron ke BO')
                                ->body('Pengguna BO (id '.$id.') untuk '.$this->record->email.' siap. Berlaku penuh setelah cache izin BO di-reset.')->send()
                            : Notification::make()->warning()->title('Dilewati')
                                ->body('Pengguna tanpa email tak dapat disinkron ke BO.')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Gagal sinkron ke BO')->body($e->getMessage())->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /** Muat hak akses khusus (direct permission) ke matrix. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $m = [];
        foreach ($this->record->getDirectPermissions() as $p) {
            $m[str_replace('.', '~', $p->name)] = true;
        }
        $data['m'] = $m;

        $bo = UserResource::penggunaBo($this->record);
        if ($bo) {
            $data['grup_bo'] = $bo->roles()->pluck('roles.id')->all();

            $khusus = [];
            foreach ($bo->permissions as $p) {
                $khusus[BoRoleResource::areaKeyFor($p->name, (string) $p->module)][] = $p->id;
            }
            $data['permBo'] = $khusus;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->izinSebelumnya = $this->record->getDirectPermissions();
        $this->pendingPerms = array_key_exists('m', $data) ? RoleResource::collectPermissions((array) $data['m']) : null;
        unset($data['m']);

        // grup_bo bukan kolom tabel users — kalau ikut lolos, Eloquent gagal menyimpan.
        $this->grupBo = array_key_exists('grup_bo', $data) ? (array) $data['grup_bo'] : null;
        $this->izinBo = array_key_exists('permBo', $data)
            ? BoRoleResource::collectPermissionIds((array) $data['permBo'])
            : null;
        unset($data['grup_bo'], $data['permBo']);

        /* Menyimpan Grup Akses BO = mengambil alih kepemilikannya.
         *
         * Ditetapkan DI SINI, bukan di afterSave(), supaya ikut dalam simpanan yang sama:
         * menulisnya belakangan akan memicu User::saved sekali lagi (sinkron berjalan dua
         * kali) dan membuka celah balapan dengan job yang sudah terlanjur dikirim.
         *
         * Hanya dinyalakan, tidak pernah dimatikan dari sini — mematikannya adalah
         * keputusan sadar lewat sakelarnya sendiri. */
        if ($this->grupBo !== null && empty($data['bo_roles_manual'])) {
            $data['bo_roles_manual'] = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Sama seperti Grup Akses: hak khusus yang tak punya kotak di matriks ini
        // (mis. izin BO) tidak boleh terhapus hanya karena halaman ini tak bisa
        // menampilkannya.
        if ($this->pendingPerms !== null) {
            $this->record->syncPermissions(
                RoleResource::gabungIzin($this->izinSebelumnya, $this->pendingPerms)
            );
        }

        // Grup akses BO ada di database lain (ipro_bo). Hanya disentuh bila
        // bidangnya benar-benar ditampilkan — kalau tidak, menyimpan pengguna FO
        // akan diam-diam mencabut grup BO orang tersebut.
        if ($this->grupBo !== null || $this->izinBo !== null) {
            $bo = UserResource::penggunaBo($this->record);
            if ($bo) {
                if ($this->grupBo !== null) {
                    $bo->roles()->sync($this->grupBo);
                }
                if ($this->izinBo !== null) {
                    $bo->permissions()->sync($this->izinBo);
                }
                // Peringatan yang sama seperti di halaman Grup Akses BO: app BO
                // memegang cache izinnya sendiri, jadi perubahan tidak langsung
                // terasa di sana.
                BoRoleResource::flashCacheNotice();
            }
        }
    }
}
