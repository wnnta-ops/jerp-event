<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rincian Batal Akad — daftar customer yang KPR-nya BATAL / DITOLAK.
 *
 * Baris-baris ini SENGAJA disaring keluar dari antrean Approval Retensi & Approval UM
 * Penjualan (lihat BankApprovalController::retensiIndex/umIndex) supaya tidak ikut
 * diapprove; halaman ini menjadi tempat menelusurinya.
 *
 * Definisi (disepakati): batal = kpr_applications.status ∈ {cancelled, rejected}.
 * Tanggal batal akad = decided_at — diisi saat keputusan "Batal" / penolakan ditetapkan.
 */
class BatalAkadController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('businesssupport') || $request->user()?->can('bankloan.application.view'), 403);

        $rows = $this->rows($request);

        return Inertia::render('BusinessSupport/BatalAkad/Index', [
            'rows' => $rows,
            'totals' => $this->totalsOf($rows),
        ]);
    }

    /**
     * Ekspor Rincian Batal Akad ke Excel.
     *
     * Izinnya DISALIN persis dari index — gerbangnya ATAU, bukan canModule saja. Menyeragamkan
     * ke satu modul akan menutup pintu bagi pengguna KPR yang justru paling sering memakainya.
     */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('businesssupport') || $request->user()?->can('bankloan.application.view'), 403);

        // Layarnya yang menentukan baris mana yang diekspor, lewat DAFTAR ID.
        //
        // Sebelumnya tombol ini mengirim q/status/akad dan di sini logika penyaringannya
        // disalin ulang. Salinan itu harus ikut berubah setiap kali layarnya bertambah filter,
        // dan sekarang filternya sepuluh — satu per kolom. Menyalinnya lagi berarti berkas dan
        // layar akan memulangkan baris berbeda untuk saringan yang sama, tanpa satu pun galat.
        // Dengan id, berkasnya berisi persis yang sedang terlihat.
        $ids = collect(explode(',', (string) $request->input('ids')))
            ->map(fn ($v) => (int) trim((string) $v))->filter()->unique();

        $rows = $this->rows($request, semua: true);
        // Tanpa ids (mis. pranala lama yang tersimpan) seluruh baris diekspor — lebih baik
        // kelebihan baris daripada berkas kosong tanpa penjelasan.
        if ($ids->isNotEmpty()) {
            $rows = $rows->filter(fn (array $r) => $ids->contains((int) $r['id']));
        }
        $rows = $rows->values();

        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $t = fn ($v) => '<td style="mso-number-format:\'\@\'">'.$e($v).'</td>';
        $n = fn ($v) => '<td>'.number_format((float) ($v ?? 0), 2, ',', '.').'</td>';

        $h = '<table border="1"><tr style="background:#c2410c;color:#fff">'
            .'<th>No</th><th>Customer</th><th>No. KPR</th><th>Unit</th><th>No. SPR</th><th>Bank</th>'
            .'<th>Status</th><th>Tgl Batal</th><th>Tahap Sebelum Batal</th><th>Sempat Akad</th>'
            .'<th>Tgl Akad</th><th>Alasan</th><th>Plafon</th></tr>';

        $no = 0;
        $plafon = 0.0;
        foreach ($rows as $r) {
            $no++;
            $plafon += (float) $r['plafon'];
            $h .= '<tr>'.$t($no).$t($r['customer']).$t($r['kpr_number']).$t($r['unit']).$t($r['booking'])
                .$t($r['bank']).$t($r['status_label']).$t($r['batal_at']).$t($r['tahap_sebelum'])
                .$t($r['sempat_akad'] ? 'Sudah' : 'Belum').$t($r['akad_at']).$t($r['alasan'])
                .$n($r['plafon']).'</tr>';
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="12">TOTAL — '.$no.' record</td>'
            .$n($plafon).'</tr></table>';

        $file = 'Rincian-Batal-Akad-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>RINCIAN BATAL AKAD</h3>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Baris Rincian Batal Akad.
     *
     * @param  bool  $semua  lepaskan batas 500 baris. Layar memakai batas itu agar ringan,
     *                       tetapi ekspor yang ikut terpotong akan terbaca sebagai "memang
     *                       segitu datanya" — diam-diam menyembunyikan sisanya.
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function rows(Request $request, bool $semua = false): \Illuminate\Support\Collection
    {
        $apps = KprApplication::query()
            ->whereIn('status', [KprStatus::Cancelled->value, KprStatus::Rejected->value])
            ->with([
                'customer:id,full_name',
                'bank:id,name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
                'akad:id,kpr_application_id,akad_at',
            ])
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->when(! $semua, fn ($q) => $q->limit(500))
            ->get();

        $rows = $apps->map(function (KprApplication $k) {
            $before = $k->status_before_cancel ? KprStatus::tryFrom($k->status_before_cancel) : null;
            $akadAt = optional($k->akad?->akad_at)->toDateString();

            return [
                'id'            => $k->id,
                'kpr_number'    => $k->application_number,
                // Layar menggabungkan pengajuan per customer. Kuncinya id, BUKAN nama: dua
                // orang berbeda yang kebetulan bernama sama akan tergabung jadi satu baris,
                // dan pembatalan milik orang lain terbaca sebagai miliknya.
                'customer_id'   => $k->customer_id,
                'customer'      => $k->customer?->full_name,
                'unit'          => $k->booking?->unit?->unit_code,
                'booking'       => $k->booking?->booking_number,
                'bank'          => $k->bank?->name,
                'status'        => $k->status->value,          // cancelled | rejected
                'status_label'  => $k->status->label(),
                // Tanggal batal akad — inti halaman ini.
                'batal_at'      => optional($k->decided_at)->toDateString(),
                // Konteks: sudah sejauh mana sebelum dibatalkan, & apakah akadnya sempat terjadi.
                'tahap_sebelum' => $before?->label(),
                'akad_at'       => $akadAt,
                'sempat_akad'   => $akadAt !== null,
                'alasan'        => $k->reject_reason,
                'plafon'        => (float) ($k->plafon_approved ?: $k->plafon_requested),
            ];
        });

        return $rows;
    }

    /**
     * Ringkasan kartu atas — dihitung dari baris yang sama dengan yang ditampilkan.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function totalsOf(\Illuminate\Support\Collection $rows): array
    {
        return [
            'count'       => $rows->count(),
            'cancelled'   => $rows->where('status', 'cancelled')->count(),
            'rejected'    => $rows->where('status', 'rejected')->count(),
            'sempat_akad' => $rows->where('sempat_akad', true)->count(),
        ];
    }
}
