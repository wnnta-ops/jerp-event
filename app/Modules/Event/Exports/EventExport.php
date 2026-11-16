<?php

namespace App\Modules\Event\Exports;

use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Services\EventReportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export satu event ke Excel: Ringkasan, Per Pos, Per Customer, Kunjungan, Log Scan.
 * Durasi ditulis dalam MENIT desimal (angka, bisa dijumlah/dirata-rata di Excel).
 * Filter sama dengan halaman Laporan (lihat EventReportService).
 */
class EventExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $filter */
    public function __construct(private readonly EtEvent $event, private readonly array $filter = []) {}

    public function sheets(): array
    {
        $r = app(EventReportService::class);
        $f = $this->filter;
        $menit = fn (?int $detik) => $detik === null ? null : round($detik / 60, 1);
        $jam = fn ($t) => $t?->format('Y-m-d H:i:s');

        // Ringkasan
        $rk = $r->ringkasan($this->event, $f);
        $ringkasan = [
            ['Event', $this->event->name],
            ['Tanggal', $this->event->event_date->format('Y-m-d')],
            ['Lokasi', $this->event->venue],
            ['Jenis', $this->event->isMandiri() ? 'Mandiri (per customer)' : 'Terhubung event NUP FO (per NUP)'],
            ['Filter waktu', trim(($f['dari'] ?? '').' s.d. '.($f['sampai'] ?? ''), ' s.d.') ?: 'semua'],
            ['Filter status', implode(', ', (array) ($f['status'] ?? [])) ?: 'semua'],
            ['Diekspor', now()->format('Y-m-d H:i:s')],
            [],
            ['Peserta', $rk['peserta']],
            ['Aktif', $rk['aktif']],
            ['Selesai semua pos', $rk['selesai']],
            ['Pulang', $rk['pulang']],
            ['Batal aktivasi', $rk['batal']],
            ['Kunjungan', $rk['kunjungan']],
            ['Total waktu rata-rata (menit)', $menit($rk['total_rata'])],
            ['Total waktu median (menit)', $menit($rk['total_median'])],
            ['Total waktu P90 (menit)', $menit($rk['total_p90'])],
            ['Tunggu rata-rata per pos (menit)', $menit($rk['tunggu_rata'])],
            ['Kunjungan hasil koreksi', $rk['koreksi']],
        ];

        // Per pos
        $perPos = array_map(fn ($p) => [
            $p['urutan'], $p['pos'], $p['opsional'] ? 'ya' : '', $p['target_menit'],
            $p['kunjungan'], $p['selesai'], $p['berjalan'],
            $menit($p['layanan_rata']), $menit($p['layanan_median']), $menit($p['layanan_p90']),
            $menit($p['tunggu_rata']), $menit($p['tunggu_p90']),
            $p['lewat_target'], $p['lewat_target_persen'],
        ], $r->perPos($this->event, $f));

        // Per customer — satu kolom lama layanan per pos
        $stations = EtStation::query()->where('event_id', $this->event->id)->where('type', '!=', EtStation::FO_CHECKIN)
            ->orderBy('sequence')->get();
        $perCustomer = array_map(fn ($c) => array_merge([
            $c['customer_code'], $c['nama'], $c['no_nup'], self::status($c['status']),
            $jam($c['check_in']), $jam($c['selesai']), $menit($c['total_detik']), $menit($c['tunggu_total_detik']),
        ], array_map(fn ($id) => $menit($c['pos'][$id] ?? null), $stations->pluck('id')->all()), [
            implode(', ', $c['dilewati']),
        ]), $r->perCustomer($this->event, $f));

        // Kunjungan
        $kunjungan = collect($r->kunjungan($this->event, $f))->map(fn ($v) => [
            $v['nama'], $v['no_nup'], $v['customer_code'], $v['urutan'], $v['pos'],
            $jam($v['mulai']), $jam($v['selesai']), $menit($v['layanan_detik']), $menit($v['tunggu_detik']),
            $v['target_menit'], $v['lewat_target'] === null ? '' : ($v['lewat_target'] ? 'ya' : 'tidak'),
            self::cara($v['cara_selesai']), $v['penanda'], $v['petugas'],
        ])->all();

        // Log scan
        $log = $r->logScan($this->event, $f)->map(fn (EtScanLog $l) => [
            $jam($l->server_time), $l->scanPoint?->station?->name, $l->scanPoint?->label,
            $l->raw_payload, $l->result, $l->reason, $l->user_id, $l->fo_nup_registration_id, $l->participant_id,
        ])->all();

        return [
            new EventSheet('Ringkasan', ['Keterangan', 'Nilai'], $ringkasan),
            new EventSheet('Per Pos', ['Urutan', 'Pos', 'Opsional', 'Target (mnt)', 'Kunjungan', 'Selesai', 'Berjalan',
                'Layanan rata2 (mnt)', 'Layanan median (mnt)', 'Layanan P90 (mnt)', 'Tunggu rata2 (mnt)', 'Tunggu P90 (mnt)',
                'Lewat target', 'Lewat target (%)'], $perPos),
            new EventSheet('Per Customer', array_merge(['Kode customer', 'Nama', 'No. NUP', 'Status', 'Check-in', 'Selesai',
                'Total (mnt)', 'Total tunggu (mnt)'], $stations->map(fn ($s) => $s->name.' (mnt)')->all(), ['Pos dilewati']), $perCustomer),
            new EventSheet('Kunjungan', ['Nama', 'No. NUP', 'Kode customer', 'Urutan pos', 'Pos', 'Mulai', 'Selesai',
                'Layanan (mnt)', 'Tunggu (mnt)', 'Target (mnt)', 'Lewat target', 'Cara selesai', 'Penanda', 'Petugas'], $kunjungan),
            new EventSheet('Log Scan', ['Waktu server', 'Pos', 'Titik scan', 'Isi scan', 'Hasil', 'Alasan', 'User ID',
                'ID NUP FO', 'ID peserta'], $log),
        ];
    }

    public static function status(string $s): string
    {
        return ['active' => 'Aktif', 'finished' => 'Selesai', 'left' => 'Pulang', 'cancelled' => 'Batal aktivasi'][$s] ?? $s;
    }

    public static function cara(?string $c): string
    {
        return [
            'scan_keluar' => 'Scan keluar', 'manual' => 'Geser / tombol selesai', 'next_scan' => 'Otomatis (masuk pos lain)',
            'koreksi' => 'Koreksi', 'event_closed' => 'Event ditutup',
        ][$c] ?? '';
    }
}
