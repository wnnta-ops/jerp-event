<?php

namespace App\Modules\FO\Approval\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apakah user FO (dicocokkan ke user BO lewat email) punya tahap di Approval Chain
 * BO Approval Inbox (Memo Urgent, TTD Berkas, Invoice Hutang, Alokasi/Koreksi Budget).
 * Dipakai sebagai gerbang login app approver (PWA) — hak approve per dokumen tetap
 * dicek BO saat aksi (ActOnApproval).
 *
 * Tipe tahap chain:
 *   user     → approver_value = id user BO
 *   role     → approver_value = nama role BO
 *   assigned → Dept Head yang ditunjuk saat pengajuan; lolos selama ia tercatat
 *              sebagai atasan minimal satu karyawan aktif di Employee Center.
 */
class BoChainApproverCheck
{
    /** Sama dengan ApprovalInboxQuery::DOCUMENT_TYPES di BO. */
    public const DOCUMENT_TYPES = ['memo_internal', 'ap_berkas', 'ap_invoice', 'budget_correction', 'budget_allocation'];

    public function isApprover(string $email): bool
    {
        try {
            return $this->cek($this->db(), $email);
        } catch (Throwable $e) {
            // BO tidak terjangkau → anggap bukan approver BO (login FO tetap jalan).
            Log::warning('BoChainApproverCheck gagal: '.$e->getMessage());

            return false;
        }
    }

    private function cek(ConnectionInterface $db, string $email): bool
    {
        $user = $db->table('users')
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->first(['id', 'employee_nip']);
        if (! $user) {
            return false;
        }

        $roles = $db->table('model_has_roles as m')
            ->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.model_id', $user->id)
            ->where('m.model_type', 'user') // morph map BO
            ->pluck('r.name')
            ->all();
        if (in_array('SuperAdmin', $roles, true)) {
            return true;
        }

        $langkah = $db->table('approval_chain_steps as s')
            ->join('approval_chains as c', 'c.id', '=', 's.approval_chain_id')
            ->where('c.is_active', 1)
            ->whereIn('c.document_type', self::DOCUMENT_TYPES);

        $adaUserAtauRole = (clone $langkah)
            ->where(function ($q) use ($user, $roles) {
                $q->where(fn ($w) => $w->where('s.approver_type', 'user')->where('s.approver_value', (string) $user->id));
                if ($roles) {
                    $q->orWhere(fn ($w) => $w->where('s.approver_type', 'role')->whereIn('s.approver_value', $roles));
                }
            })
            ->exists();
        if ($adaUserAtauRole) {
            return true;
        }

        return $user->employee_nip
            && (clone $langkah)->where('s.approver_type', 'assigned')->exists()
            && $this->punyaBawahan($db, (string) $user->employee_nip);
    }

    private function punyaBawahan(ConnectionInterface $db, string $nik): bool
    {
        $atasanId = $db->table('bs_employees')->where('nik', $nik)->whereNull('deleted_at')->value('id');

        return $atasanId !== null && $db->table('bs_employees')
            ->where('atasan_id', $atasanId)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->exists();
    }

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('ipro.connection', 'ipro_bo'));
    }
}
