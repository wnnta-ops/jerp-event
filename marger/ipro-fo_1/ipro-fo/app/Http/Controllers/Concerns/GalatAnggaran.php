<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\RedirectResponse;

/**
 * Menyalurkan penolakan gerbang anggaran ke DIALOG, bukan toast.
 *
 * OVER_BUDGET dan BUDGET_TIDAK_ADA membawa angka yang harus dibaca (anggaran, realisasi,
 * sisa), dan tindakan lanjutannya berbeda: minta tambahan, atau minta anggarannya dibuat.
 * Toast lewat dalam hitungan detik dan orang menyimpulkan dokumennya tersimpan.
 *
 * Ada di satu tempat supaya tiap controller tidak menuliskan penyaluran yang sama \u2014 dan
 * supaya tidak ada yang lupa, lalu penolakannya diam-diam kembali jadi toast di satu layar.
 */
trait GalatAnggaran
{
    /** @var list<string> */
    private array $kodeAnggaran = ['OVER_BUDGET', 'BUDGET_TIDAK_ADA'];

    protected function balikDenganGalat(BusinessRuleException $e, bool $bawaInput = true): RedirectResponse
    {
        $balik = back();
        if ($bawaInput) {
            $balik = $balik->withInput();
        }

        if (in_array($e->errorCode(), $this->kodeAnggaran, true)) {
            return $balik->with('budgetError', [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);
        }

        return $balik->with('error', $e->getMessage());
    }
}
