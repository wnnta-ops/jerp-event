<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Exports\BookingExport;
use App\Exports\CustomerExport;
use App\Exports\KprApplicationExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function customers(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('master.customer.export'), 403);

        return (new CustomerExport($request->only(['status'])))
            ->toResponse('customers-'.now()->format('Y-m-d').'.csv');
    }

    public function bookings(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reporting.report.export'), 403);

        return (new BookingExport($request->only(['status', 'date_from', 'date_to'])))
            ->toResponse('bookings-'.now()->format('Y-m-d').'.csv');
    }

    /** Report Proses KPR ke Microsoft Excel (.xlsx) — filter sama dengan halaman daftar. */
    public function kprApplications(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        return (new KprApplicationExport($request->only(['status', 'search', 'project_id'])))
            ->toResponse('kpr-applications-'.now()->format('Y-m-d').'.xlsx');
    }
}
