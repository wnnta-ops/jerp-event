<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    /**
     * @var array<string,int|null> memo hasil lookup id (cluster/type/scheme).
     *
     * CATATAN: servis ini di-singleton (BoServiceProvider) dan worker Horizon berumur panjang,
     * jadi memo ini bertahan jauh melampaui satu job. Karena itu hanya HASIL BERHASIL yang
     * boleh disimpan di sini — memo yang berisi kegagalan sesaat akan dipakai ulang berjam-jam.
     */
    private array $memo = [];

    /**
     * True bila unit terakhir punya lokasi_id tetapi clusternya GAGAL dipecahkan — dibedakan
     * dari unit yang memang tidak punya lokasi. Tanpa pembedaan ini, satu kegagalan sesaat
     * akan menimpa cluster_id yang sudah benar di BO dengan NULL, sambil melapor sukses.
     */
    private bool $clusterTakTerpecahkan = false;

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
     * Provisioning pengguna FO (jerp.users) → ipro_bo.users, idempoten BY EMAIL agar
     * SSO FO→BO cocok (SsoController@consume mencari user by email, tak auto-buat).
     *  - Baru: buat akun (password acak — login BO hanya via SSO) + grup akses default.
     *  - Sudah ada: perbarui nama & status aktif saja; JANGAN sentuh password/role
     *    (dikelola di app BO / halaman "Pengguna BO"). Return id user ipro_bo.
     */
    public function syncUser(object $u): ?int
    {
        $email = trim((string) ($u->email ?? ''));
        if ($email === '') {
            return null; // tanpa email tak bisa dipetakan ke SSO BO
        }

        $now = Carbon::now();
        $name = (string) ($u->name ?? ($u->full_name ?? $email));
        $active = ((int) ($u->is_active ?? 1)) === 1 ? 1 : 0;

        $existing = $this->db()->table('users')->where('email', $email)->first();

        if ($existing) {
            $this->db()->table('users')->where('id', $existing->id)->update([
                'name' => $name,
                'is_active' => $active,
                'updated_at' => $now,
            ]);
            $this->syncBoRolesFromFo((int) $existing->id, $u);
            $this->syncKaryawan($u, (int) $existing->id);

            return (int) $existing->id;
        }

        $id = (int) $this->db()->table('users')->insertGetId([
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'email' => $email,
            'username' => $this->uniqueUsername($email),
            'password' => Hash::make(Str::random(40)), // acak; user login BO via SSO FO
            'is_active' => $active,
            'is_blocked' => 0,
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->syncBoRolesFromFo($id, $u);
        $this->syncKaryawan($u, $id);

        return $id;
    }

    /**
     * Tautkan akun BO ke data karyawan, dan buatkan barisnya bila belum ada.
     *
     * users.employee_nip adalah satu-satunya pengikat akun ke karyawan di BO — SIP dan
     * Bukti Permohonan Pembayaran menelusurinya untuk tahu unit kerja si pengaju. Sebelum
     * ini kolomnya tidak pernah punya layar untuk diisi, sehingga kosong di semua akun dan
     * kedua dokumen tercetak "Tanpa Departemen".
     *
     * Barisnya hanya DIBUAT, tidak pernah diperbarui. Setelah karyawannya ada, HR memegang
     * datanya dan Accounting memegang cost center-nya — modulnya memang berbeda. Menimpa
     * dari sini berarti koreksi mereka hilang diam-diam setiap kali akunnya disimpan, tanpa
     * gejala apa pun sampai ada yang membaca dokumen pembayaran.
     *
     * NIK di bs_employees TIDAK dienkripsi (beda dari NIK pelanggan di bs_customers yang
     * memakai cast EncryptedString), jadi dicocokkan apa adanya.
     */
    private function syncKaryawan(object $u, int $boUserId): void
    {
        $nik = trim((string) ($u->nik ?? ''));
        if ($nik === '') {
            return; // akun tanpa NIK: tidak ada yang bisa ditautkan
        }

        $this->db()->table('users')->where('id', $boUserId)->update(['employee_nip' => $nik]);

        // Termasuk yang sudah dihapus (soft delete): NIK yang sama muncul dua kali di daftar
        // karyawan lebih membingungkan HR daripada satu baris nonaktif yang perlu diaktifkan.
        if ($this->db()->table('bs_employees')->where('nik', $nik)->exists()) {
            return;
        }

        $now = Carbon::now();
        $baris = [
            'tenant_id' => $this->tenantId,
            'nik' => $nik,
            'name' => (string) ($u->full_name ?? ($u->name ?? $nik)),
            'cost_center_id' => ! empty($u->bo_cost_center_id) ? (int) $u->bo_cost_center_id : null,
            'bank_name' => $this->namaBank($u->bank_id ?? null),
            'bank_account' => ($v = trim((string) ($u->rekening_number ?? ''))) !== '' ? $v : null,
            // NOT NULL di bs_employees. Nolnya disengaja: gaji itu urusan HR, dan menebaknya
            // di sini berarti angka karangan masuk ke daftar penggajian.
            'base_salary' => 0,
            'allowance' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Kolom npwp baru ada sejak migrasi 2026_09_15_000020 di sisi BO.
        if (Schema::connection($this->conn)->hasColumn('bs_employees', 'npwp')) {
            $baris['npwp'] = ($v = trim((string) ($u->npwp ?? ''))) !== '' ? $v : null;
        }

        $this->db()->table('bs_employees')->insert($baris);
    }

    /** Nama bank dari master FO (jerp.banks) — bs_employees menyimpan namanya, bukan id. */
    private function namaBank(mixed $bankId): ?string
    {
        if (empty($bankId)) {
            return null;
        }

        $nama = DB::table('banks')->where('id', (int) $bankId)->value('name');

        return $nama !== null ? (string) $nama : null;
    }

    /** Username unik dari local-part email (nullable). Tambah sufiks bila bentrok. */
    private function uniqueUsername(string $email): ?string
    {
        $base = Str::of($email)->before('@')->slug('_')->limit(50, '')->value();
        if ($base === '') {
            return null;
        }
        $candidate = $base;
        for ($i = 1; $this->db()->table('users')->where('username', $candidate)->exists(); $i++) {
            if ($i > 50) {
                return null; // menyerah — biarkan null (kolom nullable-unik)
            }
            $candidate = $base.'_'.$i;
        }

        return $candidate;
    }

    /** Nama role BO hasil pemetaan dari role FO user (config ipro.role_map). @return array<int,string> */
    private function mappedBoRoleNames(object $u): array
    {
        $map = (array) config('ipro.role_map', []);
        $foRoles = method_exists($u, 'getRoleNames') ? $u->getRoleNames()->all() : [];

        $names = [];
        foreach ($foRoles as $r) {
            if (! empty($map[$r])) {
                $names[] = $map[$r];
            }
        }
        $names = array_values(array_unique($names));

        if (! $names) {
            $default = (string) config('ipro.default_bo_role', 'Viewer');
            if ($default !== '') {
                $names = [$default];
            }
        }

        return $names;
    }

    /**
     * Selaraskan grup akses BO user agar MENGIKUTI role FO (config ipro.role_map).
     * Otoritatif atas role yang "dikelola" pemetaan (target map + default); role
     * BO-only (mis. Agent/SalesDirector) yang di-set manual DIPERTAHANKAN.
     */
    private function syncBoRolesFromFo(int $boUserId, object $u): void
    {
        $map = (array) config('ipro.role_map', []);
        $default = (string) config('ipro.default_bo_role', 'Viewer');
        $governedNames = array_values(array_unique(array_merge(
            array_values($map),
            $default !== '' ? [$default] : []
        )));

        $desiredNames = $this->mappedBoRoleNames($u);

        $governedIds = $governedNames
            ? $this->db()->table('roles')->whereIn('name', $governedNames)->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [];
        $desiredIds = $desiredNames
            ? $this->db()->table('roles')->whereIn('name', $desiredNames)->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [];

        $current = $this->db()->table('model_has_roles')
            ->where('model_id', $boUserId)->where('model_type', 'user')
            ->pluck('role_id')->map(fn ($v) => (int) $v)->all();

        // Pertahankan role BO-only (bukan dikelola FO), buang governed yg tak diinginkan, tambah desired.
        $keep = array_values(array_diff($current, $governedIds));
        $final = array_values(array_unique(array_merge($keep, $desiredIds)));

        $this->db()->table('model_has_roles')
            ->where('model_id', $boUserId)->where('model_type', 'user')->delete();
        if ($final) {
            $rows = array_map(fn ($rid) => [
                'role_id' => $rid, 'model_id' => $boUserId, 'model_type' => 'user',
            ], $final);
            $this->db()->table('model_has_roles')->insert($rows);
        }
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
            $drawer = (string) (Customer::whereKey($bg->customer_id)->value('full_name') ?: '-');
        }
        $tgl = $bg->tgl_bg ? Carbon::parse((string) $bg->tgl_bg)->toDateString() : now()->toDateString();
        $jt = $bg->tgl_jatuh_tempo ? Carbon::parse((string) $bg->tgl_jatuh_tempo)->toDateString() : $tgl;

        $this->db()->table('bs_giros')->insert([
            'tenant_id' => $this->tenantId,
            'giro_number' => (string) $bg->no_bg,
            'receive_date' => $tgl,
            'due_date' => $jt,
            'bank_name' => (string) ($bg->bank_nama ?: '-'),
            'cheque_number' => (string) $bg->no_bg,
            'drawer' => $drawer,
            'amount' => round((float) $bg->amount, 2),
            'giro_account_id' => $giroAcc,
            'counter_account_id' => $counterAcc,
            'status' => 'in_hand',
            'source' => 'fo',
            'source_ref' => $ref,
            'receive_journal_id' => null,
            'created_by' => $this->userId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Upsert satu customer FO → ipro_bo.customers. Return id ipro_bo. */
    public function syncCustomer(object $c): int
    {
        $now = Carbon::now();

        $data = [
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'full_name' => $c->full_name ?? '-',
            'nationality' => 'WNI',
            'id_type' => 'KTP',
            'id_number' => $this->enc->encryptString((string) ($c->nik ?? '')),
            'npwp' => ! empty($c->npwp) ? $this->enc->encryptString((string) $c->npwp) : null,
            'address_line' => $c->address ?? null,
            'city' => $c->city ?? null,
            'province' => $c->province ?? null,
            'postal_code' => $c->postal_code ?? null,
            'country' => 'ID',
            'dob' => $c->birth_date ?? null,
            'birth_place' => $c->birth_place ?? null,
            'phone' => $c->phone ?? ($c->mobile ?? null),
            'email' => $c->email ?? null,
            'occupation' => $c->occupation ?? null,
            'employer_name' => $c->company_name ?? null,
            'monthly_income' => $c->income_monthly ?? null,
            'status' => (int) ($c->is_active ?? 1) === 1 ? 'active' : 'inactive',
            'id_lifetime' => 0,
            'npwp_same_as_ktp' => 1,
            'mailing_same_as_ktp' => 1,
            'metadata' => json_encode(['jerp_customer_id' => $c->id, 'jerp_code' => $c->code]),
            'updated_at' => $now,
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

        $code = $u->kode_unit ?: trim(($u->blok ?? '').'-'.($u->no_unit ?? ''), '-');

        $data = [
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'cluster_id' => $this->iproClusterId($u),     // cerminan master Lokasi FO (Setup Unit)
            'unit_type_id' => $this->iproUnitTypeId($u),    // dari fo_unit_types (name + LT/LB)
            'base_price' => $u->harga_jual ?? 0,
            'current_price' => $u->harga_jual ?? 0,
            'status' => $statusMap[$u->status ?? 'TERSEDIA'] ?? 'available',
            'address' => $u->alamat ?? null,
            'attributes' => json_encode([
                'jerp_unit_id' => $u->id,
                'blok' => $u->blok ?? null,
                'no_unit' => $u->no_unit ?? null,
                'luas_tanah' => $u->luas_tanah ?? null,
                'luas_bangunan' => $u->luas_bangunan ?? null,
            ]),
            'updated_at' => $now,
        ];

        $existing = $this->db()->table('units')
            ->where('project_id', $this->projectId)->where('unit_code', $code)->first();

        if ($existing) {
            // Cluster yang GAGAL dipecahkan tidak boleh menimpa cluster_id yang sudah benar.
            // Membiarkannya berarti satu kedipan koneksi ke tabel clusters cukup untuk
            // mengosongkan cluster setiap unit yang tersentuh sesudahnya — dan job tetap
            // melapor sukses, jadi tidak ada retry maupun baris fo_sync_failures yang
            // memberitahu. Unit yang memang tanpa lokasi tetap dikosongkan seperti biasa.
            if ($this->clusterTakTerpecahkan) {
                unset($data['cluster_id']);
            }

            $this->db()->table('units')->where('id', $existing->id)->update($data);

            return (int) $existing->id;
        }

        $data['unit_code'] = $code;
        $data['created_at'] = $now;

        return (int) $this->db()->table('units')->insertGetId($data);
    }

    /** Apakah BO sudah punya kolom identitas Lokasi di tabel clusters? (di-cache per proses) */
    private ?bool $boPunyaFoLokasiId = null;

    private function boHasFoLokasiId(): bool
    {
        if ($this->boPunyaFoLokasiId !== null) {
            return $this->boPunyaFoLokasiId;
        }

        try {
            $ada = Schema::connection($this->conn)
                ->hasColumn('clusters', 'fo_lokasi_id');
        } catch (\Throwable $e) {
            // SENGAJA tidak di-cache. Servis ini singleton dan worker Horizon hidup berjam-jam
            // (horizon.maxTime = 0), jadi meng-cache "false" hasil koneksi yang sekejap
            // berkedip akan mengunci worker itu di jalur warisan sampai ia mati — dan jalur
            // warisan membuat cluster KEDUA berkode FOL-{id} untuk Lokasi yang clusternya
            // sudah ada, dua-duanya hidup dan bernama sama di dalam satu dropdown.
            report($e);

            return false;
        }

        return $this->boPunyaFoLokasiId = $ada;
    }

    /**
     * Upsert satu master Lokasi FO → ipro_bo.clusters, dan pulangkan id cluster-nya.
     *
     * Inilah satu-satunya penulis tabel clusters. Cluster di BO bukan master tersendiri: ia
     * cerminan Setup Unit → Lokasi di FO, yang memang disebut "cluster" di seluruh kode FO.
     * Karena itu operator memilih cluster di BO dari daftar yang sama persis dengan yang ia
     * lihat di Master Unit.
     *
     * Kuncinya fo_lokasi_id, BUKAN kode. Kode Lokasi boleh diubah operator kapan saja; kalau
     * ia dipakai sebagai kunci, mengganti "JRK3" menjadi "JRK-3" akan melahirkan cluster baru
     * dan seluruh kategori gimmick yang menunjuk yang lama menggantung tanpa pemberitahuan.
     *
     * SELALU memperbarui code & name — bukan sekadar menyisipkan bila belum ada. Versi
     * sebelumnya pulang lebih awal begitu barisnya ketemu, sehingga ganti nama di FO tidak
     * pernah sampai ke BO dan kedua layar perlahan berbeda tanpa ada yang menyadarinya.
     *
     * Pencarian SENGAJA tidak menyaring deleted_at: baris yang sudah ditandai terhapus tetap
     * memakan indeks unik, jadi mengabaikannya hanya akan menghasilkan tabrakan 1062 berulang
     * alih-alih menghidupkan kembali cluster yang Lokasinya dipulihkan di FO.
     *
     * @param  object  $l  baris fo_lokasi (id, kode, nama, deleted_at)
     */
    public function syncLokasi(object $l): int
    {
        $lokasiId = (int) ($l->id ?? 0);
        if ($lokasiId <= 0) {
            throw new \RuntimeException('Lokasi tanpa id tidak bisa disinkronkan ke BO.');
        }

        // Kode & nama wajib ada. Mengarang "Cluster 7" seperti versi sebelumnya membuat BO
        // menampilkan cluster yang tidak pernah ada di FO — persis kekeliruan yang sedang
        // diperbaiki. Lebih baik gagal dan tercatat.
        $kode = trim((string) ($l->kode ?? ''));
        $nama = trim((string) ($l->nama ?? ''));
        if ($kode === '' || $nama === '') {
            throw new \RuntimeException("Lokasi #{$lokasiId} belum punya kode/nama, tidak bisa jadi cluster di BO.");
        }

        $now = Carbon::now();
        $memoKey = 'cluster:'.$lokasiId;
        // clusters.code hanya 32 karakter, sama dengan batas kode Lokasi di FO — dipotong
        // untuk berjaga bila batas FO dilonggarkan kelak.
        $label = [
            'name' => $nama,
            'deleted_at' => $l->deleted_at ?? null,
            'updated_at' => $now,
        ];

        // BO belum dimigrasi (dua repo, dua jadwal rilis): pakai jalur kunci lama supaya
        // sinkron unit tidak ikut mati — tapi tetap dengan pembaruan label.
        if (! $this->boHasFoLokasiId()) {
            return $this->memo[$memoKey] = $this->upsertClusterWarisan($lokasiId, $label, $now);
        }

        for ($percobaan = 0; $percobaan < 3; $percobaan++) {
            // Kode dihitung ULANG tiap putaran: pada dua Lokasi yang kodenya bertukar, putaran
            // pertama menemukan kodenya masih dipegang saudaranya dan putaran berikutnya
            // menemukannya sudah bebas. Tanpa perhitungan ulang, keadaan itu tidak pernah
            // konvergen — kedua Lokasi terkunci pada kode yang salah selamanya.
            $label['code'] = $this->kodeClusterAman($lokasiId, $kode);

            try {
                // 1 · Sudah pernah disinkronkan — perbarui labelnya.
                $ada = $this->db()->table('clusters')
                    ->where('tenant_id', $this->tenantId)->where('fo_lokasi_id', $lokasiId)->value('id');
                if ($ada) {
                    // UPDATE ini WAJIB di dalam try: mengganti kode ke kode yang sedang dipegang
                    // baris lain melempar 1062 juga, bukan hanya INSERT.
                    $this->db()->table('clusters')->where('id', $ada)->update($label);

                    return $this->memo[$memoKey] = (int) $ada;
                }

                // 2 · Baris warisan hasil sinkron lama (code 'FOL-{id}') — diadopsi, bukan
                //     dikembari, supaya kategori gimmick yang menunjuknya tetap sah. Baris
                //     yang sudah ditandai terhapus pun diadopsi: itu memang cluster yang sama.
                $warisan = $this->db()->table('clusters')
                    ->where('tenant_id', $this->tenantId)->where('project_id', $this->projectId)
                    ->where('code', 'FOL-'.$lokasiId)->whereNull('fo_lokasi_id')->value('id');

                // 3 · Baris berkode sama yang belum berpemilik — diadopsi supaya cluster yang
                //     dibuat manual dengan kode benar tidak kembar. DI SINI deleted_at DISARING:
                //     baris yang sengaja dipensiunkan (mis. cluster contoh) tidak boleh hidup
                //     kembali hanya karena sebuah Lokasi baru kebetulan berkode sama — ia akan
                //     membawa serta seluruh unit lama yang masih menunjuknya.
                $warisan ??= $this->db()->table('clusters')
                    ->where('tenant_id', $this->tenantId)->where('project_id', $this->projectId)
                    ->where('code', $label['code'])->whereNull('fo_lokasi_id')
                    ->whereNull('deleted_at')->value('id');

                if ($warisan) {
                    $this->db()->table('clusters')->where('id', $warisan)
                        ->update($label + ['fo_lokasi_id' => $lokasiId]);

                    return $this->memo[$memoKey] = (int) $warisan;
                }

                // 4 · Benar-benar baru.
                return $this->memo[$memoKey] = (int) $this->db()->table('clusters')->insertGetId(
                    $label + [
                        'tenant_id' => $this->tenantId, 'project_id' => $this->projectId,
                        'fo_lokasi_id' => $lokasiId, 'created_at' => $now,
                    ]
                );
            } catch (QueryException $e) {
                // HANYA 1062 (kunci kembar) yang boleh dicoba ulang. SQLSTATE 23000 mencakup
                // seluruh pelanggaran integritas — termasuk FK (1452) akibat IPRO_TENANT_ID /
                // IPRO_PROJECT_ID yang salah. Menelannya akan mengganti galat yang menyebut
                // tabel & nilainya dengan tuduhan konkurensi yang menyesatkan.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException("Cluster untuk Lokasi #{$lokasiId} gagal disinkronkan setelah tiga percobaan.");
    }

    /**
     * Kode cluster yang dijamin tidak menabrak unique(project_id, code).
     *
     * clusters.code unik per project, sedangkan fo_lokasi.kode hanya unik per project FO —
     * dan seluruh project FO dilipat ke satu config('ipro.project_id'). Dua Lokasi berkode
     * sama dari project FO berbeda karena itu bisa bertabrakan, begitu pula dua Lokasi yang
     * kodenya saling ditukar sementara BO tertinggal beberapa sinkron.
     *
     * Yang kalah TIDAK dibiarkan gagal — cluster tetap dibuat dengan kode disambiguasi,
     * sebab cluster yang tidak pernah lahir berarti Lokasi itu tidak pernah muncul di pilihan
     * Cluster, persis penyakit yang sedang disembuhkan. Bentuknya "{kode}#{id lokasi}" supaya
     * masih terbaca operator, dan tabrakannya dilaporkan agar bisa dibereskan di FO.
     */
    private function kodeClusterAman(int $lokasiId, string $kode): string
    {
        $kode = mb_substr($kode, 0, 32);

        // Kodenya terpakai bila ada baris lain yang mendudukinya DAN tidak akan kita adopsi:
        //   - milik Lokasi lain, atau
        //   - tak bertuan tetapi sudah ditandai terhapus — baris pensiunan sengaja tidak
        //     diadopsi (ia membawa serta unit-unit lama yang masih menunjuknya), sehingga ia
        //     menduduki kodenya tanpa pernah bisa dipakai. Tanpa memperhitungkannya, INSERT
        //     menabrak unique(project_id, code) berulang sampai menyerah, dan Lokasi itu tak
        //     pernah muncul di pilihan Cluster.
        // Baris tak bertuan yang masih hidup TIDAK dihitung terpakai: ia justru diadopsi.
        $terpakai = $this->db()->table('clusters')
            ->where('project_id', $this->projectId)->where('code', $kode)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('fo_lokasi_id')->where('fo_lokasi_id', '!=', $lokasiId))
                ->orWhere(fn ($w) => $w->whereNull('fo_lokasi_id')->whereNotNull('deleted_at')))
            ->exists();

        if (! $terpakai) {
            return $kode;
        }

        $akhiran = '#'.$lokasiId;
        $alternatif = mb_substr($kode, 0, 32 - mb_strlen($akhiran)).$akhiran;
        report(new \RuntimeException(
            "Kode cluster '{$kode}' sudah dipakai Lokasi lain di project ipro_bo yang sama; "
            ."Lokasi #{$lokasiId} dipakaikan '{$alternatif}'."
        ));

        return $alternatif;
    }

    /**
     * Jalur kunci lama (code 'FOL-{id}') untuk BO yang belum punya kolom fo_lokasi_id.
     * Tetap memperbarui nama — itu perbaikan yang berlaku bahkan sebelum BO dimigrasi.
     *
     * @param  array<string,mixed>  $label
     */
    private function upsertClusterWarisan(int $lokasiId, array $label, Carbon $now): int
    {
        $code = 'FOL-'.$lokasiId;
        $ada = $this->db()->table('clusters')
            ->where('project_id', $this->projectId)->where('code', $code)->value('id');

        if ($ada) {
            // Kode dibiarkan 'FOL-{id}' — ia masih kunci satu-satunya di skema lama; hanya
            // nama & status hapusnya yang boleh ikut FO.
            $this->db()->table('clusters')->where('id', $ada)
                ->update(['name' => $label['name'], 'deleted_at' => $label['deleted_at'], 'updated_at' => $now]);

            return (int) $ada;
        }

        return (int) $this->db()->table('clusters')->insertGetId([
            'tenant_id' => $this->tenantId, 'project_id' => $this->projectId,
            'code' => $code, 'name' => $label['name'],
            'deleted_at' => $label['deleted_at'], 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Cluster ipro_bo untuk sebuah unit FO — pembungkus tipis di atas syncLokasi().
     *
     * Kegagalan sengaja ditelan menjadi null: cluster adalah atribut pelengkap unit, dan
     * menggagalkan seluruh sinkron unit hanya karena Lokasinya bermasalah akan menahan data
     * yang jauh lebih penting (harga, status, tipe) di antrean.
     */
    private function iproClusterId(object $u): ?int
    {
        // Dibedakan tegas dari "unit ini memang tanpa lokasi": lihat $clusterTakTerpecahkan.
        $this->clusterTakTerpecahkan = false;

        $lokasiId = $u->lokasi_id ?? null;
        if (! $lokasiId) {
            return null;
        }
        $memoKey = 'cluster:'.$lokasiId;
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $lokasi = DB::table('fo_lokasi')->where('id', $lokasiId)->first();
        if (! $lokasi) {
            $this->clusterTakTerpecahkan = true;

            return null;
        }

        try {
            return $this->syncLokasi($lokasi);
        } catch (\Throwable $e) {
            report($e);
            // Kegagalan TIDAK di-memo. Servis ini singleton di worker berumur panjang; sekali
            // sebuah kegagalan sesaat tersimpan, setiap unit berikutnya dari Lokasi yang sama
            // akan memakai jawaban basi itu tanpa mencoba lagi.
            $this->clusterTakTerpecahkan = true;

            return null;
        }
    }

    /** Unit type ipro_bo dari fo_unit_types (name + LT/LB). Upsert by (project_id, code). */
    private function iproUnitTypeId(object $u): ?int
    {
        $typeId = $u->unit_type_id ?? null;
        if (! $typeId) {
            return null;
        }
        $memoKey = 'type:'.$typeId;
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }
        $t = DB::table('fo_unit_types')->where('id', $typeId)->first();
        if (! $t) {
            return $this->memo[$memoKey] = null;
        }
        $code = 'FOT-'.$typeId;
        $now = Carbon::now();

        $data = [
            'tenant_id' => $this->tenantId, 'project_id' => $this->projectId,
            'code' => $code, 'name' => $t->name ?? $code,
            'lt' => $t->luas_tanah ?? null, 'lb' => $t->luas_bangunan ?? null,
            'bedrooms' => $t->jumlah_kamar ?? null, 'bathrooms' => $t->jumlah_kmandi ?? null,
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
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'customer_id' => $custId,
            'unit_id' => $unitId,
            'payment_scheme_id' => $this->iproPaymentSchemeId($typeMap[$k->cara_bayar ?? 'KPR'] ?? 'kpr'),
            'scheme_name' => $k->skema?->name,   // nama skema FO asli (mis. "KPR FREE DP", "CASH 3X")
            'booking_date' => $k->tgl_kontrak ?? $now->toDateString(),
            'base_price' => $base,
            'discount_amount' => $diskon,
            'free_dp_amount' => (float) ($k->free_dp_amount ?? 0),
            'ppn_amount' => $ppn,
            'total_price' => $total,
            'status' => $statusMap[$k->status ?? 'DRAFT'] ?? 'draft',
            'booking_source' => 'fo-jerp',
            'created_by' => $this->userId(),
            'notes' => 'SPR '.($k->no_kontrak ?? ''),
            'updated_at' => $now,
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
        $memoKey = 'scheme:'.$type;
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
                'code' => 'FO-'.strtoupper($type), 'name' => 'FO '.$type, 'type' => $type,
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return (int) ($this->memo[$memoKey] = (int) $id);
    }

    /** Replace payment_schedules dari fo_kontrak_tagihan. */
    /**
     * Sinkron jadwal tagihan FO → ipro_bo.payment_schedules secara REKONSILIASI (bukan delete+insert),
     * agar id payment_schedules yang direferensi tabel lain (tts_allocations, payment_allocations,
     * dendas, pjt_details, tunggakan_details) TIDAK dilanggar FK. Baris dicocokkan per kunci
     * (payment_type|label|#urutan-kemunculan): cocok → update in-place (id tetap), baru → insert,
     * sisa existing → hapus HANYA yang tak direferensi.
     */
    private function syncSchedules(int $bookingId, int $foKontrakId, object $k): void
    {
        // PaymentType enum ipro-fo (varchar 5): BF, DP, ANG, ADM. LAIN→ANG.
        $typeMap = ['BF' => 'BF', 'DP' => 'DP', 'ANG' => 'ANG', 'ADM' => 'ADM', 'LAIN' => 'ANG'];
        $now = Carbon::now();
        $fallbackDue = $k->tgl_kontrak ?? $now->toDateString();

        // Baris yang diinginkan dari tagihan FO (dengan kunci pencocokan stabil).
        $desired = [];
        $i = 0;
        $dCounters = [];
        foreach (DB::table('fo_kontrak_tagihan')->where('kontrak_id', $foKontrakId)->orderBy('urutan')->get() as $t) {
            $i++;
            $nilai = (float) ($t->nilai ?? 0);
            $bayar = (float) ($t->pelunasan ?? 0);
            $type = $typeMap[$t->tipe ?? 'ANG'] ?? 'ANG';
            $label = $t->nama_tagihan ?? null;
            $desired[] = [
                'key' => $this->scheduleKey($type, $label, $dCounters),
                'data' => [
                    'booking_id' => $bookingId,
                    'installment_no' => $t->urutan ?: $i,
                    'sequence' => $i,
                    'payment_type' => $type,
                    'label' => $label,
                    'due_date' => $t->jatuh_tempo ?? $fallbackDue,
                    'amount' => $nilai,
                    'paid_amount' => $bayar,
                    'status' => ($nilai > 0 && $bayar >= $nilai) ? 'paid' : 'outstanding',
                    'updated_at' => $now,
                ],
            ];
        }

        // Baris existing → map kunci => id (urut sequence/id agar penomoran kemunculan stabil).
        $existing = $this->db()->table('payment_schedules')
            ->where('booking_id', $bookingId)->orderBy('sequence')->orderBy('id')
            ->get(['id', 'payment_type', 'label']);
        $existingByKey = [];
        $eCounters = [];
        foreach ($existing as $row) {
            $existingByKey[$this->scheduleKey($row->payment_type, $row->label, $eCounters)] = (int) $row->id;
        }

        // Rekonsiliasi: cocok → update in-place (id tetap → FK aman); baru → insert.
        $usedIds = [];
        foreach ($desired as $d) {
            $id = $existingByKey[$d['key']] ?? null;
            if ($id) {
                $this->db()->table('payment_schedules')->where('id', $id)->update($d['data']);
                $usedIds[] = $id;
            } else {
                $this->db()->table('payment_schedules')->insert($d['data'] + ['created_at' => $now]);
            }
        }

        // Sisa existing yang tak terpakai: hapus HANYA yang tak direferensi tabel lain.
        $leftover = array_values(array_diff(
            array_map(fn ($r) => (int) $r->id, $existing->all()),
            $usedIds,
        ));
        if ($leftover) {
            $referenced = [];
            $refTables = ['tts_allocations', 'payment_allocations', 'dendas', 'pjt_details', 'tunggakan_details'];
            foreach ($refTables as $tbl) {
                if (! $this->db()->getSchemaBuilder()->hasTable($tbl)) {
                    continue;
                }
                foreach ($this->db()->table($tbl)->whereIn('payment_schedule_id', $leftover)->pluck('payment_schedule_id') as $refId) {
                    $referenced[(int) $refId] = true;
                }
            }
            $deletable = array_values(array_filter($leftover, fn ($id) => ! isset($referenced[$id])));
            if ($deletable) {
                $this->db()->table('payment_schedules')->whereIn('id', $deletable)->delete();
            }
        }
    }

    /** Kunci pencocokan jadwal: payment_type|LABEL|#urutan-kemunculan (menangani label kembar mis. UANG MASUK). */
    private function scheduleKey(?string $type, ?string $label, array &$counters): string
    {
        $base = ($type ?? 'ANG').'|'.strtoupper(trim((string) $label));
        $counters[$base] = ($counters[$base] ?? 0) + 1;

        return $base.'|#'.$counters[$base];
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
            'tenant_id' => $this->tenantId,
            'customer_id' => $custId,
            'booking_id' => $bookingId,
            'amount' => $t->amount ?? 0,
            'payment_method' => $pmMap[$t->cara_bayar ?? 'TRANSFER'] ?? 'TN',
            'received_date' => $t->tgl_tts ?? $now->toDateString(),
            'reference_no' => $t->no_referensi ?? null,
            'status' => $statusMap[$t->status ?? 'DRAFT'] ?? 'pending',
            'received_by' => $this->userId(),
            'notes' => $t->keterangan ?? null,
            'updated_at' => $now,
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
    /**
     * @param  ?string  $approvalSource  'dp' → tampil di BO "Approval DP" (Pelunasan DP, Angsuran
     *                                   & Tambah DP); null → "Approval UTJ" seperti sebelumnya.
     */
    public function pushBkmToApproval(object $bkm, ?string $approvalSource = null): ?int
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
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->iproCustomerIdByFo((int) $bkm->customer_id),
            'booking_id' => $bookingId,
            'amount' => $bkm->amount ?? 0,
            'payment_method' => 'TN',
            'received_date' => $bkm->tgl_bkm ? Carbon::parse((string) $bkm->tgl_bkm)->toDateString() : $now->toDateString(),
            'reference_no' => $bkm->no_referensi ?? null,
            'status' => 'posted', // posted + journal_id null = "Menunggu Approval" di BO
            'received_by' => $this->userId(),
            'updated_at' => $now,
        ];
        // Penanda submodul approval di BO. Hanya diisi bila memang ada nilainya, supaya
        // pemanggil lama (Kwitansi → Posting BO) tetap masuk Approval UTJ apa adanya.
        if ($approvalSource !== null) {
            $data['approval_source'] = $approvalSource;
        }

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
            'tenant_id' => $this->tenantId,
            'customer_id' => $custId,
            'booking_id' => $bookingId,
            'amount' => $t->amount ?? 0,
            'payment_method' => 'TN',
            'received_date' => $t->tgl_transfer ?? $now->toDateString(),
            'reference_no' => $t->keterangan_bank ?? null,
            'status' => 'posted',   // tampil di Approval UTJ&DP (Tanpa Unit)
            'received_by' => $this->userId(),
            'notes' => 'Transfer Anonim'.($t->keterangan ? ' — '.$t->keterangan : ''),
            'updated_at' => $now,
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
            'tenant_id' => $this->tenantId,
            'customer_id' => $custId,
            'booking_id' => $bookingId,
            'plafon_requested' => $plafon,
            'current_stage' => 2, // SPRS — FO sudah buat SP/SPRS, ipro lanjut pemberkasan
            'status' => $statusMap[$p->status ?? 'DRAFT'] ?? 'in_progress',
            'stages' => json_encode(['jerp_pengajuan_id' => $p->id, 'jerp_no' => $p->no_pengajuan]),
            'notes' => $p->keterangan ?? null,
            'initiated_by' => $this->userId(),
            'updated_at' => $now,
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
