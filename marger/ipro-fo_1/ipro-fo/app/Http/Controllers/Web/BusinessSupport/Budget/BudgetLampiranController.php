<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\LampiranPerubahanBudget;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** Halaman cetak Lampiran Perubahan Budget untuk satu Alokasi / Koreksi Budget. */
class BudgetLampiranController extends Controller
{
    public function alokasi(BsBudgetAllocation $allocation, LampiranPerubahanBudget $lampiran): Response
    {
        return response(view('pdf.lampiran-perubahan-budget', $lampiran->alokasi($allocation)));
    }

    public function koreksi(BsBudgetCorrection $correction, LampiranPerubahanBudget $lampiran): Response
    {
        return response(view('pdf.lampiran-perubahan-budget', $lampiran->koreksi($correction)));
    }
}
