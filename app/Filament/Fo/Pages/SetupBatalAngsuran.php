<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Settings\CollectionSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Collection → Setup Batal Angsuran.
 *
 * Mengatur biaya administrasi yang dipotong saat kontrak dibatalkan:
 *
 *   biaya administrasi = persen × ANGSURAN yang sudah dibayar
 *   dikembalikan       = angsuran dibayar − biaya administrasi
 *
 * Dasarnya angsuran, BUKAN harga unit — yang dipotong uang yang sudah masuk. Perhitungan
 * sesungguhnya ada di BiayaBatalAngsuran; layar ini hanya menyimpan angkanya, supaya tidak
 * ada rumus kedua yang bisa berbeda.
 *
 * Persen per Cluster+Tahap disimpan di kolom fo_tahap.persen_batal_admin. fo_tahap memang
 * sudah satu baris per kombinasi itu, jadi daftarnya di bawah tidak dikarang layar ini —
 * ia menampilkan apa adanya yang ada di master, dan otomatis ikut bila Setup Unit bertambah.
 *
 * Kolom yang DIKOSONGKAN berarti "ikut persen bawaan", bukan nol persen. Bedanya nyata:
 * nol persen berarti seluruh setoran dikembalikan utuh.
 */
class SetupBatalAngsuran extends Page implements HasForms
{
    use InteractsWithForms;
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Setup Batal Angsuran';

    protected static ?string $navigationLabel = 'Setup Batal Angsuran';

    protected static ?string $navigationGroup = 'Collection';

    protected static string $view = 'filament.fo.pages.setup-batal-angsuran';

    protected static ?string $slug = 'collection-setup-batal-angsuran';

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(CollectionSettings::class);

        $this->form->fill([
            'batal_admin_persen' => $s->batal_admin_persen,
            'tahap' => Tahap::with('lokasi')->orderBy('lokasi_id')->orderBy('kode')->get()
                ->map(fn (Tahap $t) => [
                    'id' => $t->id,
                    'label' => trim(($t->lokasi?->kode ?? '—').'  ·  Tahap '.$t->kode),
                    'persen' => $t->persen_batal_admin,
                ])->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Persen Bawaan')
                ->description('Dipakai untuk Cluster+Tahap yang persennya tidak diisi di bawah.')
                ->schema([
                    Forms\Components\TextInput::make('batal_admin_persen')
                        ->label('Biaya administrasi')
                        ->numeric()->required()->minValue(0)->maxValue(100)
                        ->suffix('%')
                        ->helperText('Dihitung dari ANGSURAN yang sudah dibayar (tagihan tipe ANG) — bukan dari harga unit. Uang muka, booking fee, dan biaya administrasi tagihan tidak ikut dihitung.'),
                ]),

            Forms\Components\Section::make('Persen per Cluster & Tahap')
                ->description('Kosongkan untuk mengikuti persen bawaan. Isi 0 bila memang tanpa potongan — keduanya berbeda arti. Daftar ini mengikuti master Setup Unit → Tahap.')
                ->schema([
                    Forms\Components\Repeater::make('tahap')
                        ->hiddenLabel()
                        // Barisnya cerminan master Tahap, bukan daftar yang dikarang di sini:
                        // menambah/menghapus dari layar ini akan membuat keduanya berbeda.
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->columns(3)
                        ->schema([
                            Forms\Components\Hidden::make('id'),
                            Forms\Components\Placeholder::make('label')
                                ->hiddenLabel()->columnSpan(2)
                                ->content(fn (Forms\Get $get) => $get('label')),
                            Forms\Components\TextInput::make('persen')
                                ->hiddenLabel()
                                ->numeric()->minValue(0)->maxValue(100)
                                ->suffix('%')
                                ->placeholder('ikut bawaan'),
                        ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $s = app(CollectionSettings::class);
        $s->batal_admin_persen = (float) $data['batal_admin_persen'];
        $s->save();

        $diisi = 0;
        foreach ((array) ($data['tahap'] ?? []) as $baris) {
            $t = Tahap::find($baris['id'] ?? null);
            if (! $t) {
                continue;
            }

            // '' dan null sama-sama berarti "ikut bawaan"; 0 berarti nol persen.
            $p = $baris['persen'];
            $t->persen_batal_admin = ($p === null || $p === '') ? null : (float) $p;
            $t->save();

            $diisi += $t->persen_batal_admin !== null ? 1 : 0;
        }

        Notification::make()->success()
            ->title('Setup Batal Angsuran disimpan')
            ->body('Persen bawaan '.$data['batal_admin_persen'].'%'
                .($diisi > 0 ? ", dengan {$diisi} Cluster+Tahap berpersen khusus." : '.'))
            ->send();
    }
}
