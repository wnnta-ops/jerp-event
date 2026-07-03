<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sinkronisasi master FO (J-ERP) → BO ipro-fo (ipro_bo), ditulis LANGSUNG ke DB
 * (1 MySQL). Field PII (NIK/NPWP) dienkripsi memakai APP_KEY ipro-fo agar cocok
 * dengan cast EncryptedString (Crypt::encryptString, AES-256-CBC) di sisi ipro-fo.
 *
 * Pemetaan id antar-app via kolom unik:
 *  - customers: by (tenant_id, code)   — code = fo_customers.code (CUST-xxxx)
 *  - units:     by (project_id, unit_code) — unit_code = fo_units.kode_unit
 * id FO disimpan di metadata/attributes JSON untuk referensi balik.
 */
class IproMasterSync
{
    private Encrypter $enc;
    private string $conn;
    private int $tenantId;
    private int $projectId;
    private ?int $userId = null;

    /** @var array<string,int|null> memo hasil lookup id (cluster/type/scheme) per run */
    private array $memo = [];

    public function __construct()
    {
        $key = (string) config('ipro.app_key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        // Cipher harus sama dgn ipro-fo (default AES-256-CBC; override via config bila ipro-fo ganti).
        $this->enc = new Encrypter($key, (string) config('ipro.cipher', 'AES-256-CBC'));
        $this->conn = (string) config('ipro.connection', 'ipro_bo');
        $this->tenantId = (int) config('ipro.tenant_id', 1);
        $this->projectId = (int) config('ipro.project_id', 1);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->conn);
    }

    /** User default ipro_bo (created_by/received_by). Lazy — jangan query saat boot singleton. */
    private function userId(): int
    {
        return $this->userId ??= (int) ($this->db()->table('users')->where('email', 'admin@mup.local')->value('id')
            ?? $this->db()->table('users')->min('id') ?? 1);
    }

    /**
     * Auto-push Bilyet Giro FO → BO "Cek Giro Masuk" (bs_giros) status Di Tangan, BELUM
     * jurnal (receive_journal_id null) → menunggu "Posting GL" di Penerimaan Cek Giro.
     * Idempotent by source_ref. Dipanggil dari event created FO — pemanggil wajib try/catch.
     */
    public function pushGiro(object $bg): void
    {
        $ref = 'FO-BG-'.$bg->id;
        if ($this->db()->table('bs_giros')->where('source_ref', $ref)->exists()) {
            return; // sudah dikirim sebelumnya
        }
        // Akun BO: Piutang Giro (10102010102) & Piutang Usaha (10102010101).
        $giroAcc = $this->db()->table('bs_accounts')->where('code', '10102010102')->value('id');
        $counterAcc = $this->db()->table('bs_accounts')->where('code', '10102010101')->value('id');
        if (! $giroAcc || ! $counterAcc) {
            return; // akun BO belum tersedia
        }
        $drawer = '-';
        if ($bg->customer_id) {
            $drawer = (string) (\App\Modules\FO\MarketingSales\Models\Customer::whereKey($bg->customer_id)->value('full_name') ?: '-');
        }
        $tgl = $bg->tgl_bg ? \Illuminate\Support\Carbon::parse((string) $bg->tgl_bg)->toDateString() : now()->toDateString();
        $jt = $bg->tgl_jatuh_tempo ? \Illuminate\Support\Carbon::parse((string) $bg->tgl_jatuh_tempo)->toDateString() : $tgl;

        $this->db()->table('bs_giros')->insert([
            'tenant_id'          => $this->tenantId,
            'giro_number'        => (string) $bg->no_bg,
            'receive_date'       => $tgl,
            'due_date'           => $jt,
            'bank_name'          => (string) ($bg->bank_nama ?: '-'),
            'cheque_number'      => (string) $bg->no_bg,
            'drawer'             => $drawer,
            'amount'             => round((float) $bg->amount, 2),
            'giro_account_id'    => $giroAcc,
            'counter_account_id' => $counterAcc,
            'status'             => 'in_hand',
            'source'             => 'fo',
            'source_ref'         => $ref,
            'receive_journal_id' => null,
            'created_by'         => $this->userId(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** Upsert satu customer FO → ipro_bo.customers. Return id ipro_bo. */
    public function syncCustomer(object $c): int
    {
        $now = Carbon::now();

        $data = [
            'tenant_id'           => $this->tenantId,
            'project_id'          => $this->projectId,
            'full_name'           => $c->full_name ?? '-',
            'nationality'         => 'WNI',
            'id_type'             => 'KTP',
            'id_number'           => $this->enc->encryptString((string) ($c->nik ?? '')),
            'npwp'                => ! empty($c->npwp) ? $this->enc->encryptString((string) $c->npwp) : null,
            'address_line'        => $c->address ?? null,
            'city'                => $c->city ?? null,
            'province'            => $c->province ?? null,
            'postal_code'         => $c->postal_code ?? null,
            'country'             => 'ID',
            'dob'                 => $c->birth_date ?? null,
            'birth_place'         => $c->birth_place ?? null,
            'phone'               => $c->phone ?? ($c->mobile ?? null),
            'email'               => $c->email ?? null,
            'occupation'          => $c->occupation ?? null,
            'employer_name'       => $c->company_name ?? null,
            'monthly_income'      => $c->income_monthly ?? null,
            'status'              => (int) ($c->is_active ?? 1) === 1 ? 'active' : 'inactive',
            'id_lifetime'         => 0,
            'npwp_same_as_ktp'    => 1,
            'mailing_same_as_ktp' => 1,
            'metadata'            => json_encode(['jerp_customer_id' => $c->id, 'jerp_code' => $c->code]),
            'updated_at'          => $now,
        ];

        $existing = $this->db()->table('customers')
            ->where('tenant_id', $this->tenantId)->where('code', $c->code)->first();

        if ($existing) {
            $this->db()->table('customers')->where('id', $existing->id)->update($data);

            return (int) $existing->id;
        }

        $data['code'] = $c->code;
        $data['created_at'] = $now;

        return (int) $this->db()->table('customers')->insertGetId($data);
    }

    /** Upsert satu unit FO → ipro_bo.units. Return id ipro_bo. */
    public function syncUnit(object $u): int
    {
        $now = Carbon::now();

        // Nilai harus cocok enum UnitStatus ipro-fo: available/hold/booked/sold/cancelled/maintenance.
        $statusMap = [
            'TERSEDIA' => 'available', 'BOOKING' => 'booked', 'HOLD' => 'hold',
            'TERJUAL' => 'sold', 'LUNAS' => 'sold', 'BATAL' => 'cancelled',
        ];

        $code = $u->kode_unit ?: trim(($u->blok ?? '') . '-' . ($u->no_unit ?? ''), '-');

        $data = [
            'tenant_id'     => $this->tenantId,
            'project_id'    => $this->projectId,
            'cluster_id'    => $this->iproClusterId($u),     // dari FO lokasi (fo_lokasi.nama)
            'unit_type_id'  => $this->iproUnitTypeId($u),    // dari fo_unit_types (name + LT/LB)
            'base_price'    => $u->harga_jual ?? 0,
            'current_price' => $u->harga_jual ?? 0,
            'status'        => $statusMap[$u->status ?? 'TERSEDIA'] ?? 'available',
            'address'       => $u->alamat ?? null,
            'attributes'    => json_encode([
                'jerp_unit_id'  => $u->id,
                'blok'          => $u->blok ?? null,
                'no_unit'       => $u->no_unit ?? null,
                'luas_tanah'    => $u->luas_tanah ?? null,
                'luas_bangunan' => $u->luas_bangunan ?? null,
            ]),
            'updated_at'    => $now,
        ];

        $existing = $this->db()->table('units')
            ->where('project_id', $this->projectId)->where('unit_code', $code)->first();

        if ($existing) {
            $this->db()->table('units')->where('id', $existing->id)->update($data);

            return (int) $existing->id;
        }

        $data['unit_code'] = $code;
        $data['created_at'] = $now;

        return (int) $this->db()->table('units')->insertGetId($data);
    }

    /** Cluster ipro_bo dari FO lokasi (fo_lokasi.nama). Upsert by (project_id, code). */
    private function iproClusterId(object $u): ?int
    {
        $lokasiId = $u->lokasi_id ?? null;
        if (! $lokasiId) {
            return null;
        }
        $memoKey = 'cluster:' . $lokasiId;
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }
        $nama = DB::table('fo_lokasi')->where('id', $lokasiId)->value('nama') ?: ('Cluster ' . $lokasiId);
        $code = 'FOL-' . $lokasiId;
        $now = Carbon::now();

        $existing = $this->db()->table('clusters')
            ->where('project_id', $this->projectId)->where('code', $code)->value('id');
        if ($existing) {
            return $this->memo[$memoKey] = (int) $existing;
        }

        return $this->memo[$memoKey] = (int) $this->db()->table('clusters')->insertGetId([
            'tenant_id' => $this->tenantId, 'project_id' => $this->projectId,
            'code' => $code, 'name' => $nama, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Unit type ipro_bo dari fo_unit_types (name + LT/LB). Upsert by (project_id, code). */
    private function iproUnitTypeId(object $u): ?int
    {
        $typeId = $u->unit_type_id ?? null;
        if (! $typeId) {
            return null;
        }
        $memoKey = 'type:' . $typeId;
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }
        $t = DB::table('fo_unit_types')->where('id', $typeId)->first();
        if (! $t) {
            return $this->memo[$memoKey] = null;
        }
        $code = 'FOT-' . $typeId;
        $now = Carbon::now();

        $data = [
            'tenant_id'  => $this->tenantId, 'project_id' => $this->projectId,
            'code'       => $code, 'name' => $t->name ?? $code,
            'lt'         => $t->luas_tanah ?? null, 'lb' => $t->luas_bangunan ?? null,
            'bedrooms'   => $t->jumlah_kamar ?? null, 'bathrooms' => $t->jumlah_kmandi ?? null,
            'updated_at' => $now,
        ];

        $existing = $this->db()->table('unit_types')
            ->where('project_id', $this->projectId)->where('code', $code)->value('id');
        if ($existing) {
            $this->db()->table('unit_types')->where('id', $existing)->update($data);

            return $this->memo[$memoKey] = (int) $existing;
        }
        $data['created_at'] = $now;

        return $this->memo[$memoKey] = (int) $this->db()->table('unit_types')->insertGetId($data);
    }

    /** Cari id customer ipro_bo dari id customer FO (via code). */
    private function iproCustomerIdByFo(int $foCustomerId): ?int
    {
        $code = DB::table('fo_customers')->where('id', $foCustomerId)->value('code');
        if (! $code) {
            return null;
        }

        return $this->db()->table('customers')
            ->where('tenant_id', $this->tenantId)->where('code', $code)->value('id');
    }

    /** Cari id unit ipro_bo dari id unit FO (via unit_code). */
    private function iproUnitIdByFo(int $foUnitId): ?int
    {
        $code = DB::table('fo_units')->where('id', $foUnitId)->value('kode_unit');
        if (! $code) {
            return null;
        }

        return $this->db()->table('units')
            ->where('project_id', $this->projectId)->where('unit_code', $code)->value('id');
    }

    /** Upsert SPR (fo_kontrak) → ipro_bo.bookings + payment_schedules. Return id booking. */
    public function syncSpr(object $k): ?int
    {
        $custId = $this->iproCustomerIdByFo((int) $k->customer_id);
        $unitId = $this->iproUnitIdByFo((int) $k->unit_id);
        if (! $custId || ! $unitId) {
            return null; // master belum disinkron
        }

        $typeMap = ['CASH' => 'cash_keras', 'CASH_BERTAHAP' => 'cicilan', 'CICILAN' => 'cicilan', 'KPR' => 'kpr'];
        $statusMap = ['DRAFT' => 'draft', 'BOOKING' => 'confirmed', 'AKTIF' => 'active', 'LUNAS' => 'completed', 'BATAL' => 'cancelled'];
        $now = Carbon::now();

        $base = (float) ($k->harga_jual ?? 0);
        $diskon = (float) ($k->diskon ?? 0);
        $ppn = (float) ($k->ppn ?? 0);
        $total = (float) ($k->total_kontrak ?? ($base - $diskon + $ppn));

        $data = [
            'tenant_id'         => $this->tenantId,
            'project_id'        => $this->projectId,
            'customer_id'       => $custId,
            'unit_id'           => $unitId,
            'payment_scheme_id' => $this->iproPaymentSchemeId($typeMap[$k->cara_bayar ?? 'KPR'] ?? 'kpr'),
            'booking_date'      => $k->tgl_kontrak ?? $now->toDateString(),
            'base_price'        => $base,
            'discount_amount'   => $diskon,
            'ppn_amount'        => $ppn,
            'total_price'       => $total,
            'status'            => $statusMap[$k->status ?? 'DRAFT'] ?? 'draft',
            'booking_source'    => 'fo-jerp',
            'created_by'        => $this->userId(),
            'notes'             => 'SPR ' . ($k->no_kontrak ?? ''),
            'updated_at'        => $now,
        ];

        // Transaksi: booking + payment_schedules atomik (cegah cicilan terhapus tanpa diisi ulang).
        return $this->db()->transaction(function () use ($data, $k, $now) {
            $existing = $this->db()->table('bookings')
                ->where('tenant_id', $this->tenantId)->where('booking_number', $k->no_kontrak)->first();

            if ($existing) {
                $this->db()->table('bookings')->where('id', $existing->id)->update($data);
                $bookingId = (int) $existing->id;
            } else {
                $data['booking_number'] = $k->no_kontrak;
                $data['created_at'] = $now;
                $bookingId = (int) $this->db()->table('bookings')->insertGetId($data);
            }

            $this->syncSchedules($bookingId, (int) $k->id, $k);

            return $bookingId;
        });
    }

    /** id payment_scheme ipro_bo untuk tipe tertentu (cash_keras/cicilan/kpr/...). Memoized. */
    private function iproPaymentSchemeId(string $type): int
    {
        $memoKey = 'scheme:' . $type;
        if (array_key_exists($memoKey, $this->memo) && $this->memo[$memoKey]) {
            return (int) $this->memo[$memoKey];
        }

        $id = $this->db()->table('payment_schemes')
            ->where('tenant_id', $this->tenantId)
            ->where(fn ($q) => $q->where('project_id', $this->projectId)->orWhereNull('project_id'))
            ->where('type', $type)->value('id');

        // Fallback 1: skema apa pun milik tenant. Fallback 2: buat skema minimal utk tipe ini.
        if (! $id) {
            $id = $this->db()->table('payment_schemes')->where('tenant_id', $this->tenantId)->value('id');
        }
        if (! $id) {
            $now = Carbon::now();
            $id = $this->db()->table('payment_schemes')->insertGetId([
                'tenant_id' => $this->tenantId, 'project_id' => $this->projectId,
                'code' => 'FO-' . strtoupper($type), 'name' => 'FO ' . $type, 'type' => $type,
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return (int) ($this->memo[$memoKey] = (int) $id);
    }

    /** Replace payment_schedules dari fo_kontrak_tagihan. */
    private function syncSchedules(int $bookingId, int $foKontrakId, object $k): void
    {
        // PaymentType enum ipro-fo (varchar 5): BF, DP, ANG, ADM. LAIN→ANG.
        $typeMap = ['BF' => 'BF', 'DP' => 'DP', 'ANG' => 'ANG', 'ADM' => 'ADM', 'LAIN' => 'ANG'];
        $now = Carbon::now();
        $fallbackDue = $k->tgl_kontrak ?? $now->toDateString();

        $this->db()->table('payment_schedules')->where('booking_id', $bookingId)->delete();

        $rows = [];
        $i = 0;
        foreach (DB::table('fo_kontrak_tagihan')->where('kontrak_id', $foKontrakId)->orderBy('urutan')->get() as $t) {
            $i++;
            $nilai = (float) ($t->nilai ?? 0);
            $bayar = (float) ($t->pelunasan ?? 0);
            $rows[] = [
                'booking_id'     => $bookingId,
                'installment_no' => $t->urutan ?: $i,
                'sequence'       => $i,
                'payment_type'   => $typeMap[$t->tipe ?? 'ANG'] ?? 'ANG',
                'label'          => $t->nama_tagihan ?? null,
                'due_date'       => $t->jatuh_tempo ?? $fallbackDue,
                'amount'         => $nilai,
                'paid_amount'    => $bayar,
                'status'         => ($nilai > 0 && $bayar >= $nilai) ? 'paid' : 'outstanding',
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if ($rows) {
            $this->db()->table('payment_schedules')->insert($rows);
        }
    }

    /** Upsert TTS (fo_tts) → ipro_bo.tts_receipts. Return id receipt. */
    public function syncTts(object $t): ?int
    {
        $custId = $this->iproCustomerIdByFo((int) $t->customer_id);
        if (! $custId) {
            return null;
        }

        $bookingId = null;
        if (! empty($t->kontrak_id)) {
            $noK = DB::table('fo_kontrak')->where('id', $t->kontrak_id)->value('no_kontrak');
            if ($noK) {
                $bookingId = $this->db()->table('bookings')
                    ->where('tenant_id', $this->tenantId)->where('booking_number', $noK)->value('id');
            }
        }

        $pmMap = ['CASH' => 'TN', 'TRANSFER' => 'TN', 'GIRO' => 'KK', 'CHEQUE' => 'KK', 'VA' => 'TN', 'XENDIT' => 'TN'];
        $statusMap = ['DRAFT' => 'pending', 'KONFIRMASI' => 'pending', 'JADI_BKM' => 'posted', 'JADI_BGM' => 'posted', 'BATAL' => 'void'];
        $now = Carbon::now();

        $data = [
            'tenant_id'      => $this->tenantId,
            'customer_id'    => $custId,
            'booking_id'     => $bookingId,
            'amount'         => $t->amount ?? 0,
            'payment_method' => $pmMap[$t->cara_bayar ?? 'TRANSFER'] ?? 'TN',
            'received_date'  => $t->tgl_tts ?? $now->toDateString(),
            'reference_no'   => $t->no_referensi ?? null,
            'status'         => $statusMap[$t->status ?? 'DRAFT'] ?? 'pending',
            'received_by'    => $this->userId(),
            'notes'          => $t->keterangan ?? null,
            'updated_at'     => $now,
        ];

        // Bila TTS ini berasal dari Transfer Anonim (link via NUP: tts_id+transfer_anonim_id),
        // MENYATU dgn placeholder tanpa-unit (key "TA-{id}") → satu baris yang bertransisi
        // tanpa-unit → ada-unit (tak duplikat, & hilang dari tab "Tanpa Unit").
        $anonId = DB::table('fo_nup_registrations')->where('tts_id', $t->id)->value('transfer_anonim_id');
        $key = $anonId ? 'TA-'.$anonId : $t->no_tts;

        $existing = $this->db()->table('tts_receipts')
            ->where('tenant_id', $this->tenantId)->where('tts_number', $key)->first();

        if ($existing) {
            $this->db()->table('tts_receipts')->where('id', $existing->id)->update($data);
            // Baru dapat unit + sudah dijurnal (di BO "Tanpa Unit") → perbarui jurnal dgn unit.
            if ($anonId && ! $existing->booking_id && $bookingId && $existing->journal_id) {
                $this->reclassifyJournalUnit((int) $existing->journal_id, $this->unitCodeForTransfer((int) $anonId));
            }

            return (int) $existing->id;
        }

        $data['tts_number'] = $key;
        $data['created_at'] = $now;

        return (int) $this->db()->table('tts_receipts')->insertGetId($data);
    }

    /**
     * Push 1 Kwitansi (BKM pelunasan DP/Angsuran/TDP) → ipro_bo.tts_receipts sebagai
     * penerimaan MENUNGGU APPROVAL (BO "Approval UTJ & DP"): status 'posted' + journal_id
     * null. BO memilih akun Debit/Kredit lalu menyetujui → jurnal GL terbentuk di sana.
     * Idempoten via tts_number = no_bkm; tak menimpa bila sudah dijurnal (sudah di-approve).
     */
    public function pushBkmToApproval(object $bkm): ?int
    {
        $now = Carbon::now();
        $bookingId = null;
        if (! empty($bkm->kontrak_id)) {
            $noK = DB::table('fo_kontrak')->where('id', $bkm->kontrak_id)->value('no_kontrak');
            if ($noK) {
                $bookingId = $this->db()->table('bookings')
                    ->where('tenant_id', $this->tenantId)->where('booking_number', $noK)->value('id');
            }
        }

        $data = [
            'tenant_id'      => $this->tenantId,
            'customer_id'    => $this->iproCustomerIdByFo((int) $bkm->customer_id),
            'booking_id'     => $bookingId,
            'amount'         => $bkm->amount ?? 0,
            'payment_method' => 'TN',
            'received_date'  => $bkm->tgl_bkm ? Carbon::parse((string) $bkm->tgl_bkm)->toDateString() : $now->toDateString(),
            'reference_no'   => $bkm->no_referensi ?? null,
            'status'         => 'posted', // posted + journal_id null = "Menunggu Approval" di BO
            'received_by'    => $this->userId(),
            'updated_at'     => $now,
        ];

        $existing = $this->db()->table('tts_receipts')
            ->where('tenant_id', $this->tenantId)->where('tts_number', (string) $bkm->no_bkm)->first();
        if ($existing) {
            if (! $existing->journal_id) {
                $this->db()->table('tts_receipts')->where('id', $existing->id)->update($data);
            }

            return (int) $existing->id;
        }

        $data['tts_number'] = (string) $bkm->no_bkm;
        $data['created_at'] = $now;

        return (int) $this->db()->table('tts_receipts')->insertGetId($data);
    }

    /**
     * Transfer Anonim FO (fo_transfer_anonim) → ipro_bo.tts_receipts sbg penerimaan
     * TANPA UNIT (booking_id null; customer null bila belum teridentifikasi). Muncul di
     * BO "Approval UTJ & DP → Tanpa Unit"; approver posting GL di sana. Saat transfer
     * ditarik jadi unit/SPR, baris ini MENYATU dgn TTS riil (syncTts key "TA-{id}") &
     * jurnal diperbarui dgn unit. Return id receipt.
     */
    public function syncTransferAnonim(object $t): ?int
    {
        $custId = ! empty($t->customer_id) ? $this->iproCustomerIdByFo((int) $t->customer_id) : null;
        $bookingId = $this->iproBookingIdForTransfer((int) $t->id);
        $now = Carbon::now();
        $key = 'TA-'.$t->id;

        $existing = $this->db()->table('tts_receipts')
            ->where('tenant_id', $this->tenantId)->where('tts_number', $key)->first();

        $data = [
            'tenant_id'      => $this->tenantId,
            'customer_id'    => $custId,
            'booking_id'     => $bookingId,
            'amount'         => $t->amount ?? 0,
            'payment_method' => 'TN',
            'received_date'  => $t->tgl_transfer ?? $now->toDateString(),
            'reference_no'   => $t->keterangan_bank ?? null,
            'status'         => 'posted',   // tampil di Approval UTJ&DP (Tanpa Unit)
            'received_by'    => $this->userId(),
            'notes'          => 'Transfer Anonim'.($t->keterangan ? ' — '.$t->keterangan : ''),
            'updated_at'     => $now,
        ];

        if ($existing) {
            $this->db()->table('tts_receipts')->where('id', $existing->id)->update($data);
            if (! $existing->booking_id && $bookingId && $existing->journal_id) {
                $this->reclassifyJournalUnit((int) $existing->journal_id, $this->unitCodeForTransfer((int) $t->id));
            }

            return (int) $existing->id;
        }

        $data['tts_number'] = $key;
        $data['created_at'] = $now;

        return (int) $this->db()->table('tts_receipts')->insertGetId($data);
    }

    /** Booking ipro_bo dari Transfer Anonim (via NUP → reservasi → kontrak). */
    private function iproBookingIdForTransfer(int $transferId): ?int
    {
        $resvId = DB::table('fo_nup_registrations')->where('transfer_anonim_id', $transferId)->value('reservasi_id');
        if (! $resvId) {
            return null;
        }
        $noK = DB::table('fo_kontrak')->where('reservasi_id', $resvId)->value('no_kontrak');
        if (! $noK) {
            return null;
        }

        return $this->db()->table('bookings')
            ->where('tenant_id', $this->tenantId)->where('booking_number', $noK)->value('id');
    }

    /** Kode unit (FO) dari Transfer Anonim — untuk deskripsi jurnal. */
    private function unitCodeForTransfer(int $transferId): ?string
    {
        $resvId = DB::table('fo_nup_registrations')->where('transfer_anonim_id', $transferId)->value('reservasi_id');
        if (! $resvId) {
            return null;
        }
        $unitId = DB::table('fo_kontrak')->where('reservasi_id', $resvId)->value('unit_id');

        return $unitId ? DB::table('fo_units')->where('id', $unitId)->value('kode_unit') : null;
    }

    /** Perbarui deskripsi jurnal GL dgn unit saat penerimaan tanpa-unit akhirnya dapat unit. */
    private function reclassifyJournalUnit(int $journalId, ?string $unitCode): void
    {
        if (! $unitCode) {
            return;
        }
        $desc = (string) $this->db()->table('bs_journals')->where('id', $journalId)->value('description');
        if ($desc !== '' && ! str_contains($desc, $unitCode)) {
            $this->db()->table('bs_journals')->where('id', $journalId)
                ->update(['description' => trim($desc.' · Unit '.$unitCode), 'updated_at' => Carbon::now()]);
        }
    }

    /**
     * Handoff KPR: FO "pembuatan SP" (fo_kpr_pengajuan) → ipro_bo.kpr_initiations.
     * ipro-fo melanjutkan pemberkasan → SP3K → akad → pencairan → retensi.
     */
    public function syncKpr(object $p): ?int
    {
        $kontrak = DB::table('fo_kontrak')->where('id', $p->kontrak_id)->first();
        if (! $kontrak) {
            return null;
        }
        $custId = $this->iproCustomerIdByFo((int) $kontrak->customer_id);
        if (! $custId) {
            return null;
        }

        $bookingId = $this->db()->table('bookings')
            ->where('tenant_id', $this->tenantId)->where('booking_number', $kontrak->no_kontrak)->value('id');

        $statusMap = ['DRAFT' => 'in_progress', 'PROSES' => 'in_progress', 'SELESAI' => 'completed', 'BATAL' => 'cancelled'];
        $plafon = max(0.0, (float) ($kontrak->total_kontrak ?? 0) - (float) ($kontrak->dp_amount ?? 0));
        $now = Carbon::now();

        $data = [
            'tenant_id'        => $this->tenantId,
            'customer_id'      => $custId,
            'booking_id'       => $bookingId,
            'plafon_requested' => $plafon,
            'current_stage'    => 2, // SPRS — FO sudah buat SP/SPRS, ipro lanjut pemberkasan
            'status'           => $statusMap[$p->status ?? 'DRAFT'] ?? 'in_progress',
            'stages'           => json_encode(['jerp_pengajuan_id' => $p->id, 'jerp_no' => $p->no_pengajuan]),
            'notes'            => $p->keterangan ?? null,
            'initiated_by'     => $this->userId(),
            'updated_at'       => $now,
        ];

        $existing = $this->db()->table('kpr_initiations')
            ->where('tenant_id', $this->tenantId)->where('initiation_number', $p->no_pengajuan)->first();

        if ($existing) {
            $this->db()->table('kpr_initiations')->where('id', $existing->id)->update($data);

            return (int) $existing->id;
        }

        $data['initiation_number'] = $p->no_pengajuan;
        $data['created_at'] = $now;

        return (int) $this->db()->table('kpr_initiations')->insertGetId($data);
    }
}
