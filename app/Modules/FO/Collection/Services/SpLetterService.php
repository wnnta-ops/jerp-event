<?php

namespace App\Modules\FO\Collection\Services;

use App\Modules\FO\Collection\Models\SpLetter;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Services\Numerator\NumeratorServiceInterface;
use App\Settings\CollectionSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Penerbitan Surat Peringatan (SP) tercetak: bangun baris tunggakan untuk form,
 * lalu terbitkan SP resmi (nomor + snapshot identitas & item terpilih + denda
 * manual). Tunggakan & hari telat memakai CollectionService.
 */
class SpLetterService
{
    public function __construct(
        private readonly CollectionService $collection,
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * Baris tunggakan kontrak untuk form penerbitan SP (default denda 0, sertakan true).
     *
     * @return array<int,array<string,mixed>>
     */
    public function tunggakanRows(Kontrak $kontrak): array
    {
        return $this->collection->arTagihan($kontrak)->map(fn (KontrakTagihan $t) => [
            'tagihan_id' => $t->id,
            'tgl' => optional($t->jatuh_tempo)->toDateString(),
            'keterangan' => $t->nama_tagihan,
            'tagihan' => (float) $t->nilai,
            'pembayaran' => (float) $t->pelunasan,
            'kekurangan' => (float) $t->sisa,
            'hari' => $this->collection->hariTelat($t),
            'denda' => 0.0,
            'sertakan' => true,
        ])->all();
    }

    /**
     * Terbitkan SP: buat nomor resmi + simpan snapshot. $data berisi: level,
     * tgl_surat, nama, alamat, telp, ref_spr, items[] (tiap item: tagihan_id, tgl,
     * keterangan, tagihan, pembayaran, kekurangan, hari, denda, sertakan).
     */
    public function terbitkan(Kontrak $kontrak, array $data): SpLetter
    {
        $s = app(CollectionSettings::class);

        $items = collect($data['items'] ?? [])
            ->filter(fn ($r) => (bool) ($r['sertakan'] ?? true))
            ->map(fn ($r) => [
                'tagihan_id' => $r['tagihan_id'] ?? null,
                'tgl' => $r['tgl'] ?? null,
                'keterangan' => (string) ($r['keterangan'] ?? ''),
                'tagihan' => (float) ($r['tagihan'] ?? 0),
                'pembayaran' => (float) ($r['pembayaran'] ?? 0),
                'kekurangan' => (float) ($r['kekurangan'] ?? 0),
                'hari' => (int) ($r['hari'] ?? 0),
                'denda' => (float) ($r['denda'] ?? 0),
            ])->values();

        $tglSurat = Carbon::parse($data['tgl_surat'] ?? now());
        $dueDays = (int) ($s->sp_due_days ?: 7);

        return DB::transaction(fn () => SpLetter::create([
            'kontrak_id' => $kontrak->id,
            'no_surat' => $this->nextNoSurat($kontrak, $tglSurat),
            'level' => $data['level'],
            'tgl_surat' => $tglSurat->toDateString(),
            'jatuh_tempo' => $tglSurat->copy()->addDays($dueDays)->toDateString(),
            'nama' => $data['nama'] ?? $kontrak->customer?->full_name,
            'alamat' => $data['alamat'] ?? null,
            'telp' => $data['telp'] ?? null,
            'ref_spr' => $data['ref_spr'] ?? null,
            'items' => $items->all(),
            'total_tagihan' => $items->sum('tagihan'),
            'total_pembayaran' => $items->sum('pembayaran'),
            'total_kekurangan' => $items->sum('kekurangan'),
            'total_denda' => $items->sum('denda'),
            'penandatangan_nama' => $s->sp_signer_name,
            'penandatangan_jabatan' => $s->sp_signer_title,
            'created_by' => Auth::id(),
        ]));
    }

    /**
     * Nomor SP resmi: SP/{projectCode}/{yy}/{romawi bulan}/{seq:5} → mis.
     * SP/JR/26/VI/00001. Sequence atomik & reset bulanan dari numerator 'SURATSP'
     * (auto-register format default '...-{seq:5}'); 5 digit terakhir = nomor urut.
     */
    private function nextNoSurat(Kontrak $kontrak, Carbon $tgl): string
    {
        $seq = substr($this->numerator->next('SURATSP'), -5);
        $proj = $kontrak->unit?->project?->code ?: ($kontrak->unit?->lokasi?->kode ?: 'JR');
        $yy = $tgl->format('y');
        $roman = $this->roman((int) $tgl->format('n'));

        return "SP/{$proj}/{$yy}/{$roman}/{$seq}";
    }

    private function roman(int $m): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$m] ?? (string) $m;
    }
}
