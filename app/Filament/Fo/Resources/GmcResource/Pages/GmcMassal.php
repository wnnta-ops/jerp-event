<?php

namespace App\Filament\Fo\Resources\GmcResource\Pages;

use App\Filament\Fo\Resources\GmcResource;
use App\Modules\FO\FinanceAR\Models\Gmc;
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
                        Forms\Components\DatePicker::make('tgl')->label('Tgl')->default(now())->required(),
                        // Server-side search: ketik nama customer / no. SPR / kode unit → hasil muncul
                        // (tak terbatas 300 pertama, mempercepat pencarian).
                        Forms\Components\Select::make('kontrak_id')->label('SPR / Kontrak (— Customer — Unit)')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Kontrak::with(['customer', 'unit'])
                                ->where(fn ($q) => $q->where('no_kontrak', 'like', "%{$search}%")
                                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%"))
                                    ->orWhereHas('unit', fn ($u) => $u->where('kode_unit', 'like', "%{$search}%")))
                                ->latest('id')->limit(50)->get()
                                ->mapWithKeys(fn ($k) => [$k->id => self::kontrakLabel($k)])->toArray())
                            ->getOptionLabelUsing(fn ($value) => ($k = Kontrak::with(['customer', 'unit'])->find($value)) ? self::kontrakLabel($k) : null),
                        Forms\Components\Select::make('nama')->label('Nama Gimmick')->required()
                            ->options($this->gimmickOptions()),
                        Forms\Components\TextInput::make('nilai')->label('Nilai / Biaya (Rp)')->numeric()->rupiah()->default(0)->required(),
                    ])
                    ->columns(4)->defaultItems(1)->addActionLabel('+ Tambah baris')->reorderable(false)
                    ->helperText('Pilih SPR (nama customer tampil di daftar pilihan). Tambah baris untuk banyak GMC sekaligus.'),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $rows = $this->form->getState()['rows'] ?? [];
        $n = 0;
        foreach ($rows as $row) {
            if (empty($row['kontrak_id']) || empty($row['nama'])) {
                continue;
            }
            $k = Kontrak::find($row['kontrak_id']);
            Gmc::create([
                'tgl' => $row['tgl'] ?? now(),
                'kontrak_id' => $row['kontrak_id'],
                'customer_id' => $k?->customer_id,
                'nama' => $row['nama'],
                'nilai' => $row['nilai'] ?? 0,
                'status' => 'DRAFT',
            ]);
            $n++;
        }

        Notification::make()->success()->title($n.' GMC dibuat')
            ->body('GMC massal tersimpan (status DRAFT). Lakukan Posting BO bila perlu.')->send();

        $this->redirect(GmcResource::getUrl('index'));
    }

    /** Label SPR/Kontrak: "No. SPR — Customer — Unit". */
    private static function kontrakLabel(Kontrak $k): string
    {
        return trim($k->no_kontrak.' — '.($k->customer?->full_name ?? '').' — '.($k->unit?->kode_unit ?? ''), ' —');
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
