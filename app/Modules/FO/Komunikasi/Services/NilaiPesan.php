<?php

namespace App\Modules\FO\Komunikasi\Services;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Settings\CollectionSettings;
use Throwable;

/** Nilai kata kunci {…} Template Pesan untuk satu NUP / satu kwitansi. */
final class NilaiPesan
{
    public static function namaPerusahaan(): string
    {
        try {
            return (string) (app(CollectionSettings::class)->sp_company_name ?: 'PT. MITRA USAHA PROPERTINDO');
        } catch (Throwable) {
            return 'PT. MITRA USAHA PROPERTINDO';
        }
    }

    /** @return array<string,string> */
    public static function dariNup(NupRegistration $r, ?string $linkSprs = null): array
    {
        $r->loadMissing(['customer', 'event', 'projectPref', 'agent', 'unit.lokasi']);
        // Yang benar-benar dibayar bila ada, selain itu nominal UTJ. Bandingkan sebagai angka:
        // kolom decimal datang sebagai string "0.00" yang truthy bagi operator ?:.
        $nominal = collect([$r->xendit_paid_amount, $r->booking_fee_paid, $r->utj_amount])
            ->map(fn ($v) => (float) $v)->first(fn ($v) => $v > 0) ?? 0.0;
        $acara = $r->event?->name ?: ($r->projectPref?->name ?: '-');

        return [
            'nama' => (string) $r->nama_pendaftar,
            'no_hp' => (string) ($r->phone ?: $r->customer?->mobile ?: '-'),
            'email' => (string) ($r->email ?: $r->customer?->email ?: '-'),
            'cluster' => (string) ($r->unit?->lokasi?->nama ?: '-'),
            'nama_perusahaan' => self::namaPerusahaan(),
            'tanggal' => now()->translatedFormat('d F Y'),
            'no_nup' => (string) ($r->no_nup ?: '-'),
            'nama_acara' => $acara,
            'tgl_launching' => optional($r->event?->tgl_launching)->translatedFormat('d M Y') ?? '-',
            'nominal_utj' => 'Rp '.number_format($nominal, 0, ',', '.'),
            'nominal_angka' => number_format($nominal, 0, ',', '.'),
            'link_bayar' => (string) ($r->xendit_invoice_url ?: '-'),
            'nik' => (string) ($r->nik ?: ($r->customer?->nik ?: '-')),
            'sales' => (string) ($r->agent?->sales_label ?: ($r->ref_sales_name ?: '-')),
            'link_sprs' => (string) ($linkSprs ?: '-'),
        ];
    }

    /** @return array<string,string> */
    public static function dariBkm(Bkm $b): array
    {
        $b->loadMissing(['customer', 'kontrak.unit.lokasi', 'kontrak.tagihan']);
        $k = $b->kontrak;
        $sisa = $k ? $k->tagihan->sum(fn ($t) => max(0, (float) $t->nilai - (float) $t->pelunasan)) : 0;
        $jenis = match ((string) $b->jenis_pembayaran) {
            'BOOKING' => 'Booking Fee / UTJ',
            'DP' => 'Uang Muka (DP)',
            'ANGSURAN' => 'Angsuran',
            'PELUNASAN' => 'Pelunasan',
            default => 'Pembayaran',
        };

        return [
            'nama' => (string) ($b->customer?->full_name ?: '-'),
            'no_hp' => (string) ($b->customer?->mobile ?: ($b->customer?->phone ?: '-')),
            'email' => (string) ($b->customer?->email ?: '-'),
            'cluster' => (string) ($k?->unit?->lokasi?->nama ?: '-'),
            'nama_perusahaan' => self::namaPerusahaan(),
            'tanggal' => now()->translatedFormat('d F Y'),
            'no_kwitansi' => (string) $b->no_bkm,
            'no_kontrak' => (string) ($k?->no_kontrak ?: '-'),
            'unit' => (string) ($k?->unit?->kode_unit ?: '-'),
            'jenis_pembayaran' => $jenis,
            'nominal' => 'Rp '.number_format((float) $b->amount, 0, ',', '.'),
            'nominal_angka' => number_format((float) $b->amount, 0, ',', '.'),
            'tgl_bayar' => optional($b->tgl_bkm)->translatedFormat('d F Y') ?? '-',
            'sisa_tagihan' => 'Rp '.number_format((float) $sisa, 0, ',', '.'),
        ];
    }
}
