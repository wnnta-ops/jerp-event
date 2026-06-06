<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\PilihUnitService;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Pilih Unit — workflow hari-H launching:
 *   1. Operator pilih cluster (project).
 *   2. Siteplan (Leaflet.js) + kamera barcode scanner muncul.
 *   3. Scan barcode customer (no_nup) → tampil data customer + button.
 *   4. Click "Pilih Unit" → countdown 2 menit aktif.
 *   5. Klik unit di siteplan dalam 2 menit → atomic lock unit + popup sukses.
 *   6. Timer habis → reset ke step 3 (tetap di customer yang sama).
 *   7. Click Done di popup → reset ke step 3 (scan barcode lagi).
 */
class PilihUnit extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $title = 'Pilih Unit';

    protected static ?string $slug = 'pilih-unit';

    protected static string $view = 'filament.fo.pages.pilih-unit';

    protected static bool $shouldRegisterNavigation = false;

    /** Project dari cluster yang sedang aktif. Null = step pick cluster. */
    public ?int $clusterId = null;

    /** Nama cluster (kolom fo_units.cluster) yang sedang aktif. */
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

    /** Hasil pemilihan unit (popup). */
    public ?string $pickedUnitLabel = null;

    public ?string $pickedUnitAlamat = null;

    public ?string $pickError = null;

    public function mount(): void
    {
        $this->reset(['clusterId', 'clusterName', 'phase', 'registrationId', 'regNoNup', 'regCustomerName',
            'regCustomerAddress', 'regCustomerCity', 'regTipeNup', 'regPilihanUnit1',
            'regPilihanUnit2', 'regPilihanUnit3', 'pickedUnitLabel', 'pickError']);
        $this->phase = 'scan';
    }

    public function selectCluster(int $projectId, ?string $cluster = null): void
    {
        $this->clusterId = $projectId;
        $this->clusterName = ($cluster !== null && $cluster !== '') ? $cluster : null;
        $this->phase = 'scan';
        $this->resetCustomer();
    }

    public function backToClusters(): void
    {
        $this->clusterId = null;
        $this->clusterName = null;
        $this->resetCustomer();
    }

    public function scanBarcode(string $barcode): void
    {
        $this->resetCustomer();

        try {
            $reg = app(PilihUnitService::class)->lookupByBarcode($barcode);
        } catch (\Throwable $e) {
            $this->pickError = $e->getMessage();
            Notification::make()->danger()->title('Scan gagal')->body($e->getMessage())->send();

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

        $this->phase = 'customer';
        $this->dispatch('nup-customer-loaded', noNup: $reg->no_nup, name: $this->regCustomerName);
    }

    public function startChoosing(): void
    {
        if ($this->phase !== 'customer' || ! $this->registrationId) {
            return;
        }
        $this->phase = 'choosing';
        $this->dispatch('nup-timer-start');
    }

    public function timeout(): void
    {
        // 2 menit habis tanpa pilih → kembali ke layar customer info
        if ($this->phase === 'choosing') {
            $this->phase = 'customer';
            Notification::make()->warning()->title('Waktu habis')
                ->body('Waktu 2 menit habis. Kembali ke layar data customer.')->send();
        }
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
            $result = app(PilihUnitService::class)->pickUnit($reg, $unitId);
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
            $this->pickError = $e->getMessage();
            Notification::make()->danger()->title('Gagal pilih unit')->body($e->getMessage())->send();
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
        $this->pickedUnitLabel = null;
        $this->pickedUnitAlamat = null;
        $this->pickError = null;
    }

    /**
     * Daftar cluster (kolom fo_units.cluster) per project aktif, dengan jumlah
     * unit per status. Satu kartu = satu cluster (bukan project).
     */
    public function getClusters(): array
    {
        $projects = Project::query()->where('is_active', true)->pluck('name', 'id');

        return Unit::query()
            ->where('is_active', true)
            ->whereIn('project_id', $projects->keys())
            ->get()
            ->groupBy(fn (Unit $u) => $u->project_id.'|'.($u->cluster ?? ''))
            ->map(function ($units) use ($projects) {
                /** @var Unit $first */
                $first = $units->first();

                return [
                    'project_id' => $first->project_id,
                    'project_name' => $projects[$first->project_id] ?? '',
                    'cluster' => ($first->cluster !== null && $first->cluster !== '') ? $first->cluster : null,
                    'label' => ($first->cluster !== null && $first->cluster !== '') ? $first->cluster : 'Umum',
                    'unit_tersedia' => $units->where('status', 'TERSEDIA')->count(),
                    'unit_booking' => $units->where('status', 'BOOKING')->count(),
                    'unit_terjual' => $units->whereIn('status', ['TERJUAL', 'LUNAS'])->count(),
                ];
            })
            ->sortBy('label')
            ->values()
            ->all();
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

        $project = Project::find($this->clusterId);

        return Unit::with(['unitType', 'pricelists.skema'])
            ->where('project_id', $this->clusterId)
            ->where('is_active', true)
            ->when(
                $this->clusterName !== null,
                fn ($q) => $q->where('cluster', $this->clusterName),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('cluster')->orWhere('cluster', '')),
            )
            ->orderBy('blok')
            ->orderBy('no_unit')
            ->get()
            ->map(function (Unit $u) use ($project) {
                // No. Unit kanonik (Lokasi-Tahap-Blok-Nomer) dari model.
                $kode = $u->kode_unit ?: $u->label;

                $pricelist = $u->pricelists
                    ->map(fn ($p) => [
                        'skema_code' => $p->skema?->code,
                        'skema_name' => $p->skema?->name ?: $p->no_skema,
                        'cara_bayar' => $p->skema?->cara_bayar,
                        'price' => (float) $p->price_list,
                    ])
                    ->filter(fn ($p) => $p['price'] > 0)
                    ->values()
                    ->all();

                return [
                    'id' => $u->id,
                    'kode' => $kode,
                    'label' => $kode,
                    'blok' => $u->blok,
                    'no_unit' => $u->no_unit,
                    'tahap' => $u->tahap,
                    'nama_lokasi' => $project?->code,
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
        return $this->clusterId ? Project::find($this->clusterId) : null;
    }

    /**
     * Siteplan image untuk Leaflet image-overlay. Null bila project belum
     * upload siteplan.
     *
     * @return array{url:string,width:int,height:int}|null
     */
    public function getSiteplan(): ?array
    {
        $project = $this->getProject();
        if (! $project || ! $project->siteplan_image_path) {
            return null;
        }

        return [
            'url' => Storage::disk('public')->url($project->siteplan_image_path),
            'width' => (int) ($project->siteplan_width ?: 2000),
            'height' => (int) ($project->siteplan_height ?: 1200),
        ];
    }
}
