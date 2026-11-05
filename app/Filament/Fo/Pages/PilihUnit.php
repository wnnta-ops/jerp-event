<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Filament\Fo\Resources\PilihUnitSetupResource;
use App\Models\Project;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\PilihUnitGiliran;
use App\Modules\FO\Launching\Services\PilihUnitService;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Support\Akses;
use App\Support\PesanGagal;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * Pilih Unit — workflow hari-H launching:
 *   1. Operator pilih cluster (project).
 *   2. Siteplan (Leaflet.js) + kamera barcode scanner muncul.
 *   3. Scan barcode customer (no_nup) → tampil data customer + button.
 *   4. Click "Pilih Unit" → countdown 2 menit aktif.
 *   5. Klik unit di siteplan dalam 2 menit → atomic lock unit + popup sukses.
 *   6. Timer habis → reset ke step 3 (tetap di customer yang sama).
 *   7. Click Done di popup → reset ke step 3 (scan barcode lagi).
 *
 * Setup Pilih Unit (per cluster) bisa menyalakan giliran HP — HP memilih nomornya sendiri,
 * hanya HP yang bergiliran boleh memilih unit — dan mengatur/mematikan batas waktu.
 */
class PilihUnit extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $title = 'Pilih Unit';

    protected static ?string $slug = 'pilih-unit';

    protected static string $view = 'filament.fo.pages.pilih-unit';

    protected static bool $shouldRegisterNavigation = false;

    /** Lokasi (cluster) yang sedang aktif. Null = step pick cluster. */
    public ?int $clusterId = null;

    /** Project dari cluster aktif — utk channel real-time + lookup. */
    public ?int $projectId = null;

    /** Nama cluster (Lokasi) yang sedang aktif. */
    public ?string $clusterName = null;

    /** State machine phase: 'scan' | 'customer' | 'choosing' | 'done' */
    public string $phase = 'scan';

    /** Hasil scan barcode → NupRegistration id. */
    public ?int $registrationId = null;

    public ?string $regNoNup = null;

    public ?string $regCustomerName = null;

    public ?string $regCustomerAddress = null;

    public ?string $regCustomerCity = null;

    public ?string $regTipeNup = null;

    public ?string $regPilihanUnit1 = null;

    public ?string $regPilihanUnit2 = null;

    public ?string $regPilihanUnit3 = null;

    /** Referensi dari Verifikasi Data — ditampilkan sesudah scan barcode. */
    public ?string $regRefSales = null;

    public ?string $regRefPhone = null;

    public ?string $regSumberData = null;

    public ?string $regEvent = null;

    public ?string $regCaraBayar = null;

    /** Hasil pemilihan unit (popup). */
    public ?string $pickedUnitLabel = null;

    public ?string $pickedUnitAlamat = null;

    public ?string $pickError = null;

    /** Nomor HP ini di giliran (Setup Pilih Unit). Null = belum memilih / giliran nonaktif. */
    #[Locked]
    public ?int $hpNomor = null;

    /** Penanda HP ini saat memegang nomor HP (satu nomor = satu HP). */
    #[Locked]
    public string $hpToken = '';

    #[Locked]
    public bool $giliranAktif = false;

    #[Locked]
    public bool $timerAktif = true;

    #[Locked]
    public int $timerMenit = 2;

    public function mount(): void
    {
        $this->reset(['clusterId', 'clusterName', 'phase', 'registrationId', 'regNoNup', 'regCustomerName',
            'regCustomerAddress', 'regCustomerCity', 'regTipeNup', 'regPilihanUnit1',
            'regPilihanUnit2', 'regPilihanUnit3', 'pickedUnitLabel', 'pickError']);
        $this->phase = 'scan';
        $this->hpToken = Str::random(24);
    }

    public function selectCluster(int $lokasiId): void
    {
        $lokasi = Lokasi::find($lokasiId);
        if (! $lokasi) {
            return;
        }
        $this->clusterId = $lokasi->id;          // = lokasi_id
        $this->projectId = $lokasi->project_id;  // utk channel real-time + lookup
        $this->clusterName = $lokasi->nama;
        $this->phase = 'scan';
        $this->resetCustomer();
        $this->hpNomor = null;
        $this->muatSetup();
    }

    public function backToClusters(): void
    {
        $this->lepasHp();
        $this->clusterId = null;
        $this->projectId = null;
        $this->clusterName = null;
        $this->resetCustomer();
    }

    public function scanBarcode(string $barcode): void
    {
        // Hanya proses saat fase 'scan'. Scanner kamera kadang masih aktif &
        // terus membaca QR yg sama setelah customer ter-load → tanpa guard ini
        // terjadi banjir scanBarcode + fase choosing↔customer bolak-balik.
        // Untuk scan customer baru, user klik "Scan Ulang" (→ fase 'scan').
        if ($this->phase !== 'scan') {
            return;
        }
        $this->resetCustomer();

        try {
            $reg = app(PilihUnitService::class)->lookupByBarcode($barcode, $this->clusterId);
        } catch (\Throwable $e) {
            $this->pickError = $e->getMessage();
            Notification::make()->danger()->title('Scan gagal')->body($e->getMessage())->send();
            // Fase tetap 'scan' → $watch tak terpicu; beri tahu JS agar kamera menyala lagi.
            $this->dispatch('pu-scan-gagal');

            return;
        }

        $c = $reg->customer;
        $this->registrationId = $reg->id;
        $this->regNoNup = $reg->no_nup;
        // Master Customer = sumber kebenaran nama (perbaikan typo langsung berlaku di sini).
        $this->regCustomerName = $c?->full_name ?: ($reg->nama_pendaftar ?: '—');
        $this->regCustomerAddress = $c?->jalan ?: ($c?->address ?: ($reg->keterangan ?: '—'));
        $this->regCustomerCity = $c
            ? (trim(implode(', ', array_filter([$c->kelurahan, $c->kecamatan, $c->city, $c->province]))) ?: '—')
            : '—';
        $this->regTipeNup = $reg->tipe_nup ?: 'Reguler';
        $this->regPilihanUnit1 = $reg->pilihan_unit_1;
        $this->regPilihanUnit2 = $reg->pilihan_unit_2;
        $this->regPilihanUnit3 = $reg->pilihan_unit_3;

        // Referensi (dari Verifikasi Data) — supaya operator hari-H tahu unit ini
        // datang lewat sales/agen mana tanpa membuka menu lain.
        $this->regRefSales = $reg->agent?->sales_label ?: ($reg->agent?->name ?: ($reg->ref_sales_name ?: null));
        $this->regRefPhone = $reg->ref_sales_phone ?: ($reg->agent?->phone ?: null);
        $this->regSumberData = $c?->sumber_data_label ?: null;
        $this->regEvent = $reg->event?->name ?: null;
        $this->regCaraBayar = $reg->cara_bayar ?: null;

        $this->phase = 'customer';
        $this->dispatch('nup-customer-loaded', noNup: $reg->no_nup, name: $this->regCustomerName);
    }

    public function startChoosing(): void
    {
        if ($this->phase !== 'customer' || ! $this->registrationId) {
            return;
        }
        $this->muatSetup();
        try {
            $this->pastikanGiliranSaya();
        } catch (\RuntimeException $e) {
            Notification::make()->warning()->title('Menunggu giliran')->body($e->getMessage())->send();

            return;
        }
        $this->phase = 'choosing';
        $this->dispatch('nup-timer-start');
    }

    public function timeout(): void
    {
        // Waktu habis tanpa pilih → kembali ke layar customer info (giliran tetap di HP ini).
        if ($this->phase === 'choosing') {
            $this->phase = 'customer';
            Notification::make()->warning()->title('Waktu habis')
                ->body('Waktu '.$this->timerMenit.' menit habis. Kembali ke layar data customer.')->send();
        }
    }

    /** Kembali dari layar memilih tanpa memilih unit (perlu saat waktu tanpa batas). */
    public function batalPilih(): void
    {
        if ($this->phase === 'choosing') {
            $this->phase = 'customer';
        }
    }

    /** Giliran dipindah admin (Lanjut) saat HP ini sedang memilih. */
    public function giliranLepas(): void
    {
        if ($this->phase === 'choosing') {
            $this->phase = 'customer';
            Notification::make()->warning()->title('Giliran berpindah')
                ->body('Admin memindahkan giliran ke HP lain.')->send();
        }
    }

    public function pilihHp(int $hp): void
    {
        $this->muatSetup();
        if (! $this->giliranAktif || ! $this->clusterId) {
            return;
        }
        $g = app(PilihUnitGiliran::class);
        $lama = $this->hpNomor;
        if (! $g->pegang($this->clusterId, $hp, $this->hpToken)) {
            Notification::make()->danger()->title('HP '.$hp.' sudah dipakai')->body('Pilih nomor HP lain.')->send();

            return;
        }
        if ($lama && $lama !== $hp) {
            $g->lepas($this->clusterId, $lama, $this->hpToken);
        }
        $this->hpNomor = $hp;
    }

    /** Tombol Lanjut / Ulang ke HP 1 — hanya admin (yang boleh mengubah Setup Pilih Unit). */
    public function bolehAturGiliran(): bool
    {
        return Akses::boleh(Akses::izinUntuk(PilihUnitSetupResource::class, 'update'));
    }

    public function lanjutGiliran(): void
    {
        if (! $this->clusterId || ! $this->bolehAturGiliran()) {
            return;
        }
        $hp = app(PilihUnitGiliran::class)->lanjut($this->clusterId);
        Notification::make()->success()->title('Giliran sekarang HP '.$hp)->send();
    }

    public function ulangGiliran(): void
    {
        if (! $this->clusterId || ! $this->bolehAturGiliran()) {
            return;
        }
        app(PilihUnitGiliran::class)->ulang($this->clusterId);
        Notification::make()->success()->title('Giliran kembali ke HP 1')->send();
    }

    public function gantiHp(): void
    {
        if ($this->phase === 'choosing') {
            return;
        }
        $this->lepasHp();
    }

    /**
     * Dipanggil tiap beberapa detik dari halaman: memperpanjang pegangan nomor HP dan
     * mengembalikan status giliran untuk ditampilkan. Tanpa render ulang (peta tak terganggu).
     */
    #[Renderless]
    public function cekGiliran(): array
    {
        $g = app(PilihUnitGiliran::class);
        $st = $g->status($this->clusterId);
        // Pegangan habis lalu diambil HP lain (mis. layar tertidur lama) → perlu pilih ulang.
        $hilang = $st['aktif'] && $this->hpNomor && $this->clusterId
            && ! $g->pegang($this->clusterId, $this->hpNomor, $this->hpToken);
        $st['saya'] = $this->hpNomor;
        $st['berubah'] = $hilang
            || $this->giliranAktif !== $st['aktif']
            || $this->timerAktif !== $st['timer_aktif']
            || $this->timerMenit !== $st['timer_menit'];

        return $st;
    }

    /** Muat ulang setup sesudah cekGiliran melaporkan perubahan (render ulang). */
    public function segarkanSetup(): void
    {
        $this->muatSetup();
        if (! $this->giliranAktif) {
            $this->hpNomor = null;
        } elseif ($this->hpNomor && $this->clusterId
            && ! app(PilihUnitGiliran::class)->pegang($this->clusterId, $this->hpNomor, $this->hpToken)) {
            $this->hpNomor = null;
            Notification::make()->warning()->title('Nomor HP terlepas')->body('Pilih ulang nomor HP ini.')->send();
        }
    }

    /** Giliran HP ini — nomornya juga harus masih dipegang HP ini (bukan sekadar diaku). */
    private function pastikanGiliranSaya(): void
    {
        $g = app(PilihUnitGiliran::class);
        if ($this->hpNomor && $this->clusterId && ! $g->pegang($this->clusterId, $this->hpNomor, $this->hpToken)) {
            throw new \RuntimeException('Nomor HP '.$this->hpNomor.' tidak lagi dipegang HP ini — pilih ulang nomor HP.');
        }
        $g->pastikanGiliran($this->clusterId, $this->hpNomor);
    }

    private function muatSetup(): void
    {
        $st = app(PilihUnitGiliran::class)->status($this->clusterId);
        $this->giliranAktif = $st['aktif'];
        $this->timerAktif = $st['timer_aktif'];
        $this->timerMenit = $st['timer_menit'];
    }

    private function lepasHp(): void
    {
        if ($this->hpNomor && $this->clusterId) {
            app(PilihUnitGiliran::class)->lepas($this->clusterId, $this->hpNomor, $this->hpToken);
        }
        $this->hpNomor = null;
    }

    public function pickUnit(int $unitId): void
    {
        if ($this->phase !== 'choosing' || ! $this->registrationId) {
            return;
        }

        $reg = NupRegistration::find($this->registrationId);
        if (! $reg) {
            $this->pickError = 'Registrasi tidak ditemukan.';

            return;
        }

        try {
            if ((int) Unit::whereKey($unitId)->value('lokasi_id') !== (int) $this->clusterId) {
                throw new \RuntimeException('Unit bukan bagian dari cluster yang sedang dibuka.');
            }
            $giliran = app(PilihUnitGiliran::class);
            $this->pastikanGiliranSaya();
            $result = app(PilihUnitService::class)->pickUnit($reg, $unitId);
            if ($this->hpNomor) {
                // Sesudah memilih, giliran otomatis ke HP berikutnya.
                $giliran->lanjut($this->clusterId, $this->hpNomor);
            }
            $this->pickedUnitLabel = $result['unit_label'];
            $unit = Unit::find($unitId);
            $project = $unit?->project_id ? Project::find($unit->project_id) : null;
            $this->pickedUnitAlamat = $unit?->alamat ?: ($project?->address ?? '—');
            $this->phase = 'done';
            $this->dispatch('nup-unit-picked',
                label: $this->pickedUnitLabel,
                name: $this->regCustomerName,
                unitId: $unitId,
                projectId: $this->clusterId,
            );
        } catch (\Throwable $e) {
            $this->pickError = PesanGagal::dari($e);
            Notification::make()->danger()->title('Gagal pilih unit')->body($this->pickError)->send();
        }
    }

    public function reset(...$args): mixed
    {
        // Workaround untuk Livewire reset() — kalau dipanggil dari client.
        $result = parent::reset(...$args);

        return $result;
    }

    public function resetToScan(): void
    {
        $this->resetCustomer();
        $this->phase = 'scan';
    }

    private function resetCustomer(): void
    {
        $this->registrationId = null;
        $this->regNoNup = null;
        $this->regCustomerName = null;
        $this->regCustomerAddress = null;
        $this->regCustomerCity = null;
        $this->regTipeNup = null;
        $this->regPilihanUnit1 = null;
        $this->regPilihanUnit2 = null;
        $this->regPilihanUnit3 = null;
        $this->regRefSales = null;
        $this->regRefPhone = null;
        $this->regSumberData = null;
        $this->regEvent = null;
        $this->regCaraBayar = null;
        $this->pickedUnitLabel = null;
        $this->pickedUnitAlamat = null;
        $this->pickError = null;
    }

    /**
     * Daftar cluster = master Lokasi (per project), dengan jumlah unit per status.
     * Satu kartu = satu Lokasi (cluster), masing-masing punya siteplan sendiri.
     */
    public function getClusters(): array
    {
        $lokasiList = Lokasi::with('project:id,name')
            ->where('is_active', true)->orderBy('kode')->get();

        // Hitung status unit per lokasi dalam satu query agregat.
        $counts = Unit::query()->where('is_active', true)
            ->whereIn('lokasi_id', $lokasiList->pluck('id'))
            ->selectRaw('lokasi_id, status, COUNT(*) as c')
            ->groupBy('lokasi_id', 'status')
            ->get()
            ->groupBy('lokasi_id');

        return $lokasiList->map(function (Lokasi $l) use ($counts) {
            $byStatus = ($counts[$l->id] ?? collect())->pluck('c', 'status');

            return [
                'lokasi_id' => $l->id,
                'project_id' => $l->project_id,
                'project_name' => $l->project?->name ?? '',
                'cluster' => $l->nama,
                'label' => $l->nama,
                'unit_tersedia' => (int) ($byStatus['TERSEDIA'] ?? 0),
                'unit_booking' => (int) ($byStatus['BOOKING'] ?? 0),
                'unit_terjual' => (int) (($byStatus['TERJUAL'] ?? 0) + ($byStatus['LUNAS'] ?? 0)),
            ];
        })->values()->all();
    }

    /**
     * Unit list per cluster aktif — semua field yang dipakai siteplan popup
     * (info card seperti legacy eProperty) + polygon koordinat.
     */
    public function getUnits(): array
    {
        if (! $this->clusterId) {
            return [];
        }

        $project = $this->getProject();

        return Unit::with(['unitType', 'pricelists.skema', 'lokasi'])
            ->where('lokasi_id', $this->clusterId)
            ->where('is_active', true)
            ->orderBy('blok')
            ->orderBy('no_unit')
            ->get()
            ->map(function (Unit $u) use ($project) {
                // No. Unit kanonik (Lokasi-Tahap-Blok-Nomer) dari model.
                $kode = $u->kode_unit ?: $u->label;

                // SAMA dgn /siteplan publik: hanya skema "Tampil di Site Plan" + cocok
                // lokasi+tahap unit ini (skema CASH tahap 001 tak muncul di unit
                // tahap 004) + harga > 0. Tanpa filter tampil_siteplan, popup memuat
                // SEMUA skema (mis. 7 kartu) — beda dari siteplan publik.
                $pricelist = $u->pricelists
                    ->filter(fn ($p) => $p->skema !== null
                        && (float) $p->price_list > 0
                        && $p->skema->tampil_siteplan
                        && $p->skema->appliesToScope($u->lokasi_id, $u->tahap_id))
                    ->map(fn ($p) => [
                        'skema_code' => $p->skema->code,
                        'skema_name' => $p->skema->name ?: $p->no_skema,
                        'cara_bayar' => $p->skema->cara_bayar,
                        'price' => (float) $p->price_list,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $u->id,
                    'kode' => $kode,
                    'label' => $kode,
                    'blok' => $u->blok,
                    'no_unit' => $u->no_unit,
                    'tahap' => $u->tahap,
                    // Sama dgn /siteplan: nama master Lokasi unit (mis. "Java
                    // Residence Krian Cluster 3"), fallback nama project.
                    'nama_lokasi' => $u->lokasi?->nama ?: $project?->name,
                    'tipe' => $u->unitType?->name ?: '—',
                    'luas_tanah' => (float) $u->luas_tanah,
                    'luas_bangunan' => (float) $u->luas_bangunan,
                    'alamat' => $u->alamat ?: ($project?->address ?? '—'),
                    'harga' => (float) $u->harga_jual,
                    'pricelist' => $pricelist,
                    'status' => $u->status,
                    'siteplan_coords' => is_array($u->siteplan_coords) ? $u->siteplan_coords : null,
                ];
            })
            ->all();
    }

    public function getProject(): ?Project
    {
        return $this->projectId ? Project::find($this->projectId) : null;
    }

    /**
     * Siteplan image untuk Leaflet image-overlay — gambar Lokasi (cluster) terpilih,
     * fallback ke gambar project. Null bila keduanya belum diupload.
     *
     * @return array{url:string,width:int,height:int}|null
     */
    public function getSiteplan(): ?array
    {
        return $this->clusterId ? Lokasi::find($this->clusterId)?->resolvedSiteplan() : null;
    }

    /**
     * Blok area (penanda tahap, tidak terikat unit) untuk cluster terpilih.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getBlocks(): array
    {
        if (! $this->clusterId) {
            return [];
        }

        return \App\Modules\FO\MarketingSales\Models\SiteplanBlock::query()
            ->where('lokasi_id', $this->clusterId)
            ->get(['coords', 'label', 'color', 'opacity'])
            ->map(fn ($b) => [
                'coords' => is_array($b->coords) ? $b->coords : [],
                'label' => $b->label,
                'color' => $b->color ?: '#0a0a0a',
                'opacity' => (float) $b->opacity,
            ])
            ->filter(fn ($b) => count($b['coords']) >= 3)
            ->values()->all();
    }
}
