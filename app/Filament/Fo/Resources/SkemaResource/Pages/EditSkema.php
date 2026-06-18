<?php

namespace App\Filament\Fo\Resources\SkemaResource\Pages;

use App\Filament\Fo\Resources\SkemaResource;
use App\Models\Project;
use App\Modules\FO\Adminjual\Services\SkemaCalculatorService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Editor grid skema (mirip legacy "Edit Skema CB"): header + tabel termin
 * (Tipe BF/DP/ANG/ADM, Nama, Nilai %/F, Jadwal M/D/F, Setelah, ☑BF, ☑KPR)
 * + tombol Simulasi (breakdown Gambar 2). Livewire murni (tanpa inline script).
 */
class EditSkema extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SkemaResource::class;

    protected static string $view = 'filament.fo.resources.skema.edit';

    /** @var array<string,mixed> */
    public array $header = [];

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,mixed> */
    public array $preview = [];

    /** @var array<int,string> */
    public array $projectOptions = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->load('lines');
        $this->projectOptions = Project::query()->pluck('name', 'id')->all();

        $this->header = [
            'project_id' => $this->record->project_id,
            'name' => $this->record->name,
            'cara_bayar' => $this->record->cara_bayar ?? 'KPR',
            'is_active' => $this->record->is_active ? '1' : '0', // string agar cocok value radio

            'tampil_siteplan' => (bool) $this->record->tampil_siteplan,
            'diskon_persen' => (float) $this->record->diskon_persen,
            'present_value' => number_format((float) $this->record->present_value, 0, ',', '.'),
            'subsidi_persen' => (float) $this->record->subsidi_persen,
            'bf_dipotong_di' => $this->record->bf_dipotong_di ?? 'ANGSURAN1',
        ];

        $rows = [];
        foreach ($this->record->lines as $l) {
            $rows[] = [
                'id' => $l->id,
                'urutan' => $l->urutan,
                'tipe' => $l->tipe,
                'nama_termin' => $l->nama_termin,
                'basis' => $l->basis ?: 'PERSEN',
                'nilai' => ($l->basis === 'NOMINAL') ? (float) $l->nominal : (float) $l->persen,
                'jadwal_basis' => $l->jadwal_basis ?: 'BULAN',
                'jadwal_nilai' => (int) $l->jadwal_nilai,
                'setelah' => (int) $l->setelah,
                'flag_bf' => (bool) $l->flag_bf,
                'flag_kpr' => (bool) $l->flag_kpr,
            ];
        }
        $this->rows = $rows;
    }

    public function getTitle(): string
    {
        return 'Edit Skema';
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'id' => null, 'urutan' => count($this->rows) + 1, 'tipe' => 'BF',
            'nama_termin' => '', 'basis' => 'PERSEN', 'nilai' => 0,
            'jadwal_basis' => 'BULAN', 'jadwal_nilai' => 0, 'setelah' => 0,
            'flag_bf' => false, 'flag_kpr' => false,
        ];
    }

    public function deleteRow(int $i): void
    {
        unset($this->rows[$i]);
        $this->rows = array_values($this->rows);
    }

    /** Hitung breakdown (Gambar 2) dari header + rows memakai Present Value. */
    public function simulasi(): void
    {
        $harga = (float) str_replace('.', '', (string) ($this->header['present_value'] ?? 0));
        if ($harga <= 0) {
            Notification::make()->warning()->title('Isi Present Value dulu')->send();

            return;
        }

        $dpRows = array_filter($this->rows, fn ($r) => ($r['tipe'] ?? '') === 'DP');
        $angRows = array_filter($this->rows, fn ($r) => ($r['tipe'] ?? '') === 'ANG');
        $bfRow = null;
        foreach ($this->rows as $r) {
            if (($r['tipe'] ?? '') === 'BF') {
                $bfRow = $r;
                break;
            }
        }

        $sumPersen = fn (array $rows) => array_sum(array_map(fn ($r) => ($r['basis'] ?? '') === 'PERSEN' ? (float) ($r['nilai'] ?? 0) : 0, $rows));
        $utj = $bfRow
            ? (($bfRow['basis'] ?? '') === 'NOMINAL' ? (float) $bfRow['nilai'] : (float) $bfRow['nilai'] / 100 * $harga)
            : (float) $this->record->bf_nominal;

        $this->preview = app(SkemaCalculatorService::class)->compute([
            'mode' => $this->header['bf_dipotong_di'] ?? 'TIDAK_DISEBAR',
            'cara_bayar' => $this->header['cara_bayar'] ?? 'KPR',
            'utj' => $utj,
            'dp_persen' => $sumPersen($dpRows),
            'ang_persen' => $sumPersen($angRows),
            'subsidi_persen' => (float) ($this->header['subsidi_persen'] ?? 0),
            'n_dp' => max(1, count($dpRows)),
            'n_ang' => max(1, count($angRows)),
        ], $harga);
    }

    public function save(bool $stay = false)
    {
        DB::transaction(function () {
            $this->record->update([
                'project_id' => $this->header['project_id'] ?: null,
                'name' => $this->header['name'],
                'cara_bayar' => $this->header['cara_bayar'],
                'is_active' => (bool) $this->header['is_active'],
                'tampil_siteplan' => (bool) $this->header['tampil_siteplan'],
                'diskon_persen' => (float) $this->header['diskon_persen'],
                'present_value' => (float) str_replace('.', '', (string) $this->header['present_value']),
                'subsidi_persen' => (float) $this->header['subsidi_persen'],
                'bf_dipotong_di' => $this->header['bf_dipotong_di'],
                'jumlah_termin' => count($this->rows),
            ]);

            $keepIds = [];
            foreach (array_values($this->rows) as $idx => $r) {
                $data = [
                    'urutan' => (int) (($r['urutan'] ?? 0) ?: $idx + 1),
                    'tipe' => $r['tipe'] ?? 'DP',
                    'nama_termin' => (string) ($r['nama_termin'] ?? ''),
                    'basis' => $r['basis'] ?? 'PERSEN',
                    'persen' => ($r['basis'] ?? 'PERSEN') === 'PERSEN' ? (float) ($r['nilai'] ?? 0) : null,
                    'nominal' => ($r['basis'] ?? 'PERSEN') === 'NOMINAL' ? (float) ($r['nilai'] ?? 0) : null,
                    'jadwal_basis' => $r['jadwal_basis'] ?? 'BULAN',
                    'jadwal_nilai' => (int) ($r['jadwal_nilai'] ?? 0),
                    'setelah' => (int) ($r['setelah'] ?? 0),
                    'flag_bf' => (bool) ($r['flag_bf'] ?? false),
                    'flag_kpr' => (bool) ($r['flag_kpr'] ?? false),
                ];

                if (! empty($r['id'])) {
                    $this->record->lines()->whereKey($r['id'])->update($data);
                    $keepIds[] = $r['id'];
                } else {
                    $keepIds[] = $this->record->lines()->create($data)->id;
                }
            }

            $this->record->lines()->whereNotIn('id', $keepIds)->delete();
        });

        Notification::make()->success()->title('Skema "'.$this->record->name.'" disimpan')->send();

        if (! $stay) {
            return redirect(SkemaResource::getUrl('index'));
        }

        $this->record->refresh()->load('lines');

        return null;
    }

    public function deleteRecord()
    {
        $this->record->delete();
        Notification::make()->success()->title('Skema dihapus')->send();

        return redirect(SkemaResource::getUrl('index'));
    }
}
