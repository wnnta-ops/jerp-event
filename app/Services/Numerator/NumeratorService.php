<?php

namespace App\Services\Numerator;

use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Atomic document numerator.
 *
 * Allocates the next sequence for a document `code` using a row-level lock
 * (`SELECT ... FOR UPDATE`) so concurrent requests never collide — never
 * `MAX()+1`. The number format is stored on the `numerators` row as a template
 * with tokens:
 *
 *   {YYYY} 4-digit year   {YY} 2-digit year   {MM} month   {DD} day
 *   {seq}  raw sequence   {seq:N} zero-padded to N digits
 *
 * The reset period is derived from the finest date token present in the format
 * ({DD} → daily, {MM} → monthly, {YYYY}/{YY} → yearly, none → perpetual), so a
 * code like `TTS-{YYYY}{MM}-{seq:5}` resets its counter every month.
 */
class NumeratorService implements NumeratorServiceInterface
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function next(string $code, ?int $companyId = null): string
    {
        return $this->resolve($code, $companyId, consume: true);
    }

    public function peek(string $code, ?int $companyId = null): string
    {
        return $this->resolve($code, $companyId, consume: false);
    }

    private function resolve(string $code, ?int $companyId, bool $consume): string
    {
        $tenantId = $this->tenant->id();
        $now = Carbon::now();

        $format = $this->lookupFormat($tenantId, $code, $companyId);
        $period = $this->periodFor($format, $now);

        return DB::transaction(function () use ($tenantId, $companyId, $code, $period, $format, $now, $consume) {
            $row = DB::table('numerators')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->where('period', $period)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('numerators')->insert([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'code' => $code,
                    'format' => $format,
                    'last_seq' => 0,
                    'period' => $period,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = DB::table('numerators')
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->where('period', $period)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
            }

            $next = (int) $row->last_seq + 1;

            if ($consume) {
                DB::table('numerators')
                    ->where('id', $row->id)
                    ->update(['last_seq' => $next, 'updated_at' => $now]);
            }

            return $this->render($row->format, $next, $now);
        });
    }

    /**
     * Read the format template for a code. Prefers a company-scoped definition,
     * falling back to the tenant-global (company_id = null) definition.
     */
    private function lookupFormat(int $tenantId, string $code, ?int $companyId): string
    {
        $query = DB::table('numerators')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->where('is_active', true);

        if ($companyId !== null) {
            $row = (clone $query)->where('company_id', $companyId)->orderByDesc('period')->first()
                ?? (clone $query)->whereNull('company_id')->orderByDesc('period')->first();
        } else {
            $row = (clone $query)->whereNull('company_id')->orderByDesc('period')->first();
        }

        if ($row === null) {
            // Kode belum terdaftar → AUTO-REGISTER dengan format default. resolve()
            // membuat barisnya, sehingga kode penomoran baru OTOMATIS muncul di master
            // Penomoran (System → Numerator) dan formatnya bisa diubah di sana. Dengan
            // begitu semua penomoran tersintegrasi di satu tempat, tanpa perlu seeding.
            return $this->defaultFormat($code);
        }

        return $row->format;
    }

    /**
     * Format default untuk kode yang belum terdaftar (bulanan, reset tiap bulan).
     *
     * KWI dikecualikan: nomor kwitansi meneruskan penomoran sistem lama
     * (00400/KWI/6/2026) supaya nomor di kwitansi, di dashboard Xendit, dan di
     * arsip lama memakai bentuk yang sama. Ditaruh di sini — BUKAN sebagai migrasi
     * penyisip baris — agar tidak mengulang kegagalan register_spr_numerator yang
     * memutus rantai migrasi di database baru (tabel tenants masih kosong saat
     * migrate berjalan). Formatnya tetap bisa diubah di System → Penomoran.
     */
    private function defaultFormat(string $code): string
    {
        return match ($code) {
            'KWI' => '{seq:5}/KWI/{M}/{YYYY}',
            default => $code.'-{YYYY}{MM}-{seq:5}',
        };
    }

    private function periodFor(string $format, Carbon $now): string
    {
        return match (true) {
            str_contains($format, '{DD}') => $now->format('Ymd'),
            str_contains($format, '{MM}') => $now->format('Ym'),
            str_contains($format, '{YYYY}') || str_contains($format, '{YY}') => $now->format('Y'),
            default => '',
        };
    }

    private function render(string $format, int $seq, Carbon $now): string
    {
        // {M} = bulan TANPA nol di depan (6, bukan 06) — dipakai format kwitansi
        // warisan sistem lama: 00400/KWI/6/2026. strtr mencocokkan kunci terpanjang
        // lebih dulu, jadi {MM} tidak ikut terpotong oleh {M}.
        $out = strtr($format, [
            '{YYYY}' => $now->format('Y'),
            '{YY}' => $now->format('y'),
            '{MM}' => $now->format('m'),
            '{M}' => $now->format('n'),
            '{DD}' => $now->format('d'),
        ]);

        $out = preg_replace_callback(
            '/\{seq:(\d+)\}/',
            fn (array $m) => str_pad((string) $seq, (int) $m[1], '0', STR_PAD_LEFT),
            $out
        );

        return str_replace('{seq}', (string) $seq, $out);
    }
}
