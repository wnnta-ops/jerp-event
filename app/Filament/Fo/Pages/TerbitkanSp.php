<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Collection\Services\SpLetterService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Terbitkan Surat Peringatan (SP) — halaman detail per kontrak (dibuka dari menu
 * Surat SP). Tampilkan identitas + pilih level + centang baris tunggakan/biaya +
 * isi denda manual → "Terbitkan & Cetak SP" membuat nomor resmi & membuka cetakan.
 */
class TerbitkanSp extends Page implements HasForms
{
    use MematuhiIzinHalaman;

    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.fo.pages.terbitkan-sp';

    protected static ?string $slug = 'collection/terbitkan-sp';

    public ?Kontrak $kontrak = null;

    public ?array $data = [];

    public function mount(): void
    {
        $this->kontrak = Kontrak::with(['customer', 'unit.unitType', 'unit.lokasi'])
            ->findOrFail(request()->integer('kontrak'));

        $c = $this->kontrak->customer;
        $this->form->fill([
            'level' => 'SP1',
            'tgl_surat' => now()->toDateString(),
            'nama' => $c?->full_name,
            'telp' => $c?->mobile ?: $c?->phone,
            'alamat' => $c ? $c->alamatRingkas() : null,
            'ref_spr' => $this->kontrak->no_kontrak
                .($this->kontrak->tgl_kontrak ? ' tertanggal '.$this->kontrak->tgl_kontrak->translatedFormat('d M Y') : ''),
            'items' => app(SpLetterService::class)->tunggakanRows($this->kontrak),
        ]);
    }

    public function getTitle(): string
    {
        return 'Terbitkan SP — '.($this->kontrak?->no_kontrak ?? '');
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Identitas')->columns(2)->schema([
                Forms\Components\Placeholder::make('no_kontrak_v')->label('No. Kontrak')
                    ->content(fn () => $this->kontrak?->no_kontrak),
                Forms\Components\Placeholder::make('unit_v')->label('Unit')
                    ->content(fn () => $this->kontrak?->unit?->kode_unit ?: $this->kontrak?->unit?->no_unit ?: '—'),
                Forms\Components\TextInput::make('nama')->label('Nama Customer')->required(),
                Forms\Components\TextInput::make('telp')->label('No. Telp / WhatsApp'),
                Forms\Components\TextInput::make('ref_spr')->label('Ref. SPR (No. & tanggal)')->columnSpanFull(),
                Forms\Components\Textarea::make('alamat')->label('Alamat')->rows(3)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Surat')->columns(2)->schema([
                Forms\Components\Radio::make('level')->label('Level Surat')->inline()->required()
                    ->options(['SP1' => 'SP 1', 'SP2' => 'SP 2', 'SP3' => 'SP 3', 'PEMBATALAN' => 'Pembatalan']),
                Forms\Components\DatePicker::make('tgl_surat')->label('Tanggal Surat')->required()->default(now()),
            ]),

            Forms\Components\Section::make('Tunggakan / Biaya')
                ->description('Centang baris yang dimasukkan ke surat SP. Isi Denda bila ada (default 0).')
                ->schema([
                    Forms\Components\Repeater::make('items')->label('')
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->columns(12)
                        ->schema([
                            Forms\Components\Hidden::make('tagihan_id'),
                            Forms\Components\Hidden::make('tgl'),
                            Forms\Components\Hidden::make('keterangan'),
                            Forms\Components\Hidden::make('tagihan'),
                            Forms\Components\Hidden::make('pembayaran'),
                            Forms\Components\Hidden::make('kekurangan'),
                            Forms\Components\Hidden::make('hari'),
                            Forms\Components\Toggle::make('sertakan')->label('Sertakan')->default(true)->columnSpan(2),
                            Forms\Components\Placeholder::make('info')->label('')->columnSpan(6)
                                ->content(fn (Forms\Get $get) => new HtmlString(
                                    '<div style="font-weight:600">'.e($get('keterangan')).'</div>'
                                    .'<div style="font-size:11px;color:#888">JT '.e($get('tgl') ?: '—')
                                    .' · Sisa Rp '.number_format((float) $get('kekurangan'), 0, ',', '.')
                                    .' · telat '.(int) $get('hari').' hari</div>'
                                )),
                            Forms\Components\TextInput::make('denda')->label('Denda (Rp)')->numeric()->rupiah()->default(0)->columnSpan(4),
                        ]),
                ]),
        ]);
    }

    public function cetak(): void
    {
        $data = $this->form->getState();

        $dipilih = collect($data['items'] ?? [])->filter(fn ($r) => (bool) ($r['sertakan'] ?? false));
        if ($dipilih->isEmpty()) {
            Notification::make()->warning()->title('Belum ada tunggakan dipilih')
                ->body('Centang minimal satu baris tunggakan untuk dimasukkan ke surat.')->send();

            return;
        }

        $sp = app(SpLetterService::class)->terbitkan($this->kontrak, $data);

        Notification::make()->success()->title('SP diterbitkan')
            ->body('Nomor '.$sp->no_surat.' — membuka cetakan…')->send();

        $this->redirect(route('fo.cetak.sp', $sp));
    }
}
