<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Services;

use App\Domain\BankLoan\Actions\RecordDisbursement;
use App\Domain\BankLoan\Actions\ReverseDisbursement;
use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprApprovalSetting;
use App\Domain\BankLoan\Models\KprChangeRequest;
use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\RetentionCategory;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PERUBAHAN DATA PROSES KPR — dengan atau tanpa approval.
 *
 * Setiap edit / hapus / mundur tahap / batal pada Proses KPR lewat sini. Per TAHAP (Setup Approval
 * KPR) ditentukan apakah perubahan wajib approval, siapa approver-nya, dan siapa yang boleh
 * mengubah langsung:
 *
 *   tidak wajib / user "boleh ubah langsung"  → diterapkan seketika, dicatat status 'direct'
 *   wajib                                     → disimpan 'pending'; data lama tetap berlaku sampai
 *                                               approver tahap itu menyetujui (lalu diterapkan)
 *
 * Aturan tiap perubahan (mis. plafon tidak boleh di bawah total cair, akad tak bisa dihapus bila
 * sudah ada pencairan) ada di SATU tempat — periksa() — dan dijalankan dua kali: saat diajukan
 * (supaya pengajuan yang mustahil langsung ditolak) dan saat disetujui (keadaan KPR bisa saja
 * sudah berubah selama pengajuan menunggu).
 */
final class KprPerubahanService
{
    public const STAGES = [
        'prosesbank' => 'Proses Bank',
        'sp3k'       => 'SP3K',
        'akad'       => 'Akad',
        'pencairan'  => 'Pencairan',
        'batal'      => 'Batal / Tolak',
    ];

    /** aksi => [tahap, label] */
    public const ACTIONS = [
        'proses_ubah'     => ['prosesbank', 'Ubah Data Tahap'],
        'mundur'          => ['prosesbank', 'Mundur ke Tahap Sebelumnya'],
        'buka_tahap'      => ['prosesbank', 'Buka Tahap'],
        'sp3k_ubah'       => ['sp3k', 'Ubah SP3K'],
        'sp3k_hapus'      => ['sp3k', 'Hapus SP3K'],
        'akad_ubah'       => ['akad', 'Ubah Akad'],
        'akad_hapus'      => ['akad', 'Hapus Akad'],
        'pencairan_ubah'  => ['pencairan', 'Ubah Pencairan'],
        'pencairan_hapus' => ['pencairan', 'Hapus Pencairan'],
        'batal'           => ['batal', 'Batalkan Proses KPR'],
        'tolak'           => ['batal', 'Tolak Proses KPR'],
        'pulihkan'        => ['batal', 'Pulihkan Proses KPR'],
    ];

    /** Tahap tambahan Proses KPR (Wawancara..Banding) — data di kpr_applications.process_stages. */
    public const PROCESS_STAGES = [
        'wawancara'  => 'Wawancara',
        'input_bank' => 'Input Sistem Bank',
        'verifikasi' => 'Proses Verifikasi',
        'analis'     => 'Proses Analis',
        'banding'    => 'Proses Banding',
    ];

    /** Tahap yang boleh di-Reject (Tolak Proses KPR) — sebelum SP3K terbit / SI. */
    private const REJECTABLE_STATUSES = ['submitted', 'wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'in_review', 'sp3k'];

    /**
     * Tahap yang boleh DIBATALKAN lewat tombol "Batal" pada rincian tahap.
     * TIDAK termasuk akad / disbursed (Pencairan) / active (Lunas) — sudah terlalu jauh untuk dibatalkan.
     */
    private const CANCELLABLE_STATUSES = ['draft', 'submitted', 'in_review', 'wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'sp3k', 'si'];

    /** Tahap yang bisa dibuka lewat "Buka Tahap" (sebelum Akad). */
    private const NAV_STAGES = ['submitted', 'wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'sp3k', 'si'];

    /** @var array<string, KprApprovalSetting|null> */
    private array $settings = [];

    public function __construct(
        private readonly ReverseDisbursement $reverse,
        private readonly RecordDisbursement $record,
    ) {}

    // ── Setup ────────────────────────────────────────────────────────────

    public function setting(string $stage): ?KprApprovalSetting
    {
        return $this->settings[$stage] ??= KprApprovalSetting::where('stage', $stage)->first();
    }

    public function butuhApproval(string $stage, ?User $user): bool
    {
        $s = $this->setting($stage);
        if (! $s || ! $s->is_required) {
            return false;
        }

        return ! in_array((int) $user?->id, array_map('intval', $s->bypass_user_ids ?? []), true);
    }

    /** @return array<string, 'ajukan'|'langsung'> mode tombol per tahap untuk user ini. */
    public function modeUntuk(?User $user): array
    {
        $out = [];
        foreach (array_keys(self::STAGES) as $stage) {
            $out[$stage] = $this->butuhApproval($stage, $user) ? 'ajukan' : 'langsung';
        }

        return $out;
    }

    /** @return list<string> tahap yang approver-nya mencantumkan user ini. */
    public function tahapApprover(int $userId): array
    {
        return KprApprovalSetting::query()->get()
            ->filter(fn ($s) => in_array($userId, array_map('intval', $s->approver_user_ids ?? []), true))
            ->pluck('stage')->values()->all();
    }

    public function pending(KprApplication $kpr): ?KprChangeRequest
    {
        return KprChangeRequest::where('kpr_application_id', $kpr->id)->where('status', 'pending')->latest('id')->first();
    }

    // ── Ajukan / setujui / tolak ─────────────────────────────────────────

    /**
     * Terapkan langsung bila tahapnya tidak wajib approval (atau user boleh ubah langsung);
     * selain itu simpan sebagai pengajuan. Melempar BusinessRuleException bila perubahan
     * tidak mungkin dilakukan.
     */
    public function ajukanAtauTerapkan(KprApplication $kpr, string $aksi, array $data, ?string $alasan, User $user, ?int $targetId = null): KprChangeRequest
    {
        $stage = self::ACTIONS[$aksi][0];
        if ($p = $this->pending($kpr)) {
            throw new BusinessRuleException('Masih ada pengajuan "'.self::ACTIONS[$p->action][1].'" yang menunggu approval. Tunggu diputuskan, atau batalkan pengajuan itu dulu.');
        }

        $data = $this->periksa($kpr, $aksi, $data, $targetId);
        [$old, $new] = $this->snapshot($kpr, $aksi, $data, $targetId);
        $alasan = trim((string) $alasan);

        $row = [
            'kpr_application_id' => $kpr->id, 'stage' => $stage, 'action' => $aksi, 'target_id' => $targetId,
            'old_data' => $old, 'new_data' => $new, 'reason' => $alasan ?: null, 'requested_by' => $user->id,
        ];

        if (! $this->butuhApproval($stage, $user)) {
            return DB::transaction(function () use ($kpr, $aksi, $data, $targetId, $user, $row): KprChangeRequest {
                $this->terapkan($kpr, $aksi, $data, $targetId, (int) $user->id);

                return KprChangeRequest::create($row + ['status' => 'direct', 'decided_by' => $user->id, 'decided_at' => now()]);
            });
        }

        if ($alasan === '') {
            throw new BusinessRuleException('Alasan perubahan wajib diisi.');
        }

        return KprChangeRequest::create($row + ['status' => 'pending']);
    }

    public function setujui(KprChangeRequest $c, User $approver, ?string $catatan): void
    {
        DB::transaction(function () use ($c, $approver, $catatan): void {
            $c = KprChangeRequest::lockForUpdate()->findOrFail($c->id);
            $this->bolehMemutuskan($c, $approver);

            $kpr = KprApplication::findOrFail($c->kpr_application_id);
            $data = $c->new_data ?? [];
            // Diperiksa ULANG terhadap keadaan KPR saat ini, lalu diterapkan.
            $data = $this->periksa($kpr, $c->action, $data, $c->target_id);
            $this->terapkan($kpr, $c->action, $data, $c->target_id, (int) $c->requested_by);

            $c->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => trim((string) $catatan) ?: null]);
        });
    }

    public function tolak(KprChangeRequest $c, User $approver, ?string $catatan): void
    {
        DB::transaction(function () use ($c, $approver, $catatan): void {
            $c = KprChangeRequest::lockForUpdate()->findOrFail($c->id);
            $this->bolehMemutuskan($c, $approver);
            $c->update(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => trim((string) $catatan) ?: null]);
        });
    }

    /** Pengaju menarik kembali pengajuannya sendiri yang masih menunggu. */
    public function batalkanPengajuan(KprChangeRequest $c, User $user): void
    {
        if ($c->status !== 'pending') {
            throw new BusinessRuleException('Pengajuan ini sudah diputuskan.');
        }
        if ((int) $c->requested_by !== (int) $user->id) {
            throw new BusinessRuleException('Hanya pengaju yang bisa membatalkan pengajuannya.');
        }
        $c->update(['status' => 'cancelled', 'decided_by' => $user->id, 'decided_at' => now()]);
    }

    private function bolehMemutuskan(KprChangeRequest $c, User $approver): void
    {
        if ($c->status !== 'pending') {
            throw new BusinessRuleException('Pengajuan ini sudah diputuskan.');
        }
        $approvers = array_map('intval', $this->setting($c->stage)?->approver_user_ids ?? []);
        if (! in_array((int) $approver->id, $approvers, true)) {
            throw new BusinessRuleException('Anda bukan approver tahap '.self::STAGES[$c->stage].'.');
        }
        if ((int) $c->requested_by === (int) $approver->id) {
            throw new BusinessRuleException('Pengajuan sendiri tidak boleh disetujui/ditolak sendiri.');
        }
    }

    // ── Aturan tiap perubahan ────────────────────────────────────────────

    /**
     * Pastikan perubahan bisa dilakukan pada keadaan KPR saat ini. Mengembalikan data yang sudah
     * dinormalkan (dipakai untuk disimpan & diterapkan).
     */
    private function periksa(KprApplication $kpr, string $aksi, array $data, ?int $targetId): array
    {
        $kpr->refresh();
        $gagal = fn (string $m) => throw new BusinessRuleException($m);

        switch ($aksi) {
            case 'proses_ubah':
                $stage = (string) ($data['stage'] ?? '');
                if (! isset(self::PROCESS_STAGES[$stage])) {
                    $gagal('Tahap proses tidak dikenal.');
                }
                if (empty(($kpr->process_stages ?? [])[$stage])) {
                    $gagal('Tahap '.self::PROCESS_STAGES[$stage].' belum pernah diisi — isi lewat tahapnya sendiri.');
                }
                [$clean, , $err] = $this->normalizeProcessStage($stage, (array) ($data['data'] ?? []));
                if ($err !== null) {
                    $gagal($err);
                }
                // Pilihan "perlu banding" menentukan jalur tahap yang sudah dilalui — tidak diubah di sini.
                if ($stage === 'analis') {
                    $clean['banding'] = (bool) (($kpr->process_stages['analis'] ?? [])['banding'] ?? false);
                }

                return ['stage' => $stage, 'data' => $clean];

            case 'mundur':
                if ($this->tahapSebelumnya($kpr) === null) {
                    $gagal('Tahap ini tidak dapat dikembalikan ke tahap sebelumnya.');
                }

                return [];

            case 'buka_tahap':
                $target = (string) ($data['stage'] ?? '');
                $ti = array_search($target, self::NAV_STAGES, true);
                if ($ti === false) {
                    $gagal('Tahap tujuan tidak valid.');
                }
                if (in_array($kpr->status, [KprStatus::AkadDone, KprStatus::Disbursed, KprStatus::Active], true) || $kpr->akad()->exists()) {
                    $gagal('Proses sudah pada tahap Akad — data tahap sebelumnya terkunci (tidak dapat diedit).');
                }
                if ($ti > $this->furthestNavIndex($kpr)) {
                    $gagal('Tahap tersebut belum dapat dibuka (belum pernah dicapai).');
                }
                if ($kpr->status->value === $target) {
                    $gagal('KPR sudah berada di tahap itu.');
                }

                return ['stage' => $target];

            case 'sp3k_ubah':
                if (! $kpr->sp3k()->exists()) {
                    $gagal('SP3K belum ada untuk diubah.');
                }
                $gross = (float) $kpr->realizations()->sum('disbursed_amount');
                if ((float) $data['approved_amount'] + 0.01 < $gross) {
                    $gagal('Plafon baru ('.$this->rp($data['approved_amount']).') lebih kecil dari total yang sudah dicairkan ('.$this->rp($gross).').');
                }

                return ['sp3k_date' => (string) $data['sp3k_date'], 'approved_amount' => (float) $data['approved_amount']];

            case 'sp3k_hapus':
                if (! $kpr->sp3k()->exists()) {
                    $gagal('SP3K belum ada untuk dihapus.');
                }
                if ($kpr->akad()->exists() || $kpr->realizations()->exists()) {
                    $gagal('Tidak bisa hapus SP3K: sudah ada akad / pencairan. Hapus tahap itu dulu.');
                }

                return [];

            case 'akad_ubah':
                if (! $kpr->akad()->exists()) {
                    $gagal('Akad belum ada untuk diubah.');
                }

                return ['akad_at' => (string) $data['akad_at'], 'place' => trim((string) ($data['place'] ?? '')) ?: null];

            case 'akad_hapus':
                if (! $kpr->akad()->exists()) {
                    $gagal('Akad belum ada untuk dihapus.');
                }
                if ($kpr->realizations()->exists()) {
                    $gagal('Tidak bisa hapus Akad: sudah ada pencairan. Hapus pencairan dulu.');
                }

                return [];

            case 'pencairan_ubah':
            case 'pencairan_hapus':
                $r = KprRealization::with(['retentions', 'tts'])->find($targetId);
                if (! $r || (int) $r->kpr_application_id !== (int) $kpr->id) {
                    $gagal('Data pencairan tidak ditemukan.');
                }
                if ((string) $r->tts?->status === 'posted') {
                    $gagal('TTS pencairan sudah diposting — void dulu di modul TTS sebelum mengubah/menghapus pencairan ini.');
                }
                if ($r->journal_id || $r->retentions->contains(fn ($x) => $x->journal_id)) {
                    $gagal('Pencairan ini sudah dijurnal di Approval Retensi — tidak bisa diubah/dihapus dari sini.');
                }
                if ($aksi === 'pencairan_hapus') {
                    return [];
                }
                if ($r->retentions->contains(fn ($x) => (float) $x->released_amount > 0)) {
                    $gagal('Retensi pencairan ini sudah pernah dilepas — tidak bisa diubah.');
                }

                $new = [
                    'disbursed_at'          => (string) $data['disbursed_at'],
                    'retention_category_id' => (int) $data['retention_category_id'],
                    'disbursed_amount'      => (float) ($data['disbursed_amount'] ?? 0),
                    'retention_amount'      => (float) ($data['retention_amount'] ?? 0),
                    'retention_note'        => trim((string) ($data['retention_note'] ?? '')) ?: null,
                    'disbursement_scheme'   => $data['disbursement_scheme'] ?? null,
                    'ssp_bphtb_date'        => $data['ssp_bphtb_date'] ?? null,
                    'ssp_bphtb_amount'      => (float) ($data['ssp_bphtb_amount'] ?? 0),
                ];
                if ($new['disbursed_amount'] <= 0 && $new['retention_amount'] <= 0) {
                    $gagal('Isi Nominal Cair atau Retensi/DAJAM (salah satu wajib lebih dari 0).');
                }
                if ($new['retention_amount'] > 0 && $new['retention_note'] === null) {
                    $gagal('Keterangan Retensi / DAJAM wajib diisi untuk setiap retensi.');
                }
                $plafon = (float) ($kpr->plafon_approved ?? 0);
                $lain = (float) $kpr->realizations()->where('id', '!=', $r->id)->sum('disbursed_amount');
                if ($plafon > 0 && $lain + $new['disbursed_amount'] > $plafon + 0.01) {
                    $gagal('Total pencairan melebihi plafon disetujui ('.$this->rp($plafon).'). Maksimal nominal cair untuk baris ini '.$this->rp(max(0, $plafon - $lain)).'.');
                }

                return $new;

            case 'batal':
                if (! in_array($kpr->status->value, self::CANCELLABLE_STATUSES, true)) {
                    $gagal('Proses KPR tidak dapat dibatalkan pada tahap Akad / Pencairan / Lunas.');
                }
                $this->bolehTransisi($kpr, KprStatus::Cancelled, 'Status KPR saat ini tidak bisa dibatalkan.');

                return [];

            case 'tolak':
                if (! in_array($kpr->status->value, self::REJECTABLE_STATUSES, true)) {
                    $gagal('Reject hanya bisa dilakukan sampai tahap SP3K (sebelum SI).');
                }
                $this->bolehTransisi($kpr, KprStatus::Rejected, 'Status KPR saat ini tidak bisa ditolak.');

                return ['reason' => trim((string) ($data['reason'] ?? '')) ?: null];

            case 'pulihkan':
                if ($kpr->status !== KprStatus::Cancelled) {
                    $gagal('Hanya Proses KPR berstatus Batal yang bisa dipulihkan.');
                }

                return [];
        }

        throw new BusinessRuleException('Jenis perubahan tidak dikenal.');
    }

    /** Lakukan perubahannya. $userId = pengaju (dicatat sebagai handled_by bila relevan). */
    private function terapkan(KprApplication $kpr, string $aksi, array $data, ?int $targetId, int $userId): void
    {
        switch ($aksi) {
            case 'proses_ubah':
                $ps = $kpr->process_stages ?? [];
                $ps[$data['stage']] = $data['data'];
                $kpr->process_stages = $ps;
                $kpr->save();

                return;

            case 'mundur':
                $prev = $this->tahapSebelumnya($kpr);
                // Hapus cap waktu tahap yang di-masuki-ulang agar Laporan Durasi Proses tidak lagi
                // menandainya SELESAI; cap dibuat ulang saat operator lanjut lagi.
                $timeline = $kpr->timeline ?? [];
                unset($timeline[$prev->value]);
                $kpr->update(['status' => $prev, 'timeline' => $timeline]); // record tahap (SP3K/Akad) dipertahankan

                return;

            case 'buka_tahap':
                $kpr->update(['status' => KprStatus::from($data['stage'])]);

                return;

            case 'sp3k_ubah':
                $kpr->sp3k()->first()->update(['sp3k_date' => $data['sp3k_date'], 'approved_amount' => $data['approved_amount']]);
                $gross = (float) $kpr->realizations()->sum('disbursed_amount');
                $upd = ['plafon_approved' => $data['approved_amount']];
                // Plafon naik melewati total cair → Lunas dibuka lagi; plafon turun sampai total cair → Lunas.
                if ($kpr->status === KprStatus::Active && $gross + 0.01 < $data['approved_amount']) {
                    $upd['status'] = KprStatus::Disbursed;
                } elseif ($kpr->status === KprStatus::Disbursed && $gross > 0 && $gross + 0.01 >= $data['approved_amount']) {
                    $upd['status'] = KprStatus::Active;
                }
                $kpr->update($upd);

                return;

            case 'sp3k_hapus':
                $kpr->sp3k()->first()?->delete();
                $kpr->update(['status' => KprStatus::Sp3kIssued, 'plafon_approved' => null]);

                return;

            case 'akad_ubah':
                $akad = $kpr->akad()->first();
                $akad->update([
                    'akad_at' => $data['akad_at'],
                    'place'   => $data['place'] ?: ($kpr->bank?->name ? 'Kantor '.$kpr->bank->name : $akad->place),
                ]);

                return;

            case 'akad_hapus':
                $kpr->akad()->first()?->delete();
                $kpr->update(['status' => KprStatus::AkadDone]);

                return;

            case 'pencairan_hapus':
                $this->reverse->execute(KprRealization::findOrFail($targetId));

                return;

            case 'pencairan_ubah':
                // Ubah = batalkan baris lama (alokasi & TTS dikembalikan) lalu catat ulang dengan
                // nilai baru — jalur yang sama dengan Hapus + Catat Pencairan, jadi TTS & jadwal
                // angsurannya ikut terbentuk ulang dengan benar.
                $old = KprRealization::findOrFail($targetId);
                $payload = $data + ['reference_no' => $old->reference_no, 'bank_account_id' => $old->bank_account_id];
                $this->reverse->execute($old);
                $kpr->refresh();
                if ($kpr->status === KprStatus::Active) {
                    $kpr->update(['status' => KprStatus::Disbursed]); // dicatat ulang → RecordDisbursement menetapkan Lunas lagi bila penuh
                }
                try {
                    $this->record->execute($kpr->fresh(), $payload);
                } catch (InvalidStateTransitionException $e) {
                    throw new BusinessRuleException($e->getMessage());
                }

                return;

            case 'batal':
                $kpr->update([
                    'decision'             => 'batal',
                    'decided_at'           => now()->toDateString(),
                    'status'               => KprStatus::Cancelled,
                    'status_before_cancel' => $kpr->status->value, // agar bisa dipulihkan
                    'handled_by'           => $userId,
                ]);

                return;

            case 'tolak':
                $kpr->update([
                    'status'        => KprStatus::Rejected,
                    'reject_reason' => $data['reason'] ?? null,
                    'handled_by'    => $userId,
                    'decided_at'    => now()->toDateString(),
                ]);

                return;

            case 'pulihkan':
                // Keputusan "batal" DICABUT (decision → null), bukan diubah jadi "lanjut" — memulihkan ≠ menyetujui.
                $kpr->update([
                    'status'               => $this->tahapPulih($kpr),
                    'status_before_cancel' => null,
                    'decision'             => null,
                    'decided_at'           => null,
                    'handled_by'           => $userId,
                ]);

                return;
        }
    }

    /** [data lama, data baru] untuk ditampilkan berdampingan di layar approval & riwayat. */
    private function snapshot(KprApplication $kpr, string $aksi, array $data, ?int $targetId): array
    {
        $statusNow = ['status' => $kpr->status->label()];

        return match ($aksi) {
            'proses_ubah' => [
                ['stage' => $data['stage'], 'data' => ($kpr->process_stages ?? [])[$data['stage']] ?? []],
                $data,
            ],
            'mundur'      => [$statusNow, ['status' => $this->tahapSebelumnya($kpr)->label()]],
            'buka_tahap'  => [$statusNow, $data + ['status' => KprStatus::from($data['stage'])->label()]],
            'sp3k_ubah', 'sp3k_hapus' => [
                ['sp3k_date' => $kpr->sp3k?->sp3k_date?->toDateString(), 'approved_amount' => (float) $kpr->sp3k?->approved_amount],
                $data,
            ],
            'akad_ubah', 'akad_hapus' => [
                ['akad_at' => $kpr->akad?->akad_at?->toDateString(), 'place' => $kpr->akad?->place],
                $data,
            ],
            'pencairan_ubah', 'pencairan_hapus' => [$this->dataPencairan(KprRealization::find($targetId)), $data],
            'batal'    => [$statusNow, ['status' => KprStatus::Cancelled->label()]],
            'tolak'    => [$statusNow, $data + ['status' => KprStatus::Rejected->label()]],
            'pulihkan' => [$statusNow, ['status' => $this->tahapPulih($kpr)->label()]],
        };
    }

    private function dataPencairan(?KprRealization $r): array
    {
        return $r ? [
            'disbursed_at'          => $r->disbursed_at?->toDateString(),
            'retention_category_id' => $r->retention_category_id ? (int) $r->retention_category_id : null,
            'disbursed_amount'      => (float) $r->disbursed_amount,
            'retention_amount'      => (float) $r->retention_amount,
            'retention_note'        => $r->retention_note,
            'disbursement_scheme'   => $r->disbursement_scheme,
            'ssp_bphtb_date'        => $r->ssp_bphtb_date?->toDateString(),
            'ssp_bphtb_amount'      => (float) $r->ssp_bphtb_amount,
        ] : [];
    }

    // ── Tampilan ─────────────────────────────────────────────────────────

    /**
     * Rincian lama → baru per isian. Untuk aksi hapus kolom "baru" bertuliskan "(dihapus)".
     *
     * @return list<array{label:string, lama:string, baru:string, berubah:bool}>
     */
    public function rincian(KprChangeRequest $c): array
    {
        $old = $c->old_data ?? [];
        $new = $c->new_data ?? [];
        $hapus = str_ends_with($c->action, '_hapus');

        $fields = match (true) {
            str_starts_with($c->action, 'sp3k')      => ['sp3k_date' => ['Tanggal SP3K', 'date'], 'approved_amount' => ['Plafon Disetujui', 'rp']],
            str_starts_with($c->action, 'akad')      => ['akad_at' => ['Tanggal Akad', 'date'], 'place' => ['Tempat', 'text']],
            str_starts_with($c->action, 'pencairan') => [
                'disbursed_at' => ['Tanggal Cair', 'date'], 'retention_category_id' => ['Kategori Retensi', 'kategori'],
                'disbursed_amount' => ['Nominal Cair', 'rp'], 'retention_amount' => ['Retensi / DAJAM', 'rp'],
                'retention_note' => ['Keterangan Retensi', 'text'], 'disbursement_scheme' => ['Jalur Pencairan', 'skema'],
                'ssp_bphtb_date' => ['Tanggal SSP/BPHTB', 'date'], 'ssp_bphtb_amount' => ['Nominal SSP/BPHTB', 'rp'],
            ],
            $c->action === 'tolak'       => ['status' => ['Status', 'text'], 'reason' => ['Alasan Penolakan', 'text']],
            $c->action === 'proses_ubah' => null,
            default                      => ['status' => ['Status', 'text']],
        };

        if ($fields === null) { // proses_ubah: isian bertingkat (mis. Verifikasi → OTS / Wawancara Kedua)
            $o = $this->ratakan((array) ($old['data'] ?? []));
            $n = $this->ratakan((array) ($new['data'] ?? []));
            $out = [['label' => 'Tahap', 'lama' => self::PROCESS_STAGES[$new['stage'] ?? ''] ?? '—', 'baru' => self::PROCESS_STAGES[$new['stage'] ?? ''] ?? '—', 'berubah' => false]];
            foreach (array_unique([...array_keys($o), ...array_keys($n)]) as $k) {
                $out[] = $this->baris($this->labelProses($k), $this->fmt($o[$k] ?? null, str_contains($k, '_at') ? 'date' : 'text'), $this->fmt($n[$k] ?? null, str_contains($k, '_at') ? 'date' : 'text'));
            }

            return $out;
        }

        $out = [];
        foreach ($fields as $k => [$label, $type]) {
            if ($c->action === 'tolak' && $k === 'reason') {
                $out[] = $this->baris($label, '—', $this->fmt($new[$k] ?? null, $type));
                continue;
            }
            $out[] = $this->baris($label, $this->fmt($old[$k] ?? null, $type), $hapus ? '(dihapus)' : $this->fmt($new[$k] ?? null, $type));
        }

        return $out;
    }

    /** Satu baris daftar approval / riwayat. */
    public function baris(string $label, string $lama, string $baru): array
    {
        return ['label' => $label, 'lama' => $lama, 'baru' => $baru, 'berubah' => $lama !== $baru];
    }

    /** Ringkasan pengajuan untuk layar (daftar approval, banner & riwayat di halaman KPR). */
    public function tampil(KprChangeRequest $c): array
    {
        $kpr = $c->kpr;

        return [
            'id'          => $c->id,
            'kpr_id'      => $c->kpr_application_id,
            'kpr_number'  => $kpr?->application_number,
            'customer'    => $kpr?->customer?->full_name,
            'unit'        => $kpr?->booking?->unit?->unit_code,
            'bank'        => $kpr?->bank?->name,
            'stage'       => $c->stage,
            'stage_label' => self::STAGES[$c->stage] ?? $c->stage,
            'action'      => $c->action,
            'label'       => (self::ACTIONS[$c->action][1] ?? $c->action).($c->action === 'proses_ubah' ? ' — '.(self::PROCESS_STAGES[$c->new_data['stage'] ?? ''] ?? '') : ''),
            'rincian'     => $this->rincian($c),
            'reason'      => $c->reason,
            'status'      => $c->status,
            'requested_by_id' => $c->requested_by,
            'requested_by' => $c->requester?->name,
            'requested_at' => $c->created_at?->toIso8601String(),
            'decided_by'  => $c->decider?->name,
            'decided_at'  => $c->decided_at?->toIso8601String(),
            'decision_note' => $c->decision_note,
        ];
    }

    private function ratakan(array $d, string $prefix = ''): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            if (is_array($v)) {
                $out += $this->ratakan($v, $prefix.$k.'.');
            } else {
                $out[$prefix.$k] = is_bool($v) ? ($v ? 'Ya' : 'Tidak') : $v;
            }
        }

        return $out;
    }

    private function labelProses(string $key): string
    {
        $grup = ['ots' => 'OTS · ', 'wawancara2' => 'Wawancara Kedua · '];
        $nama = [
            'started_at' => 'Tanggal Mulai', 'finished_at' => 'Tanggal Selesai', 'notes' => 'Update Proses & Kendala',
            'berkas_tambahan' => 'Tambahan Berkas', 'banding' => 'Perlu Banding', 'jenis' => 'Jenis Banding',
            'jenis_lain' => 'Keterangan Lain-Lain', 'wawancara2_mode' => 'Jalur Wawancara Kedua',
        ];
        $parts = explode('.', $key);
        $last = array_pop($parts);

        return implode('', array_map(fn ($p) => $grup[$p] ?? $p.' · ', $parts)).($nama[$last] ?? $last);
    }

    private function fmt(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return match ($type) {
            'rp'       => $this->rp($v),
            'date'     => rescue(fn () => Carbon::parse((string) $v)->translatedFormat('d M Y'), (string) $v, false),
            'kategori' => (string) (RetentionCategory::withTrashed()->find($v)?->name ?? $v),
            'skema'    => $v === 'bsn' ? 'BSN' : ($v === 'non_bsn' ? 'SELAIN BSN' : (string) $v),
            default    => match ((string) $v) {
                'dengan' => 'Melalui Wawancara Kedua', 'tanpa' => 'Tanpa Wawancara Kedua',
                'tambah_dp' => 'Tambah DP', 'pindah_unit' => 'Pindah Unit', 'plafon_tidak_sesuai' => 'Plafon Tidak Sesuai',
                'tenor_tidak_sesuai' => 'Tenor Tidak Sesuai', 'ganti_nama' => 'Ganti Nama Nasabah', 'lain_lain' => 'Lain-Lain',
                default => (string) $v,
            },
        };
    }

    private function rp(mixed $v): string
    {
        $s = number_format((float) $v, 2, ',', '.');

        return 'Rp '.(str_ends_with($s, ',00') ? substr($s, 0, -3) : $s);
    }

    // ── Bantuan tahap ────────────────────────────────────────────────────

    /** Tahap tujuan "Mundur ke Tahap Sebelumnya" (null = tidak bisa mundur). */
    private function tahapSebelumnya(KprApplication $kpr): ?KprStatus
    {
        return match ($kpr->status) {
            KprStatus::Wawancara  => KprStatus::Submitted,
            KprStatus::InputBank  => KprStatus::Wawancara,
            KprStatus::Verifikasi => KprStatus::InputBank,
            KprStatus::Analis     => KprStatus::Verifikasi,
            KprStatus::Banding    => KprStatus::Analis,
            // SP3K kembali ke Banding (bila tadinya banding) atau Analis. Record lama (tanpa
            // process_stages / alur lama) tak pernah lewat tahap baru → mundur ke Submitted.
            KprStatus::Sp3kIssued => (empty($kpr->process_stages)
                ? KprStatus::Submitted
                : ((($kpr->process_stages['analis']['banding'] ?? false)) ? KprStatus::Banding : KprStatus::Analis)),
            KprStatus::Si         => KprStatus::Sp3kIssued,
            KprStatus::AkadDone   => KprStatus::Si,
            default               => null,
        };
    }

    /** Tahap terakhir sebelum dibatalkan; fallback data lama tanpa catatan. */
    private function tahapPulih(KprApplication $kpr): KprStatus
    {
        $resume = $kpr->status_before_cancel !== null ? KprStatus::tryFrom($kpr->status_before_cancel) : null;

        return $resume ?? ($kpr->sp3k()->exists() ? KprStatus::Sp3kIssued : KprStatus::Submitted);
    }

    /** Indeks tahap terjauh yang pernah dicapai (dari status + jejak data). */
    private function furthestNavIndex(KprApplication $kpr): int
    {
        $idx = array_search($kpr->status->value, self::NAV_STAGES, true);
        $idx = $idx === false ? 0 : $idx;
        $ps = $kpr->process_stages ?? [];
        foreach (array_keys(self::PROCESS_STAGES) as $s) {
            if (! empty($ps[$s])) {
                $idx = max($idx, (int) array_search($s, self::NAV_STAGES, true));
            }
        }
        if ($kpr->sp3k()->exists()) {
            $idx = max($idx, (int) array_search('sp3k', self::NAV_STAGES, true));
        }

        return (int) $idx;
    }

    private function bolehTransisi(KprApplication $kpr, KprStatus $to, string $pesan): void
    {
        try {
            $kpr->status->assertCanTransitionTo($to);
        } catch (InvalidStateTransitionException $e) {
            throw new BusinessRuleException($pesan);
        }
    }

    /**
     * Validasi + normalisasi data satu tahap tambahan. Mengembalikan [data bersih, lengkap?, error|null].
     * Field tanggal dinormalkan ke 'Y-m-d' (atau null). "Lengkap" = tanggal selesai (+ field wajib) terisi.
     *
     * @return array{0: array<string,mixed>, 1: bool, 2: ?string}
     */
    public function normalizeProcessStage(string $stage, array $data): array
    {
        $date = function ($v): ?string {
            $v = trim((string) ($v ?? ''));

            return ($v !== '' && strtotime($v) !== false) ? date('Y-m-d', strtotime($v)) : null;
        };
        $text = fn ($v) => (trim((string) ($v ?? '')) !== '') ? trim((string) $v) : null;
        // satu blok tanggal-mulai/catatan/tanggal-selesai; error bila selesai < mulai.
        $block = function (array $d) use ($date, $text): array {
            $start = $date($d['started_at'] ?? null);
            $end = $date($d['finished_at'] ?? null);
            $bad = ($start !== null && $end !== null && $end < $start);

            return [['started_at' => $start, 'notes' => $text($d['notes'] ?? null), 'finished_at' => $end], $end !== null && ! $bad, $bad];
        };

        if ($stage === 'wawancara' || $stage === 'input_bank') {
            [$b, $ok, $bad] = $block($data);
            if ($bad) {
                return [$b, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
            }

            return [$b, $ok, null];
        }

        if ($stage === 'verifikasi') {
            // OTS WAJIB. Wawancara Kedua opsional: bank menentukan lewat wawancara2_mode
            // ('dengan' = harus dijalani, 'tanpa' = dilewati). Mode wajib dipilih sebelum lanjut.
            [$ots, $okO, $badO] = $block((array) ($data['ots'] ?? []));
            [$w2, $okW, $badW] = $block((array) ($data['wawancara2'] ?? []));
            $mode = in_array($data['wawancara2_mode'] ?? null, ['dengan', 'tanpa'], true) ? $data['wawancara2_mode'] : null;

            // Jalur "tanpa": tanggal/catatan wawancara kedua sengaja DIKOSONGKAN agar rekap
            // (timeline, Master Data KPR) tidak terlanjur melaporkan wawancara yang tak pernah ada.
            if ($mode === 'tanpa') {
                $w2 = ['started_at' => null, 'notes' => null, 'finished_at' => null];
                $okW = false;
                $badW = false;
            }

            $clean = ['ots' => $ots, 'wawancara2' => $w2, 'wawancara2_mode' => $mode];
            if ($badO || $badW) {
                return [$clean, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
            }

            $complete = $okO && ($mode === 'tanpa' || ($mode === 'dengan' && $okW));

            return [$clean, $complete, null];
        }

        if ($stage === 'analis') {
            [$b, $ok, $bad] = $block($data);
            $clean = array_merge($b, [
                'berkas_tambahan' => $text($data['berkas_tambahan'] ?? null),
                'banding'         => (bool) ($data['banding'] ?? false),
            ]);
            if ($bad) {
                return [$clean, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
            }

            return [$clean, $ok, null];
        }

        // banding
        $allowedJenis = ['tambah_dp', 'pindah_unit', 'plafon_tidak_sesuai', 'tenor_tidak_sesuai', 'ganti_nama', 'lain_lain'];
        $jenis = in_array($data['jenis'] ?? null, $allowedJenis, true) ? $data['jenis'] : null;
        $jenisLain = $text($data['jenis_lain'] ?? null);
        [$b, $ok, $bad] = $block($data);
        $clean = array_merge($b, [
            'jenis'           => $jenis,
            'jenis_lain'      => $jenisLain,
            'berkas_tambahan' => $text($data['berkas_tambahan'] ?? null),
        ]);
        if ($bad) {
            return [$clean, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
        }
        // Lengkap bila jenis dipilih (+ keterangan bila "Lain-Lain") DAN tanggal selesai terisi.
        $complete = $ok && $jenis !== null && ! ($jenis === 'lain_lain' && $jenisLain === null);

        return [$clean, $complete, null];
    }
}
