<?php

namespace App\Filament\Fo\Concerns;

use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;

/**
 * Tabel stok visual (grid Blok × No. unit, warna sesuai status) — dipakai Pilih Unit
 * Booked & Pindah Unit. Halaman pemakai: simpan property $lokasiId & $tahap (live),
 * implement selectGridUnit($id), dan render partial 'filament.fo.partials.stock-grid'.
 */
trait BuildsStockGrid
{
    /** Blok dengan nomor lebih dari ini dipecah ke beberapa baris (tabel tak melebar ke kanan). */
    private int $stockGridMaksKolom = 40;

    private int $stockGridKolomPecah = 30;

    /** @return array<int|string,string> opsi cluster (Lokasi) untuk project. */
    public function clusterOptions(?int $projectId): array
    {
        return Lokasi::query()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('nama')->pluck('nama', 'id')->all();
    }

    /** @return array<string,string> opsi tahap (dari unit di cluster terpilih). */
    public function tahapOptions(?int $projectId, ?int $lokasiId): array
    {
        if (! $lokasiId) {
            return [];
        }

        return Unit::query()->where('is_active', true)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('lokasi_id', $lokasiId)
            ->whereNotNull('tahap')->where('tahap', '!=', '')
            ->distinct()->orderBy('tahap')->pluck('tahap', 'tahap')->all();
    }

    /**
     * Data grid per blok: baris = blok, kolom = nomor (angka depan; 23 & 23A satu kolom).
     * Blok kosong (warisan migrasi, no_unit berisi kode lengkap) dibaca dari kode
     * {Lokasi}-{Tahap}-{Blok}-{Nomor} — hanya untuk tampilan, data tidak diubah.
     * Unit yang tak bisa dipecah masuk 'lainnya'.
     *
     * @return array{blok:array<int,array>,cols:array<int,int>,lainnya:array<int,array>,stat:array<string,int>,total:int}
     */
    public function stockGridData(?int $projectId, ?int $lokasiId, ?string $tahap, ?int $excludeUnitId = null): array
    {
        $kosong = ['blok' => [], 'cols' => [], 'lainnya' => [], 'stat' => [], 'total' => 0];
        if (! $lokasiId) {
            return $kosong;
        }

        $units = Unit::query()->where('is_active', true)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('lokasi_id', $lokasiId)
            ->when($tahap, fn ($q) => $q->where('tahap', $tahap))
            ->get(['id', 'lokasi_id', 'tahap_id', 'tahap', 'blok', 'no_unit', 'kode_unit', 'status', 'harga_jual', 'luas_tanah', 'luas_bangunan']);
        if ($units->isEmpty()) {
            return $kosong;
        }

        $harga = UnitPricelist::query()->whereIn('unit_id', $units->pluck('id'))->where('price_list', '>', 0)
            ->with('skema')->get(['unit_id', 'skema_id', 'price_list', 'no_skema'])->groupBy('unit_id');

        $blok = [];
        $lainnya = [];
        $stat = ['TERSEDIA' => 0, 'BOOKING' => 0, 'TERJUAL' => 0];
        $maks = 0;

        foreach ($units as $u) {
            $status = $excludeUnitId === (int) $u->id ? 'INI' : (string) $u->status;
            $kode = (string) ($u->kode_unit ?: $u->no_unit);
            [$namaBlok, $nomor] = $this->pecahBlokNomor($u);
            $dasar = $nomor !== null && preg_match('/^0*(\d+)/', $nomor, $m) ? (int) $m[1] : 0;

            $info = ($u->luas_tanah || $u->luas_bangunan) ? 'LT '.(float) $u->luas_tanah.' / LB '.(float) $u->luas_bangunan : '';
            // Skema yang tampil sama dengan /siteplan publik: "Tampil di Site Plan" + cocok lokasi/tahap.
            $hargaTeks = ($harga[$u->id] ?? collect())
                ->filter(fn ($p) => $p->skema && $p->skema->tampil_siteplan && $p->skema->appliesToScope($u->lokasi_id, $u->tahap_id))
                ->map(fn ($p) => ($p->skema->name ?: $p->no_skema).' Rp '.number_format((float) $p->price_list, 0, ',', '.'))
                ->unique()->implode("\n");
            if ($hargaTeks === '' && (float) $u->harga_jual > 0) {
                $hargaTeks = 'Rp '.number_format((float) $u->harga_jual, 0, ',', '.');
            }

            $sel = [
                'id' => (int) $u->id,
                'status' => $status,
                'no' => $nomor ?? $kode,
                'kode' => $kode,
                'cari' => strtolower($kode.' '.$namaBlok.'-'.$nomor),
                'title' => trim($kode.' — '.$status."\n".$info."\n".$hargaTeks),
            ];
            isset($stat[$status]) && $stat[$status]++;

            if ($dasar === 0) {
                $lainnya[] = $sel;

                continue;
            }

            // Tanpa filter tahap, blok bernama sama di tahap berbeda dipisah.
            $kunci = ($tahap ? '' : ((string) $u->tahap).' · ').$namaBlok;
            $blok[$kunci] ??= ['nama' => $kunci, 'sel' => [], 'total' => 0, 'tersedia' => 0, 'booking' => 0];
            $blok[$kunci]['sel'][$dasar][] = $sel;
            $blok[$kunci]['total']++;
            $blok[$kunci]['tersedia'] += $status === 'TERSEDIA' ? 1 : 0;
            $blok[$kunci]['booking'] += $status === 'BOOKING' ? 1 : 0;
            $maks = max($maks, $dasar);
        }

        // Urut blok: Romawi (XV < XVI < XXVII) dibandingkan nilainya, lainnya urut natural.
        uksort($blok, fn ($a, $b) => strnatcasecmp($this->kunciUrutBlok($a), $this->kunciUrutBlok($b)));

        $lebar = $maks > $this->stockGridMaksKolom ? $this->stockGridKolomPecah : $maks;
        foreach ($blok as &$b) {
            foreach ($b['sel'] as &$isi) {
                usort($isi, fn ($x, $y) => strnatcasecmp($x['no'], $y['no']));
            }
            unset($isi);
            // Pecah blok panjang ke beberapa baris: baris k memuat nomor k*lebar+1 … (k+1)*lebar.
            $b['baris'] = [];
            foreach ($b['sel'] as $dasar => $isi) {
                $b['baris'][intdiv($dasar - 1, $lebar)][($dasar - 1) % $lebar + 1] = $isi;
            }
            ksort($b['baris']);
            unset($b['sel']);
        }
        unset($b);

        return [
            'blok' => array_values($blok),
            'cols' => $lebar ? range(1, $lebar) : [],
            'lainnya' => $lainnya,
            'stat' => $stat,
            'total' => $units->count(),
        ];
    }

    /** @return array{0:string,1:?string} [blok, nomor] — dari kolom, atau dari kode 4 segmen. */
    private function pecahBlokNomor(Unit $u): array
    {
        $blok = trim((string) $u->blok);
        $no = trim((string) $u->no_unit);
        if ($blok !== '') {
            return [$blok, $no];
        }
        $bagian = explode('-', $no !== '' ? $no : (string) $u->kode_unit);

        return count($bagian) === 4 ? [trim($bagian[2]), trim($bagian[3])] : ['', null];
    }

    private function kunciUrutBlok(string $kunci): string
    {
        return preg_replace_callback('/\b[IVX]+\b/', function ($m) {
            $nilai = ['I' => 1, 'V' => 5, 'X' => 10];
            $total = 0;
            $s = $m[0];
            for ($i = 0; $i < strlen($s); $i++) {
                $v = $nilai[$s[$i]];
                $total += ($i + 1 < strlen($s) && $nilai[$s[$i + 1]] > $v) ? -$v : $v;
            }

            return str_pad((string) $total, 4, '0', STR_PAD_LEFT);
        }, $kunci);
    }
}
