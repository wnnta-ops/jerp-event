<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Numerator\Services;

use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Exceptions\BusinessRuleException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class NumeratorService
{
    /**
     * Generate nomor dokumen berikutnya berdasarkan `code` (mis. 'CUST', 'BOOK', 'TTS').
     *
     * @param array<string, scalar|null> $context Variable interpolation: PROJECT, COMPANY, dst.
     *
     * @throws BusinessRuleException
     */
    /**
     * @param  string  $scope  'fo' untuk dokumen Front Office, 'bo' untuk Back Office. Kedua
     *                         kelompok punya daftar kodenya sendiri dan tidak saling
     *                         menimpa — tanpa pemisahan ini, kode yang kebetulan sama
     *                         (mis. FP) akan berbagi satu deret nomor.
     */
    public function next(string $code, array $context = [], string $scope = 'fo'): string
    {
        return DB::transaction(function () use ($code, $context, $scope) {
            $numerator = Numerator::query()
                ->where('code', $code)
                ->where('scope', $scope)
                ->when(isset($context['project_id']), fn ($q) => $q->where('project_id', $context['project_id']))
                ->lockForUpdate()
                ->first();

            if (! $numerator) {
                throw new BusinessRuleException(
                    "Numerator dengan code '{$code}' belum di-setup. Tambahkan di Settings > Numerator.",
                    'NUMERATOR_NOT_FOUND',
                    422,
                    ['code' => $code],
                );
            }

            $period = $this->resolvePeriod($numerator->reset_strategy);

            if ($numerator->current_period !== $period) {
                $numerator->current_period = $period;
                $numerator->current_seq    = 0;
            }

            $numerator->current_seq = $numerator->current_seq + 1;
            $numerator->save();

            return $this->format(
                $numerator->format ?: config('domain.numerator.fallback_format'),
                array_merge([
                    'PREFIX'  => $numerator->prefix,
                    'YYYY'    => now()->format('Y'),
                    'YY'      => now()->format('y'),
                    'MM'      => now()->format('m'),
                    'DD'      => now()->format('d'),
                    'SEQ'     => str_pad((string) $numerator->current_seq, $numerator->seq_padding, '0', STR_PAD_LEFT),
                    'PROJECT' => '',
                    'COMPANY' => '',
                ], $context),
            );
        });
    }

    /**
     * Preview tanpa increment (untuk display di UI).
     */
    public function preview(string $code, array $context = [], string $scope = 'fo'): string
    {
        $numerator = Numerator::query()->where('code', $code)->where('scope', $scope)->first();
        if (! $numerator) {
            return "{$code}-PREVIEW";
        }
        $nextSeq = $numerator->current_seq + 1;

        return $this->format(
            $numerator->format ?: config('domain.numerator.fallback_format'),
            array_merge([
                'PREFIX'  => $numerator->prefix,
                'PROJECT' => '',
                'COMPANY' => '',
                'YYYY'   => now()->format('Y'),
                'YY'     => now()->format('y'),
                'MM'     => now()->format('m'),
                'DD'     => now()->format('d'),
                'SEQ'    => str_pad((string) $nextSeq, $numerator->seq_padding, '0', STR_PAD_LEFT),
            ], $context),
        );
    }

    private function resolvePeriod(string $strategy): ?string
    {
        return match ($strategy) {
            'yearly'  => Carbon::now()->format('Y'),
            'monthly' => Carbon::now()->format('Ym'),
            'daily'   => Carbon::now()->format('Ymd'),
            default   => null,
        };
    }

    /**
     * @param array<string, scalar|null> $vars
     */
    private function format(string $template, array $vars): string
    {
        // Inject derived month formats if not explicitly provided (Java Core System Lama parity).
        $month = (int) now()->format('n');
        $vars += [
            'MM_ROMAN'  => self::romanMonth($month),   // V untuk Mei
            'MMM'       => now()->format('M'),          // May
            'MMM_ID'    => self::monthAbbrId($month),   // Mei
        ];

        $result = $template;
        foreach ($vars as $key => $value) {
            $result = str_replace('{'.$key.'}', (string) $value, $result);
        }

        return $result;
    }

    /**
     * Convert month number (1-12) ke Roman numeral (I-XII).
     */
    public static function romanMonth(int $month): string
    {
        return [
            1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
            7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
        ][$month] ?? (string) $month;
    }

    /**
     * Convert month number ke singkatan bulan Indonesia.
     */
    public static function monthAbbrId(int $month): string
    {
        return [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ][$month] ?? (string) $month;
    }
}
