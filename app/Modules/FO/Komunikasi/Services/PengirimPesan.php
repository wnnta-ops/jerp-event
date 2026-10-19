<?php

namespace App\Modules\FO\Komunikasi\Services;

use App\Modules\FO\Komunikasi\Models\LogPesan;
use App\Modules\FO\Shared\Integrations\WhatsApp\MemberiAlasan;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pintu keluar SEMUA email & WhatsApp ke customer. Setiap kiriman — berhasil, gagal, atau
 * dilewati — dicatat di fo_log_pesan beserta alasannya (Monitoring Pesan).
 *
 * Tidak pernah melempar: kegagalan satu kanal tidak boleh membatalkan kanal lain maupun
 * proses bisnis yang memicunya (pembayaran, approval, dst).
 *
 * $ref = ['jenis','ref_type','ref_id','ref_no','kontrak_id','penerima_nama'] (opsional semua kecuali jenis).
 */
class PengirimPesan
{
    /**
     * @param  array<int,string>  $params
     * @param  array<string,mixed>  $ref
     */
    public function wa(array $ref, ?string $nomor, string $templateId, array $params, ?string $dokumenUrl = null, ?string $dokumenNama = null): LogPesan
    {
        $data = $this->dasar($ref, 'WA', $nomor) + [
            'wa_template_id' => $templateId !== '' ? $templateId : null,
            'payload' => ['params' => array_values($params), 'dokumen_url' => $dokumenUrl, 'dokumen_nama' => $dokumenNama],
        ];

        if (trim((string) $nomor) === '') {
            return $this->catat($data, LogPesan::DILEWATI, 'Customer tidak punya nomor HP');
        }
        if ($templateId === '') {
            return $this->catat($data, LogPesan::DILEWATI, 'Template WA belum dipilih di Template Pesan');
        }

        try {
            $gateway = app(WhatsAppGatewayInterface::class);
            $ok = $gateway->sendTemplate((string) $nomor, (string) ($ref['penerima_nama'] ?? ''), $templateId, array_values($params), $dokumenUrl, $dokumenNama);
            $alasan = $gateway instanceof MemberiAlasan ? $gateway->alasanTerakhir() : null;
            if ($ok) {
                return $this->catat($data, LogPesan::TERKIRIM, null);
            }
            // Gateway Null (token kosong) = memang dimatikan → DILEWATI, bukan GAGAL.
            $dilewati = $gateway instanceof \App\Modules\FO\Shared\Integrations\WhatsApp\NullWhatsAppGateway;

            return $this->catat($data, $dilewati ? LogPesan::DILEWATI : LogPesan::GAGAL, $alasan ?: 'WA tidak terkirim (lihat log server)');
        } catch (Throwable $e) {
            return $this->catat($data, LogPesan::GAGAL, mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /**
     * @param  array<string,mixed>  $ref
     * @param  array<string,mixed>  $ulang  data untuk tombol "Kirim ulang" (disimpan di payload)
     */
    public function email(array $ref, ?string $alamat, Mailable $mail, ?string $subjek = null, array $ulang = []): LogPesan
    {
        $data = $this->dasar($ref, 'EMAIL', $alamat) + ['subjek' => $subjek ? mb_substr($subjek, 0, 250) : null, 'payload' => $ulang ?: null];

        if (trim((string) $alamat) === '') {
            return $this->catat($data, LogPesan::DILEWATI, 'Customer tidak punya email');
        }
        if (! filter_var(trim((string) $alamat), FILTER_VALIDATE_EMAIL)) {
            return $this->catat($data, LogPesan::GAGAL, 'Alamat email tidak valid: '.$alamat);
        }

        try {
            Mail::to(trim((string) $alamat))->send($mail);
            $mailer = (string) config('mail.default');
            if (in_array($mailer, ['log', 'array'], true)) {
                return $this->catat($data, LogPesan::DILEWATI, 'Pengiriman email dimatikan di server ini (MAIL_MAILER='.$mailer.')');
            }

            return $this->catat($data, LogPesan::TERKIRIM, null);
        } catch (Throwable $e) {
            return $this->catat($data, LogPesan::GAGAL, mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /** Catat kiriman yang sengaja tidak dilakukan (mis. template dinonaktifkan). */
    public function lewati(array $ref, string $channel, ?string $tujuan, string $alasan): LogPesan
    {
        return $this->catat($this->dasar($ref, $channel, $tujuan), LogPesan::DILEWATI, $alasan);
    }

    /** @return array<string,mixed> */
    private function dasar(array $ref, string $channel, ?string $tujuan): array
    {
        return [
            'channel' => $channel,
            'jenis' => (string) ($ref['jenis'] ?? 'LAIN'),
            'ref_type' => $ref['ref_type'] ?? null,
            'ref_id' => $ref['ref_id'] ?? null,
            'ref_no' => isset($ref['ref_no']) ? mb_substr((string) $ref['ref_no'], 0, 250) : null,
            'kontrak_id' => $ref['kontrak_id'] ?? null,
            'penerima_nama' => isset($ref['penerima_nama']) ? mb_substr((string) $ref['penerima_nama'], 0, 250) : null,
            'tujuan' => $tujuan !== null ? mb_substr(trim($tujuan), 0, 250) : null,
            'percobaan' => (int) ($ref['percobaan'] ?? 1),
            'kirim_ulang_dari' => $ref['kirim_ulang_dari'] ?? null,
            'dikirim_oleh' => Auth::id(),
        ];
    }

    private function catat(array $data, string $status, ?string $alasan): LogPesan
    {
        try {
            return LogPesan::create($data + ['status' => $status, 'alasan' => $alasan]);
        } catch (Throwable $e) {
            // Pencatatan tak boleh menjatuhkan pengiriman — kembalikan model tak tersimpan.
            report($e);

            return new LogPesan($data + ['status' => $status, 'alasan' => $alasan]);
        }
    }
}
