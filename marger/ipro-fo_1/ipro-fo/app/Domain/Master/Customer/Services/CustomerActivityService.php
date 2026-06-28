<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Services;

use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengumpulkan riwayat aktivitas customer dari SELURUH modul (360°).
 * Mengembalikan daftar event seragam, terurut terbaru → lama.
 * Memakai query builder (bukan Eloquent) agar ringan & lintas-domain.
 */
final class CustomerActivityService
{
    /** @return array{events: array<int,array<string,mixed>>, counts: array<string,int>} */
    public function timeline(Customer $customer, int $limit = 300): array
    {
        $cid = $customer->id;
        $bookingIds = $this->exists('bookings')
            ? DB::table('bookings')->where('customer_id', $cid)->whereNull('deleted_at')->pluck('id')->all()
            : [];

        $events = [];
        $push = function (?string $at, string $module, string $icon, string $title, ?string $detail = null, $amount = null, ?string $status = null) use (&$events): void {
            if (! $at) {
                return;
            }
            $events[] = [
                'at'     => $at,
                'date'   => substr($at, 0, 10),
                'module' => $module,
                'icon'   => $icon,
                'title'  => $title,
                'detail' => $detail,
                'amount' => $amount !== null && $amount !== '' ? (float) $amount : null,
                'status' => $status !== null && $status !== '' ? (string) $status : null,
            ];
        };
        $safe = function (callable $fn): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                // Sumber tidak tersedia / kolom beda → lewati tanpa merusak halaman.
            }
        };

        // ---------- FRONT OFFICE ----------
        $safe(fn () => $this->each('crm_leads', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->created_at, 'CRM', 'users', 'Lead '.$r->lead_number, 'Tahap: '.$r->stage, null, $r->stage)));
        $safe(fn () => $this->each('nup_registrations', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->registered_at ?? $r->created_at, 'NUP', 'ticket', 'NUP '.$r->nup_number, 'Antrian #'.$r->queue_no, null, $r->status)));
        $safe(fn () => $this->each('unit_holds', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->held_at ?? $r->created_at, 'Hold', 'hand', 'Hold Unit', $r->reason, null, $r->status)));
        $safe(fn () => $this->each('bookings', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->booking_date ?? $r->created_at, 'Booking', 'shopping-cart', 'Booking '.$r->booking_number, null, $r->total_price, $r->status)));
        $safe(fn () => $this->each('contracts', fn ($q) => $q->whereIn('booking_id', $bookingIds)->whereNull('deleted_at'), fn ($r) => $push($r->ppjb_date ?? $r->signed_at ?? $r->created_at, 'PPJB', 'file-text', 'Kontrak '.$r->contract_number, 'PPJB: '.($r->ppjb_status ?? $r->status), null, $r->ppjb_status ?? $r->status)));
        $safe(fn () => $this->each('tts_receipts', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->received_date ?? $r->created_at, 'TTS', 'receipt', 'TTS '.$r->tts_number, 'Metode: '.$r->payment_method, $r->amount, $r->status)));
        $safe(fn () => $this->each('kuitansis', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->issued_date ?? $r->created_at, 'Kuitansi', 'file-check', 'Kuitansi '.$r->kuitansi_number, null, $r->amount, $r->status)));
        $safe(fn () => $this->each('memos', fn ($q) => $q->where('reference_type', 'like', '%Booking%')->whereIn('reference_id', $bookingIds)->whereNull('deleted_at'), fn ($r) => $push($r->memo_date ?? $r->created_at, 'Memo', 'sticky-note', 'Memo '.$r->memo_number, $r->type, $r->amount, $r->status)));
        $safe(fn () => $this->each('cashbacks', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->created_at, 'Cashback', 'gift', 'Cashback', $r->reason, $r->amount, $r->status)));
        $safe(fn () => $this->each('refunds', fn ($q) => $q->where('refundable_type', 'like', '%Booking%')->whereIn('refundable_id', $bookingIds), fn ($r) => $push($r->requested_at ?? $r->created_at, 'Refund', 'rotate-ccw', 'Refund '.$r->refund_number, null, $r->amount, $r->status)));
        $safe(fn () => $this->each('commissions', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->created_at, 'Komisi', 'percent', 'Komisi', null, $r->base_amount, $r->status)));
        $safe(fn () => $this->each('kpr_applications', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->submitted_at ?? $r->created_at, 'KPR', 'landmark', 'KPR '.$r->application_number, 'Tenor '.$r->tenor_month.' bln', $r->plafon_approved ?? $r->plafon_requested, $r->status)));
        $safe(fn () => $this->each('ajb_documents', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->signed_at ?? $r->created_at, 'Legal', 'scroll', 'AJB '.$r->ajb_number, 'Notaris: '.$r->notary_name, null, $r->status)));
        $safe(fn () => $this->each('bast_documents', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->handed_over_at ?? $r->created_at, 'Legal', 'scroll', 'BAST '.$r->bast_number, 'Serah terima unit', null, $r->status)));
        $safe(fn () => $this->each('sertifikat_documents', fn ($q) => $q->where('owner_customer_id', $cid), fn ($r) => $push($r->issued_at ?? $r->created_at, 'Legal', 'scroll', 'Sertifikat '.$r->serial_number, $r->type, null, null)));
        $safe(fn () => $this->each('faktur_pajaks', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->issued_at ?? $r->created_at, 'Pajak', 'file-badge', 'Faktur Pajak '.$r->fp_number, 'PPN Rp '.number_format((float) $r->ppn, 0, ',', '.'), $r->total, $r->status)));
        $safe(fn () => $this->each('virtual_accounts', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->created_at, 'VA', 'credit-card', 'Virtual Account '.$r->va_number, null, null, $r->status)));

        // ---------- COLLECTION ----------
        $safe(fn () => $this->each('followups', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->contacted_at ?? $r->created_at, 'Collection', 'phone', 'Follow-up', $r->result ?? $r->notes, null, $r->status)));
        $safe(fn () => $this->each('surat_peringatans', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->issued_at ?? $r->created_at, 'Collection', 'alert-triangle', 'Surat Peringatan SP'.$r->level, null, null, $r->status)));
        $safe(fn () => $this->each('tunggakan_headers', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->first_overdue_at ?? $r->created_at, 'Collection', 'alert-circle', 'Tunggakan', $r->category, $r->total_amount, $r->status)));
        $safe(fn () => $this->each('pjt_headers', fn ($q) => $q->where('customer_id', $cid), fn ($r) => $push($r->period_at ?? $r->created_at, 'Collection', 'calendar', 'PJT '.$r->pjt_number, 'Jatuh tempo', $r->total_overdue, $r->status)));

        // ---------- EXCEPTION ----------
        $safe(fn () => $this->each('name_change_requests', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->requested_at ?? $r->created_at, 'Exception', 'user-cog', 'Ganti Nama (GN)', 'Biaya admin', $r->admin_fee, $r->status)));
        $safe(fn () => $this->each('unit_change_requests', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->created_at, 'Exception', 'refresh-cw', 'Ganti Unit (GU)', 'Selisih harga', $r->price_diff, $r->status)));
        $safe(fn () => $this->each('reschedule_requests', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->created_at, 'Exception', 'calendar-clock', 'Reschedule', 'Biaya admin', $r->admin_fee, $r->status)));
        $safe(fn () => $this->each('cancellation_requests', fn ($q) => $q->whereIn('booking_id', $bookingIds), fn ($r) => $push($r->requested_at ?? $r->created_at, 'Exception', 'x-circle', 'Pembatalan', $r->reason, $r->refund_amount, $r->status)));

        // ---------- BUSINESS SUPPORT (Akuntansi) ----------
        $safe(fn () => $this->each('bs_ar_invoices', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->invoice_date ?? $r->created_at, 'Piutang', 'coins', 'Invoice Piutang '.$r->invoice_number, null, $r->total, $r->status)));
        $safe(fn () => $this->each('bs_ar_kuitansis', fn ($q) => $q->where('customer_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->kuitansi_date ?? $r->created_at, 'Piutang', 'file-check', 'Kuitansi Piutang '.$r->kuitansi_number, null, $r->total, $r->status)));

        // ---------- CATATAN AKTIVITAS (CRM Jurnal polimorfik) ----------
        $safe(fn () => $this->each('activity_logs', fn ($q) => $q->where('subject_type', 'like', '%Customer%')->where('subject_id', $cid)->whereNull('deleted_at'), fn ($r) => $push($r->logged_at ?? $r->created_at, 'Aktivitas', 'activity', $r->category ?? 'Catatan', $r->description, null, null)));

        // Urut terbaru → lama.
        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        // Hitung per modul (untuk chip filter) sebelum dipotong.
        $counts = [];
        foreach ($events as $e) {
            $counts[$e['module']] = ($counts[$e['module']] ?? 0) + 1;
        }
        arsort($counts);

        return ['events' => array_slice($events, 0, $limit), 'counts' => $counts];
    }

    /** Jalankan query pada tabel (bila ada) dan terapkan callback per baris. */
    private function each(string $table, callable $scope, callable $row): void
    {
        if (! $this->exists($table)) {
            return;
        }
        $scope(DB::table($table))->get()->each($row);
    }

    private function exists(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
