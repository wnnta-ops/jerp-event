<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jembatan modul Legal (app/legal) ⇄ SPR Final (FO) ⇄ Detail AJB (ipro_bo).
 *
 * Alurnya satu arah dan hanya satu pintu: Legal mengarsipkan akta AJB lalu
 * menautkannya ke sebuah No. SPR (Final). FO-lah yang memiliki data SPR
 * (fo_kontrak) DAN jalur tulis ke ipro_bo, jadi kedua langkah itu dikerjakan di
 * sini — modul Legal tidak pernah menyentuh database selain miliknya sendiri.
 *
 * Yang ditulis ke ipro_bo.ajb_documents persis empat kolom yang ditampilkan
 * layar "Detail AJB" di Booked Revenue: notaris, nomor akta, tanggal, nominal.
 */
final class LegalAjbBridge
{
    /** Tahap kontrak FO yang berarti "SPR (Final)" (lawannya: SPRS). */
    private const TAHAP_FINAL = 'SPR';

    /** Kontrak batal tidak boleh diberi AJB — aktanya tak punya dasar. */
    private const STATUS_TERLARANG = ['BATAL'];

    /** Penanda kolom `source` pada ipro_bo.ajb_documents. */
    public const SUMBER_LEGAL = 'legal';

    public function __construct(private readonly IproMasterSync $sync) {}

    private function bo(): ConnectionInterface
    {
        return DB::connection((string) config('ipro.connection', 'ipro_bo'));
    }

    private function tenantId(): int
    {
        return (int) config('ipro.tenant_id', 1);
    }

    // ── 1. Pencarian SPR (Final) untuk penaut di formulir Legal ──────────────

    /**
     * Daftar SPR Final untuk dipilih di modul Legal.
     *
     * @return list<array<string,mixed>>
     */
    public function cariSpr(?string $q, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $kunci = trim((string) $q);

        $rows = Kontrak::query()
            ->where('tahap', self::TAHAP_FINAL)
            ->whereNotIn('status', self::STATUS_TERLARANG)
            ->with(['customer:id,full_name', 'unit:id,kode_unit,cluster,blok,no_unit'])
            ->when($kunci !== '', function ($b) use ($kunci) {
                $kata = '%'.$kunci.'%';
                $b->where(function ($w) use ($kata) {
                    $w->where('no_kontrak', 'like', $kata)
                        ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $kata))
                        ->orWhereHas('unit', fn ($u) => $u->where('kode_unit', 'like', $kata));
                });
            })
            ->orderByDesc('tgl_kontrak')->orderByDesc('id')
            ->limit($limit)->get();

        return $rows->map(fn (Kontrak $k) => [
            'spr'        => $k->no_kontrak,
            'customer'   => $k->customer?->full_name,
            'unit'       => $k->unit?->kode_unit,
            'cluster'    => $k->unit?->cluster,
            'blok'       => $k->unit?->blok,
            'no_unit'    => $k->unit?->no_unit,
            'tanggal'    => $k->tgl_kontrak?->toDateString(),
            'total'      => $k->total_kontrak === null ? null : (float) $k->total_kontrak,
            'status'     => $k->status,
            'cara_bayar' => $k->cara_bayar,
        ])->values()->all();
    }

    // ── 2. Dorong akta AJB dari Legal → ipro_bo.ajb_documents ───────────────

    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    public function simpan(array $in): array
    {
        $legalDocId = trim((string) ($in['legal_doc_id'] ?? ''));
        $spr        = trim((string) ($in['spr'] ?? ''));
        $nomor      = trim((string) ($in['ajb_number'] ?? ''));
        $tanggal    = trim((string) ($in['signed_at'] ?? ''));
        $notaris    = trim((string) ($in['notary_name'] ?? ''));
        $nominal    = $in['amount'] ?? null;

        if ($legalDocId === '' || $spr === '' || $nomor === '' || $tanggal === '') {
            return $this->gagal('DATA_KURANG', 'legal_doc_id, spr, ajb_number, dan signed_at wajib diisi.');
        }

        try {
            $tgl = Carbon::parse($tanggal)->toDateString();
        } catch (\Throwable) {
            return $this->gagal('TANGGAL_INVALID', 'Tanggal AJB tidak bisa dibaca: '.$tanggal);
        }

        $nominal = ($nominal === null || $nominal === '') ? null : (float) $nominal;
        if ($nominal !== null && $nominal < 0) {
            return $this->gagal('NOMINAL_INVALID', 'Nominal AJB tidak boleh negatif.');
        }

        // ── SPR harus ada, sudah Final, dan tidak batal ──
        $kontrak = Kontrak::query()->where('no_kontrak', $spr)->first();
        if (! $kontrak) {
            return $this->gagal('SPR_TIDAK_DITEMUKAN', 'No. SPR '.$spr.' tidak ada di Marketing & Sales.');
        }
        if ($kontrak->tahap !== self::TAHAP_FINAL) {
            return $this->gagal('SPR_BELUM_FINAL', 'No. SPR '.$spr.' masih tahap SPRS, belum SPR (Final).');
        }
        if (in_array((string) $kontrak->status, self::STATUS_TERLARANG, true)) {
            return $this->gagal('SPR_BATAL', 'No. SPR '.$spr.' berstatus BATAL.');
        }

        // ── Baris booking di ipro_bo; dibuat bila sinkron masternya belum sampai ──
        $bookingId = $this->bookingId($spr);
        if ($bookingId === null) {
            // syncSpr hanya membentuk baris booking + jadwal bayarnya, supaya
            // Detail AJB punya baris SPR untuk diisi. Nominal akta tidak diubah.
            $bookingId = $this->sync->syncSpr($kontrak);
        }
        if ($bookingId === null) {
            return $this->gagal('BOOKING_BELUM_SINKRON',
                'SPR '.$spr.' belum tersinkron ke Back Office (customer/unit-nya belum ada di sana).');
        }

        // Nomor akta unik SECARA GLOBAL di ipro_bo.ajb_documents. Tanpa
        // pemeriksaan ini, bentrok nomor muncul sebagai 500 dari MySQL — bukan
        // pesan yang bisa ditindaklanjuti petugas Legal.
        //
        // Baris milik dokumen Legal INI dikecualikan. Tanpa pengecualian itu,
        // memindahkan akta yang sama dari satu SPR ke SPR lain akan ditolak
        // sebagai "nomor bentrok" — bentrok dengan dirinya sendiri di baris lama
        // yang justru akan dilepas beberapa baris di bawah.
        $bentrok = $this->tabel()
            ->where('ajb_number', $nomor)
            ->where('booking_id', '!=', $bookingId)
            ->where(fn ($q) => $q->whereNull('legal_doc_id')->orWhere('legal_doc_id', '!=', $legalDocId))
            ->first();
        if ($bentrok) {
            return $this->gagal('NOMOR_BENTROK',
                'Nomor akta '.$nomor.' sudah dipakai dokumen AJB lain di Back Office.');
        }

        // Satu SPR hanya boleh dipegang satu dokumen Legal. Tanpa ini, dua
        // dokumen akan saling menimpa baris yang sama tanpa ada yang menyadarinya.
        $penghuni = $this->tabel()->where('booking_id', $bookingId)->first();
        if ($penghuni
            && ($penghuni->source ?? null) === self::SUMBER_LEGAL
            && ($penghuni->legal_doc_id ?? null) !== null
            && $penghuni->legal_doc_id !== $legalDocId) {
            return $this->gagal('SPR_SUDAH_DITAUTKAN',
                'No. SPR '.$spr.' sudah ditautkan ke dokumen AJB lain di modul Legal.');
        }

        $nilai = [
            'tenant_id'       => $this->tenantId(),
            'booking_id'      => $bookingId,
            'ajb_number'      => $nomor,
            'signed_at'       => $tgl,
            'notary_name'     => $notaris !== '' ? $notaris : null,
            'amount'          => $nominal,
            'status'          => 'active',
            'source'          => self::SUMBER_LEGAL,
            'legal_doc_id'    => $legalDocId,
            'legal_synced_at' => now(),
            'updated_at'      => now(),
        ];

        $ditimpa = false;

        $this->bo()->transaction(function () use ($legalDocId, $bookingId, $nilai, &$ditimpa) {
            // Dokumen Legal yang berpindah SPR: baris lamanya dilepas dulu, kalau
            // tidak akta yang sama akan muncul di DUA baris SPR di Detail AJB.
            $this->tabel()->where('legal_doc_id', $legalDocId)
                ->where('booking_id', '!=', $bookingId)->delete();

            $ada = $this->tabel()->where('booking_id', $bookingId)->first();
            if ($ada) {
                // Baris yang sudah ada — termasuk hasil "Input Massal AJB" di BO —
                // digantikan datanya oleh akta asli yang diarsipkan Legal.
                $ditimpa = ($ada->source ?? 'bo') !== self::SUMBER_LEGAL;
                $this->tabel()->where('id', $ada->id)->update($nilai);
            } else {
                $this->tabel()->insert($nilai + ['created_at' => now()]);
            }
        });

        return [
            'ok'         => true,
            'pesan'      => 'Akta AJB tertaut ke '.$kontrak->no_kontrak.'.',
            'booking_id' => $bookingId,
            'ditimpa'    => $ditimpa,
            'spr'        => $kontrak->no_kontrak,
            'customer'   => $kontrak->customer?->full_name,
            'unit'       => $kontrak->unit?->kode_unit,
        ];
    }

    // ── 3. Lepas tautan (dokumen Legal dihapus / tipenya diubah) ─────────────

    /** @return array<string,mixed> */
    public function lepas(string $legalDocId): array
    {
        $legalDocId = trim($legalDocId);
        if ($legalDocId === '') {
            return $this->gagal('DATA_KURANG', 'legal_doc_id wajib diisi.');
        }

        // Hanya baris milik Legal sendiri. Dokumen AJB yang diterbitkan di BO
        // lewat "Input Massal" tidak boleh ikut terhapus dari modul lain.
        $dihapus = $this->tabel()
            ->where('legal_doc_id', $legalDocId)
            ->where('source', self::SUMBER_LEGAL)
            ->delete();

        return ['ok' => true, 'pesan' => 'Tautan dilepas ('.$dihapus.' baris).', 'dihapus' => $dihapus];
    }

    // ── Pembantu ────────────────────────────────────────────────────────────

    private function tabel(): \Illuminate\Database\Query\Builder
    {
        return $this->bo()->table('ajb_documents');
    }

    private function bookingId(string $spr): ?int
    {
        $id = $this->bo()->table('bookings')
            ->where('tenant_id', $this->tenantId())->where('booking_number', $spr)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed> */
    private function gagal(string $kode, string $pesan): array
    {
        return ['ok' => false, 'kode' => $kode, 'pesan' => $pesan];
    }
}
