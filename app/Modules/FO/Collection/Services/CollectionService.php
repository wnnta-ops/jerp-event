<?php

namespace App\Modules\FO\Collection\Services;

use App\Modules\FO\Collection\Mail\CollectionNoticeMail;
use App\Modules\FO\Collection\Models\CollectionNotice;
use App\Modules\FO\Komunikasi\Models\LogPesan;
use App\Modules\FO\Komunikasi\Services\PengirimPesan;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use App\Services\Numerator\NumeratorServiceInterface;
use App\Settings\CollectionSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Logika penagihan AR (Collection) untuk kontrak yang sudah SPR:
 * - kirim reminder pra-jatuh-tempo & Surat Peringatan I/II/III via Email + WA,
 * - render template (placeholder) + catat ke fo_collection_notices,
 * - bantu hitung status/level penagihan & kandidat otomatis.
 *
 * Tipe tagihan yang dianggap AR: selain GMC (gimmick FREE — bukan tagihan customer).
 */
class CollectionService
{
    /** Jenis surat yang valid. */
    public const JENIS = ['REMINDER', 'SP1', 'SP2', 'SP3'];

    public function __construct(
        private readonly CollectionSettings $settings,
        private readonly WhatsAppGatewayInterface $wa,
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /** Tagihan AR yang belum lunas (sisa > 0) untuk satu kontrak, urut jatuh tempo. */
    public function arTagihan(Kontrak $kontrak): Collection
    {
        return $kontrak->tagihan()
            ->where('tipe', '!=', 'GMC')
            ->get()
            ->filter(fn (KontrakTagihan $t) => (float) $t->nilai - (float) $t->pelunasan > 0.5)
            ->sortBy('jatuh_tempo')
            ->values();
    }

    /** Level SP tertinggi yang sudah dikirim untuk kontrak ini (null bila belum). */
    public function levelSekarang(Kontrak $kontrak): ?string
    {
        $jenis = CollectionNotice::where('kontrak_id', $kontrak->id)
            ->whereIn('jenis', ['SP1', 'SP2', 'SP3'])
            ->pluck('jenis')->all();

        foreach (['SP3', 'SP2', 'SP1'] as $lvl) {
            if (in_array($lvl, $jenis, true)) {
                return $lvl;
            }
        }

        return null;
    }

    /** Apakah jenis surat ini sudah pernah dikirim untuk tagihan tsb. */
    public function sudahKirim(int $tagihanId, string $jenis): bool
    {
        return CollectionNotice::where('kontrak_tagihan_id', $tagihanId)
            ->where('jenis', $jenis)->exists();
    }

    /**
     * Kirim surat (jenis) untuk satu tagihan. Mengirim ke channel sesuai argumen
     * atau setting (Email/WA), mencatat tiap channel ke fo_collection_notices.
     *
     * @param  array<int,string>|null  $channels  ['EMAIL','WA'] — null = ikut setting.
     * @return array<int,CollectionNotice>
     */
    public function kirim(Kontrak $kontrak, ?KontrakTagihan $tagihan, string $jenis, ?array $channels = null, ?int $hariTelat = null): array
    {
        $kontrak->loadMissing(['customer', 'unit']);
        $cust = $kontrak->customer;

        $hariTelat ??= $tagihan ? $this->hariTelat($tagihan) : 0;
        $subjek = $this->render($this->subjectTemplate($jenis), $kontrak, $tagihan, $hariTelat);
        $isi = $this->render($this->bodyTemplate($jenis), $kontrak, $tagihan, $hariTelat);

        // SP butuh nomor surat (reminder tidak).
        $noSurat = $jenis === 'REMINDER' ? null : $this->numerator->next('SP');

        if ($channels === null) {
            $channels = [];
            if ($this->settings->channel_email) {
                $channels[] = 'EMAIL';
            }
            if ($this->settings->channel_wa) {
                $channels[] = 'WA';
            }
        }

        $hasil = [];
        foreach ($channels as $ch) {
            $hasil[] = $ch === 'EMAIL'
                ? $this->kirimEmail($kontrak, $tagihan, $jenis, $noSurat, $subjek, $isi, $hariTelat, (string) ($cust?->email ?? ''))
                : $this->kirimWa($kontrak, $tagihan, $jenis, $noSurat, $subjek, $isi, $hariTelat, (string) ($cust?->mobile ?: $cust?->phone ?? ''));
        }

        return $hasil;
    }

    /**
     * Kiriman lewat PengirimPesan → ikut tercatat di Monitoring Pesan (alasan gagal terbaca).
     * fo_collection_notices tetap dicatat seperti biasa; selain TERKIRIM dianggap GAGAL di sana.
     *
     * @return array<string,mixed>
     */
    private function refPesan(Kontrak $k, ?KontrakTagihan $t, string $jenis, ?string $noSurat): array
    {
        return [
            'jenis' => $jenis,
            'ref_type' => 'kontrak_tagihan',
            'ref_id' => $t?->id,
            'ref_no' => trim(($noSurat ? $noSurat.' · ' : '').$k->no_kontrak.($t ? ' · '.$t->nama_tagihan : '')),
            'kontrak_id' => $k->id,
            'penerima_nama' => $k->customer?->full_name,
        ];
    }

    private function kirimEmail(Kontrak $k, ?KontrakTagihan $t, string $jenis, ?string $noSurat, string $subjek, string $isi, int $hariTelat, string $email): CollectionNotice
    {
        $log = app(PengirimPesan::class)->email($this->refPesan($k, $t, $jenis, $noSurat), $email, new CollectionNoticeMail($subjek, $isi), $subjek);
        // Sama seperti sebelumnya: MAIL_MAILER=log tetap dianggap terkirim di catatan Collection
        // (agar jadwal otomatis tak mengulang & menghabiskan nomor SP); Monitoring mencatatnya DILEWATI.
        $status = (trim($email) === '' || $log->status === LogPesan::GAGAL) ? 'GAGAL' : 'TERKIRIM';

        return $this->catat($k, $t, $jenis, 'EMAIL', $status, $email, $subjek, $isi, $hariTelat, $noSurat);
    }

    private function kirimWa(Kontrak $k, ?KontrakTagihan $t, string $jenis, ?string $noSurat, string $subjek, string $isi, int $hariTelat, string $nomor): CollectionNotice
    {
        $params = [
            (string) ($k->customer?->full_name ?? ''),
            (string) $k->no_kontrak,
            $t ? (string) $t->nama_tagihan : '',
            $t && $t->jatuh_tempo ? $t->jatuh_tempo->format('d M Y') : '',
            $t ? number_format((float) $t->nilai - (float) $t->pelunasan, 0, ',', '.') : '',
        ];
        $log = app(PengirimPesan::class)->wa($this->refPesan($k, $t, $jenis, $noSurat), $nomor, $this->waTemplate($jenis), $params);
        $status = $log->status === LogPesan::TERKIRIM ? 'TERKIRIM' : 'GAGAL';

        return $this->catat($k, $t, $jenis, 'WA', $status, $nomor, $subjek, $isi, $hariTelat, $noSurat);
    }

    private function catat(Kontrak $k, ?KontrakTagihan $t, string $jenis, string $channel, string $status, string $penerima, string $subjek, string $isi, int $hariTelat, ?string $noSurat): CollectionNotice
    {
        return CollectionNotice::create([
            'kontrak_id' => $k->id,
            'kontrak_tagihan_id' => $t?->id,
            'no_surat' => $noSurat,
            'jenis' => $jenis,
            'channel' => $channel,
            'status' => $status,
            'penerima' => $penerima,
            'subjek' => $subjek,
            'isi' => $isi,
            'hari_telat' => max(0, $hariTelat),
            'dikirim_pada' => now(),
            'created_by' => auth()->id(),
        ]);
    }

    /** Ganti placeholder di template dengan data kontrak/tagihan. */
    public function render(string $template, Kontrak $kontrak, ?KontrakTagihan $tagihan, int $hariTelat = 0): string
    {
        $kontrak->loadMissing(['customer', 'unit']);
        $unit = $kontrak->unit;
        $sisa = $tagihan ? (float) $tagihan->nilai - (float) $tagihan->pelunasan : 0;

        $map = [
            '{nama}' => (string) ($kontrak->customer?->full_name ?? '—'),
            '{no_kontrak}' => (string) $kontrak->no_kontrak,
            '{unit}' => (string) ($unit?->alamat ?: $unit?->kode_unit ?: '—'),
            '{tagihan}' => (string) ($tagihan?->nama_tagihan ?? 'angsuran'),
            '{jatuh_tempo}' => $tagihan && $tagihan->jatuh_tempo ? $tagihan->jatuh_tempo->format('d M Y') : '—',
            '{nominal}' => number_format($tagihan ? (float) $tagihan->nilai : 0, 0, ',', '.'),
            '{sisa}' => number_format($sisa, 0, ',', '.'),
            '{hari_telat}' => (string) max(0, $hariTelat),
        ];

        return strtr($template, $map);
    }

    /** Hari keterlambatan tagihan (0 bila belum jatuh tempo). */
    public function hariTelat(KontrakTagihan $tagihan): int
    {
        if (! $tagihan->jatuh_tempo) {
            return 0;
        }

        return max(0, $tagihan->jatuh_tempo->copy()->startOfDay()->diffInDays(Carbon::today(), false));
    }

    private function subjectTemplate(string $jenis): string
    {
        return match ($jenis) {
            'REMINDER' => $this->settings->reminder_subject,
            'SP1' => $this->settings->sp1_subject,
            'SP2' => $this->settings->sp2_subject,
            'SP3' => $this->settings->sp3_subject,
            default => '',
        };
    }

    private function bodyTemplate(string $jenis): string
    {
        return match ($jenis) {
            'REMINDER' => $this->settings->reminder_body,
            'SP1' => $this->settings->sp1_body,
            'SP2' => $this->settings->sp2_body,
            'SP3' => $this->settings->sp3_body,
            default => '',
        };
    }

    private function waTemplate(string $jenis): string
    {
        return match ($jenis) {
            'REMINDER' => $this->settings->wa_template_reminder,
            'SP1' => $this->settings->wa_template_sp1,
            'SP2' => $this->settings->wa_template_sp2,
            'SP3' => $this->settings->wa_template_sp3,
            default => '',
        };
    }
}
