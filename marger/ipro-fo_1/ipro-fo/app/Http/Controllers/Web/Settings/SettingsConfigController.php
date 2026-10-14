<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Konfigurasi Sistem (eProperty Settings: Setup PPN, Batas Booking, Mandatory,
 * Setup Penomoran VA, SMS Blast, Daily Report) — terkonsolidasi via tabel parameters.
 */
class SettingsConfigController extends Controller
{
    /** Skema: group => [key => [type, default, label]]. */
    private const SCHEMA = [
        'tax' => [
            'ppn_pct' => ['number', '11', 'Tarif PPN (%)'],
        ],
        'booking' => [
            'expiry_days' => ['int', '7', 'Batas Booking (hari kedaluwarsa)'],
            'dp_min_pct'  => ['number', '0', 'DP Minimum (%)'],
        ],
        'hold' => [
            'default_days' => ['int', '7', 'Hold Default (hari)'],
            'max_days'     => ['int', '14', 'Hold Maksimum (hari)'],
        ],
        'nup' => [
            'refund_penalty_pct' => ['number', '10', 'Penalti Refund NUP (%)'],
        ],
        'va' => [
            'prefix' => ['string', '8808', 'Prefix Nomor VA'],
            'digits' => ['int', '12', 'Jumlah Digit VA'],
        ],
        'mandatory' => [
            'require_ktp'       => ['bool', '1', 'Wajib KTP'],
            'require_kk'        => ['bool', '0', 'Wajib Kartu Keluarga'],
            'require_npwp'      => ['bool', '0', 'Wajib NPWP'],
            'require_slip_gaji' => ['bool', '0', 'Wajib Slip Gaji'],
        ],
        'notif' => [
            'sms_enabled'        => ['bool', '0', 'Aktifkan SMS Blast'],
            'sms_sender'         => ['string', '', 'Sender ID SMS'],
            'daily_report_email' => ['string', '', 'Email Tujuan Daily Report'],
        ],
        // Halaman "Selamat Datang" (pengguna tanpa izin Dashboard Back Office) — satu kalimat
        // per baris, tampil bergantian tiap hari. Kosong = tidak ada kalimat yang tampil.
        'beranda' => [
            'kata_motivasi' => ['text', self::MOTIVASI_AWAL, 'Kata Motivasi (satu kalimat per baris — tampil bergantian tiap hari)'],
        ],
    ];

    /** Isi awal kata motivasi sampai admin menggantinya. */
    private const MOTIVASI_AWAL = "Kerja yang rapi hari ini memudahkan pekerjaan esok hari.\n"
        ."Teliti di awal, tenang di akhir.\n"
        ."Setiap transaksi yang benar adalah kepercayaan yang terjaga.\n"
        ."Selesaikan yang penting dulu, lalu yang mendesak.\n"
        ."Kerja sama tim membuat beban terasa ringan.";

    /** Kata motivasi untuk hari ini — satu baris, bergantian per hari. null bila kosong. */
    public static function motivasiHariIni(): ?string
    {
        $isi = DB::table('parameters')->where('tenant_id', tenant_id() ?? default_tenant_id())
            ->where('group', 'beranda')->where('key', 'kata_motivasi')->value('value') ?? self::MOTIVASI_AWAL;
        $baris = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $isi)), fn ($b) => $b !== ''));

        return $baris === [] ? null : $baris[now()->dayOfYear % count($baris)];
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.setup.parameter'), 403);

        $stored = DB::table('parameters')->where('tenant_id', tenant_id() ?? default_tenant_id())
            ->get(['group', 'key', 'value'])->groupBy('group')
            ->map(fn ($g) => $g->pluck('value', 'key'));

        $config = [];
        foreach (self::SCHEMA as $group => $keys) {
            foreach ($keys as $key => [$type, $default, $label]) {
                $raw = $stored[$group][$key] ?? $default;
                $config[$group][$key] = $type === 'bool' ? ((string) $raw === '1')
                    : ($type === 'int' ? (int) $raw : ($type === 'number' ? (float) $raw : (string) $raw));
            }
        }

        return Inertia::render('Settings/Konfigurasi', ['config' => $config, 'schema' => $this->labels()]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('system.setup.parameter'), 403);

        $rules = [];
        foreach (self::SCHEMA as $group => $keys) {
            foreach ($keys as $key => [$type]) {
                $rules["config.{$group}.{$key}"] = match ($type) {
                    'bool'   => ['boolean'],
                    'int'    => ['nullable', 'integer', 'min:0'],
                    'number' => ['nullable', 'numeric', 'min:0'],
                    'text'   => ['nullable', 'string', 'max:5000'],
                    default  => ['nullable', 'string', 'max:191'],
                };
            }
        }
        $data = $request->validate($rules)['config'] ?? [];

        foreach (self::SCHEMA as $group => $keys) {
            foreach ($keys as $key => [$type, $default, $label]) {
                // Teks panjang yang DIKOSONGKAN memang berarti kosong (mis. tanpa kata motivasi),
                // bukan "kembalikan isi awal".
                $val = $type === 'text' ? (string) ($data[$group][$key] ?? '') : ($data[$group][$key] ?? $default);
                $stringVal = $type === 'bool' ? ((bool) $val ? '1' : '0') : (string) $val;
                // Petakan tipe skema → enum kolom data_type (string|int|decimal|bool|json).
                $dataType = match ($type) {
                    'bool'   => 'bool',
                    'int'    => 'int',
                    'number' => 'decimal',
                    default  => 'string',
                };
                DB::table('parameters')->updateOrInsert(
                    ['tenant_id' => tenant_id() ?? default_tenant_id(), 'group' => $group, 'key' => $key],
                    ['value' => $stringVal, 'data_type' => $dataType, 'label' => $label, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        return back()->with('success', 'Konfigurasi sistem disimpan.');
    }

    /** @return array<string,array<string,string>> label per group/key untuk UI. */
    private function labels(): array
    {
        $out = [];
        foreach (self::SCHEMA as $group => $keys) {
            foreach ($keys as $key => [$type, $default, $label]) {
                $out[$group][$key] = ['label' => $label, 'type' => $type];
            }
        }

        return $out;
    }
}
