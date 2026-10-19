<?php

namespace App\Modules\FO\Komunikasi\Services;

use App\Modules\FO\Komunikasi\Mail\PesanTemplateMail;
use App\Modules\FO\Komunikasi\Models\TemplatePesan;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use Closure;
use Illuminate\Mail\Mailable;

/**
 * Kirim satu pesan (WA + email) memakai Pengaturan → Template Pesan, dengan cadangan ke
 * perilaku lama bila template belum diatur (ID template .env & tampilan email bawaan).
 *
 * Urutan WA dulu, baru email: SMTP bisa menggantung sampai job dibunuh, WA lewat HTTP
 * jauh lebih cepat gagal — jangan sampai satu kanal yang macet menghilangkan kanal lain.
 */
class KirimPesanTemplate
{
    public function __construct(private readonly PengirimPesan $pengirim) {}

    /**
     * @param  array<string,mixed>  $ref  jenis, ref_type, ref_id, ref_no, kontrak_id, penerima_nama, percobaan, kirim_ulang_dari
     * @param  array<string,string>  $nilai  nilai kata kunci {…}
     * @param  array<int,string>  $waKunciBawaan  urutan isian {{1}}… bila Template Pesan belum mengaturnya
     * @param  array{url:?string,nama:?string,pdf:?Closure}|null  $dokumen  PDF untuk header WA & lampiran email
     * @param  Closure(): Mailable  $mailBawaan  email lama bila isi email di template kosong
     * @param  string|null  $kanal  'WA' / 'EMAIL' saja (kirim ulang), null = keduanya
     */
    public function kirim(
        array $ref,
        array $nilai,
        ?int $lokasiId,
        ?string $nomor,
        ?string $email,
        string $waIdBawaan,
        array $waKunciBawaan,
        ?array $dokumen,
        Closure $mailBawaan,
        ?string $kanal = null,
    ): void {
        $tpl = TemplatePesan::untuk((string) $ref['jenis'], $lokasiId);
        $ulang = ['ulang' => ['jenis' => $ref['jenis'], 'ref_type' => $ref['ref_type'] ?? null, 'ref_id' => $ref['ref_id'] ?? null]];

        if ($kanal === null || $kanal === 'WA') {
            if ($tpl && ! $tpl->kirim_wa) {
                $this->pengirim->lewati($ref, 'WA', $nomor, 'WA dinonaktifkan di Template Pesan ('.JenisPesan::label($ref['jenis']).')');
            } else {
                $wa = $tpl?->waTemplate;
                $waAktif = $wa && $wa->is_active;
                $templateId = $waAktif ? (string) $wa->template_id : $waIdBawaan;
                $kunci = $tpl?->wa_params ?: $waKunciBawaan;
                $params = array_map(fn ($k) => (string) ($nilai[$k] ?? '-'), array_values((array) $kunci));
                $pakaiDok = $waAktif ? $wa->pakai_dokumen : true;

                $docUrl = $pakaiDok ? ($dokumen['url'] ?? null) : null;
                if ($docUrl && isset($dokumen['pdf'])) {
                    // Siapkan PDF lebih dulu: pengunduh media Qontak menyerah ~5 detik.
                    try {
                        ($dokumen['pdf'])();
                    } catch (\Throwable) {
                    }
                }

                $this->pengirim->wa($ref, $nomor, $templateId, $params, $docUrl, $pakaiDok ? ($dokumen['nama'] ?? null) : null);
            }
        }

        if ($kanal === null || $kanal === 'EMAIL') {
            if ($tpl && ! $tpl->kirim_email) {
                $this->pengirim->lewati($ref, 'EMAIL', $email, 'Email dinonaktifkan di Template Pesan ('.JenisPesan::label($ref['jenis']).')');

                return;
            }

            if ($tpl && filled(strip_tags((string) $tpl->email_isi))) {
                $subjek = JenisPesan::isi($tpl->email_subjek ?: JenisPesan::label($ref['jenis']), $nilai);
                $lampiran = ($tpl->email_lampiran && isset($dokumen['pdf']))
                    ? fn () => [($dokumen['pdf'])(), (string) ($dokumen['nama'] ?? 'dokumen.pdf')]
                    : null;
                $mail = new PesanTemplateMail($subjek, JenisPesan::isi($tpl->email_isi, $nilai, true), $lampiran);
            } else {
                $mail = $mailBawaan();
                $subjek = (string) ($mail->envelope()->subject ?? '');
            }

            $this->pengirim->email($ref, $email, $mail, $subjek, $ulang);
        }
    }
}
