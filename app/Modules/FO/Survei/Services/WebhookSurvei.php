<?php

namespace App\Modules\FO\Survei\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\Survei\Jobs\KirimWebhookSurvei;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhook perubahan SPR J-ERP → aplikasi Survei Kepuasan Customer.
 *
 * Kontrak (dok. tim survei, "Webhook SPR J-ERP"):
 *   POST {SURVEI_WEBHOOK_URL} · JSON · header X-Jerp-Timestamp + X-Jerp-Signature (HMAC-SHA256
 *   atas "{timestamp}.{body}") · body {id, event, occurred_at, data{no_spr, nama, unit, status}}
 *   event: spr.created | spr.updated | spr.cancelled | spr.deleted
 *
 * Isinya SENGAJA sama dengan GET /api/survei/spr — tanpa NIK/HP/email.
 * Mati selama SURVEI_WEBHOOK_URL atau SURVEI_WEBHOOK_SECRET kosong: tidak ada yang dicatat
 * maupun dikirim, jadi aman dinaikkan lebih dulu.
 */
class WebhookSurvei
{
    /** Jeda kirim ulang (detik) sesudah percobaan ke-1..6 — total 7 percobaan ±10 jam. */
    public const JEDA = [60, 300, 900, 3600, 10800, 21600];

    public static function aktif(): bool
    {
        return (string) config('services.survei.webhook_url') !== ''
            && (string) config('services.survei.webhook_secret') !== '';
    }

    /** Data SPR untuk body webhook — bentuk & isinya sama dengan API survei. */
    public static function dataKontrak(Kontrak $k): array
    {
        $k->loadMissing(['customer:id,full_name', 'unit:id,kode_unit']);

        return [
            'no_spr' => (string) $k->no_kontrak,
            'nama' => $k->customer?->full_name,
            'unit' => $k->unit?->kode_unit,
            'status' => (string) $k->status,
        ];
    }

    /**
     * Catat satu notifikasi ke antrean lalu kirim lewat job SESUDAH commit — notifikasi untuk
     * data yang akhirnya di-rollback tidak pernah berangkat.
     */
    public static function antre(string $event, array $data, ?CarbonInterface $terjadi = null): void
    {
        if (! self::aktif() || ($data['no_spr'] ?? '') === '') {
            return;
        }

        try {
            $uuid = (string) Str::uuid();
            $terjadi ??= now();
            $body = json_encode([
                'id' => $uuid,
                'event' => $event,
                'occurred_at' => $terjadi->toIso8601String(),
                'data' => $event === 'spr.deleted' ? ['no_spr' => $data['no_spr']] : $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $id = DB::table('webhook_keluar')->insertGetId([
                'tujuan' => 'survei', 'uuid' => $uuid, 'event' => $event, 'kunci' => $data['no_spr'],
                'body' => $body, 'terjadi_pada' => $terjadi, 'status' => 'antre',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            KirimWebhookSurvei::dispatch($id)->afterCommit();
        } catch (Throwable $e) {
            // Notifikasi yang gagal dicatat TIDAK boleh menggagalkan penyimpanan SPR-nya —
            // aplikasi survei tetap menyalin seluruh SPR lewat sinkron harian.
            report($e);
        }
    }

    /** Antre untuk sebuah kontrak tahap SPR. Event dipilih dari perubahan statusnya. */
    public static function antreKontrak(Kontrak $k, string $event): void
    {
        if (strtoupper((string) $k->tahap) !== 'SPR') {
            return;
        }
        self::antre($event, self::dataKontrak($k), $k->updated_at ?? now());
    }

    /**
     * Kirim satu baris antrean. Tanda tangan & timestamp dibuat ULANG tiap percobaan; `id` dan
     * body tetap sama. Memulangkan: 'terkirim' | 'ditolak' (jangan diulang) | 'ulang'.
     */
    public static function kirim(int $id): string
    {
        $row = DB::table('webhook_keluar')->where('id', $id)->first();
        if (! $row || in_array($row->status, ['terkirim', 'ditolak'], true)) {
            return 'terkirim';
        }

        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $row->body, (string) config('services.survei.webhook_secret'));
        $catat = fn (array $x) => DB::table('webhook_keluar')->where('id', $id)
            ->update($x + ['percobaan' => (int) $row->percobaan + 1, 'updated_at' => now()]);

        try {
            $res = Http::timeout(10)
                ->withHeaders(['X-Jerp-Timestamp' => $ts, 'X-Jerp-Signature' => 'sha256=' . $sig])
                ->withBody($row->body, 'application/json')   // string yang SAMA dengan yang ditandatangani
                ->post((string) config('services.survei.webhook_url'));
        } catch (Throwable $e) {
            $catat(['status' => 'antre', 'kode_http' => null, 'jawaban' => mb_substr('Koneksi gagal: ' . $e->getMessage(), 0, 2000)]);

            return 'ulang';
        }

        $jawaban = mb_substr((string) $res->body(), 0, 2000);
        if ($res->successful()) {
            $catat(['status' => 'terkirim', 'kode_http' => $res->status(), 'jawaban' => $jawaban, 'terkirim_pada' => now()]);

            return 'terkirim';
        }
        if (in_array($res->status(), [401, 413, 422], true)) {
            // Ditolak karena isi/tanda tangan — mengulang tidak akan menolong. Catat & berhenti.
            $catat(['status' => 'ditolak', 'kode_http' => $res->status(), 'jawaban' => $jawaban]);

            return 'ditolak';
        }
        $catat(['status' => 'antre', 'kode_http' => $res->status(), 'jawaban' => $jawaban]);

        return 'ulang';
    }
}
