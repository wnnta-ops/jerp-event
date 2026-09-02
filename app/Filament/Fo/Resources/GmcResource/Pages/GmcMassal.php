<?php

namespace App\Filament\Fo\Resources\GmcResource\Pages;

use App\Filament\Fo\Resources\GmcResource;
use App\Modules\FO\FinanceAR\Models\Gmc;
use App\Modules\FO\FinanceAR\Models\GmcItem;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * GMC Massal — buat banyak GMC sekaligus lewat repeater baris. HALAMAN tersendiri
 * (bukan modal) + Select NATIVE tanpa reaktivitas → tak ada Livewire Entangle Error.
 *
 * PENTING: opsi SPR & Gimmick di-MEMOIZE (dihitung SEKALI per request), BUKAN per-baris
 * per-render. Tanpa ini, tiap klik tambah/hapus menjalankan query (termasuk cross-DB
 * ipro_bo) berkali-kali → request Livewire lambat/nyangkut (hapus baris gagal).
 */
class GmcMassal extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = GmcResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.fo.resources.gmc.massal';

    protected static ?string $title = 'Buat GMC Massal';

    /** @var array<string,mixed>|null */
    public ?array $data = [];

    /** @var array<string,string>|null Memo opsi Nama Gimmick dari BO (sekali per request). */
    protected ?array $gimmickOpts = null;

    /** @var array<int,string>|null Memo opsi Supplier dari BO (sekali per request). */
    protected ?array $supplierOpts = null;


    public function mount(): void
    {
        // Kosong — Repeater ->defaultItems(1) yang membuat baris awal ber-UUID benar.
        // Guard anti-resurrection ditangani global (App\Support\Livewire\RepeaterResurrectionGuard).
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Repeater::make('rows')->label('Daftar GMC')
                    ->schema([
                        Forms\Components\DatePicker::make('tgl')->label('Tgl')->default(now())->required()
                            ->columnSpan(1),
                        // Server-side search: ketik nama customer / no. SPR / kode unit → hasil muncul
                        // (tak terbatas 300 pertama, mempercepat pencarian).
                        Forms\Components\Select::make('kontrak_id')->label('SPR / Kontrak (— Customer — Unit)')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Kontrak::with(['customer', 'unit'])
                                ->where(fn ($q) => $q->where('no_kontrak', 'like', "%{$search}%")
                                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"))
                                    ->orWhereHas('unit', fn ($u) => $u->where('kode_unit', 'like', "%{$search}%")))
                                ->latest('id')->limit(50)->get()
                                ->mapWithKeys(fn ($k) => [$k->id => self::kontrakLabel($k)])->toArray())
                            ->getOptionLabelUsing(fn ($value) => ($k = Kontrak::with(['customer', 'unit'])->find($value)) ? self::kontrakLabel($k) : null)
                            ->columnSpan(2),
                        Forms\Components\Select::make('nama')->label('Nama Gimmick')->required()
                            ->options($this->gimmickOptions())->columnSpan(1),
                        // Supplier = master Supplier Center BO (bs_suppliers @ ipro_bo), sumber yang
                        // SAMA dengan Rincian Komponen pada form GMC tunggal — bukan daftar kedua
                        // yang bisa berbeda isinya.
                        //
                        // searchable() memang dipakai di sini walau halaman ini sengaja memilih
                        // Select native: ada 762 supplier aktif, dan daftar sepanjang itu tanpa
                        // pencarian praktis tidak bisa dipakai. Preseden yang sama sudah berjalan
                        // pada Repeater "Rincian Komponen" di GmcResource — Select supplier yang
                        // persis ini. Yang TIDAK dipakai adalah reaktivitas (->live()), karena
                        // itulah yang dulu memicu Livewire Entangle Error di halaman ini.
                        Forms\Components\Select::make('supplier_id')->label('Supplier')->required()->searchable()
                            ->options($this->supplierOptions())->columnSpan(1),
                        // live(onBlur) DIPAKAI di sini, dan HANYA di sini — untuk menyalakan baris
                        // total di bawah repeater.
                        //
                        // Ini pelanggaran sempit yang disengaja terhadap aturan "tanpa reaktivitas"
                        // di docblock kelas, dan batasnya penting: yang direaktifkan adalah TextInput
                        // angka biasa, bukan Select. Yang dulu memicu Livewire Entangle Error adalah
                        // Select searchable/live di dalam repeater — Select di halaman ini tetap
                        // tidak reaktif. onBlur (bukan per ketikan) menahan round-trip Livewire
                        // sampai kursor pindah field, jadi mengetik angka tidak menjalankan render
                        // ulang seluruh baris.
                        Forms\Components\TextInput::make('nilai')->label('Nilai / Biaya (Rp)')->numeric()->rupiah()->default(0)->required()
                            ->live(onBlur: true)
                            ->columnSpan(1),
                        // Keterangan disejajarkan dengan form GMC tunggal, yang sudah punya field ini.
                        // Tanpa itu GMC yang dibuat massal selalu berketerangan kosong, dan satu-satunya
                        // cara mengisinya adalah menyunting ulang satu per satu setelah tersimpan.
                        Forms\Components\TextInput::make('keterangan')->label('Keterangan')
                            ->maxLength(255)->columnSpan(6),
                    ])
                    ->columns(6)->defaultItems(1)->addActionLabel('+ Tambah baris')->reorderable(false)
                    ->helperText('Pilih SPR (nama customer tampil di daftar pilihan) dan Supplier penerima biayanya. Tambah baris untuk banyak GMC sekaligus.'),

                // Jaring pengaman salah ketik. Tiap baris di sini hanya punya satu komponen,
                // jadi nilai baris ADALAH totalnya — yang tidak bisa dilihat orang adalah
                // jumlah seluruh baris. Nol digit yang kelebihan pada satu baris tenggelam di
                // antara belasan baris lain, dan baru ketahuan setelah GMC-nya terlanjur dibuat.
                Forms\Components\Placeholder::make('total_massal')->label('Total seluruh baris')
                    ->content(function (Forms\Get $get): string {
                        $rows = collect($get('rows') ?? []);
                        $sah = $rows->filter(fn ($r) => filled($r['kontrak_id'] ?? null)
                            && filled($r['nama'] ?? null) && filled($r['supplier_id'] ?? null));
                        $total = $sah->sum(fn ($r) => (float) str_replace('.', '', (string) ($r['nilai'] ?? 0)));
                        $lewat = $rows->count() - $sah->count();

                        // Kalimat barisnya HARUS menyebut akibat yang benar. Field pada repeater
                        // ini required(), sehingga baris yang belum lengkap MENAHAN penyimpanan
                        // lewat validasi — bukan dilewati diam-diam. Menulis "tidak akan disimpan"
                        // membuat orang menekan Buat Semua sambil mengira sisanya tetap tersimpan,
                        // lalu bingung ketika tidak terjadi apa-apa.
                        return $sah->count().' GMC — Rp '.number_format($total, 0, ',', '.')
                            .($lewat > 0 ? '  ·  '.$lewat.' baris belum lengkap — lengkapi atau hapus dulu' : '');
                    }),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $rows = $this->form->getState()['rows'] ?? [];
        $n = 0;
        $lewat = 0;
        foreach ($rows as $row) {
            if (empty($row['kontrak_id']) || empty($row['nama']) || empty($row['supplier_id'])) {
                $lewat++;

                continue;
            }
            $k = Kontrak::with('unit')->find($row['kontrak_id']);
            $gmc = Gmc::create([
                'tgl' => $row['tgl'] ?? now(),
                'kontrak_id' => $row['kontrak_id'],
                'customer_id' => $k?->customer_id,
                // Cluster tidak ditanyakan — selalu dari unit pada SPR-nya.
                'lokasi_id' => $k?->unit?->lokasi_id,
                'nama' => $row['nama'],
                'nilai' => $row['nilai'] ?? 0,
                'keterangan' => $row['keterangan'] ?? null,
                'status' => 'DRAFT',
            ]);

            // Supplier disimpan sebagai RINCIAN KOMPONEN, bukan kolom baru di fo_gmc.
            //
            // Alasannya bukan kerapian: jalur Posting BO sudah membaca komponen dan mengirim
            // satu baris antrian per komponen "ke supplier masing-masing"
            // (GmcResource::postGmcToBo). Menaruh supplier di kolom baru pada fo_gmc berarti
            // supplier bisa berada di DUA tempat, dan jalur posting itu harus memilih salah
            // satu — persis cara sebuah GMC berakhir tanpa supplier di hutangnya tanpa satu
            // pun galat. Dengan komponen, supplier yang dipilih di sini benar-benar sampai ke
            // AP, memakai jalur yang sudah teruji.
            $gmc->items()->create([
                'nama' => $row['nama'],
                'supplier_id' => (int) $row['supplier_id'],
                'nilai' => $row['nilai'] ?? 0,
                'urutan' => 1,
            ]);
            $n++;
        }

        // Baris tak lengkap DILAPORKAN, bukan dilewati diam-diam. Notifikasi yang hanya
        // menyebut jumlah berhasil membuat baris yang hilang terbaca seperti tidak pernah
        // diisi — pola pelaporan ok/dilewati ini sudah dipakai Posting BO massal.
        Notification::make()->{$lewat > 0 ? 'warning' : 'success'}()
            ->title($n.' GMC dibuat'.($lewat > 0 ? ', '.$lewat.' baris dilewati' : ''))
            ->body('GMC massal tersimpan (status DRAFT). Lakukan Posting BO bila perlu.'
                .($lewat > 0 ? ' Baris yang dilewati belum lengkap SPR / gimmick / supplier-nya.' : ''))
            ->send();

        $this->redirect(GmcResource::getUrl('index'));
    }

    /** Label SPR/Kontrak: "No. SPR — Customer — Unit". */
    private static function kontrakLabel(Kontrak $k): string
    {
        return trim($k->no_kontrak.' — '.($k->customer?->full_name ?? '').' — '.($k->unit?->kode_unit ?? ''), ' —');
    }

    /**
     * Opsi Supplier dari master Supplier Center BO — MEMOIZE per request.
     *
     * Sengaja MEMINJAM GmcItem::supplierOptions() alih-alih query sendiri: daftar supplier di
     * halaman ini harus sama persis dengan yang di Rincian Komponen GMC tunggal. Dua query
     * terpisah adalah cara paling mudah keduanya lambat laun menyaring hal yang berbeda
     * (mis. satu ikut supplier nonaktif, satunya tidak) tanpa ada yang menyadarinya.
     *
     * Memo-nya ganda dan itu disengaja: GmcItem menyimpan memo statis per request, dan di sini
     * disalin lagi ke properti komponen. Repeater memanggil schema-nya berkali-kali per render,
     * dan halaman ini memang dibangun dengan aturan "tak ada query per baris" — lihat docblock
     * kelas.
     */
    protected function supplierOptions(): array
    {
        return $this->supplierOpts ??= GmcItem::supplierOptions();
    }

    /** Opsi Nama Gimmick dari master Kategori Gimmick BO — MEMOIZE per request (cross-DB sekali saja). */
    protected function gimmickOptions(): array
    {
        if ($this->gimmickOpts !== null) {
            return $this->gimmickOpts;
        }
        try {
            return $this->gimmickOpts = DB::connection(config('ipro.connection', 'ipro_bo'))
                ->table('bs_gimmick_categories')->where('is_active', true)
                ->orderBy('name')->pluck('name', 'name')->toArray();
        } catch (\Throwable $e) {
            return $this->gimmickOpts = [];
        }
    }
}
