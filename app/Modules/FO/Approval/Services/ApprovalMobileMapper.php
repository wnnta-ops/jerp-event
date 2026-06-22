<?php

namespace App\Modules\FO\Approval\Services;

use App\Filament\Fo\Resources\ApprovalResource;
use App\Modules\FO\Approval\Models\Approval;

/**
 * Petakan Approval (J-ERP) → bentuk data yang dipakai aplikasi mobile approver
 * (Expo/React Native). Kontrak shape mengikuti src/data/types.ts di app:
 * RequestItem, HistoryEntry, PipeStep. Semua jenis approval didukung.
 */
class ApprovalMobileMapper
{
    /** Label manusiawi tiap jenis. */
    private const JENIS_LABEL = [
        'DISKON' => 'Diskon Harga',
        'DISKON_SPRS' => 'Diskon SPRS',
        'BATAL_KONTRAK' => 'Batal Kontrak',
        'ADJ_HARGA' => 'Adjust Harga',
        'RESCHEDULE' => 'Reschedule Angsuran',
        'GANTI_NAMA' => 'Ganti Nama / Pengalihan Hak',
        'GANTI_UNIT' => 'Ganti Unit / Pindah Unit',
        'REFUND' => 'Refund',
        'BLT' => 'Pengajuan BLT',
        'KOMISI' => 'Pengajuan Komisi',
        'LAIN' => 'Lainnya',
    ];

    /** Jenis ber-nominal → tampil sebagai FUND; sisanya DOC. */
    private const FUND_JENIS = ['DISKON', 'DISKON_SPRS', 'BATAL_KONTRAK', 'ADJ_HARGA', 'REFUND', 'BLT', 'KOMISI'];

    /** SLA target (jam) sejak diajukan untuk hitung sisa waktu. */
    private const SLA_HOURS = 72;

    /** RequestItem (kontrak app) untuk satu approval. */
    public function toRequestItem(Approval $a): array
    {
        $jenis = (string) $a->jenis;
        $isFund = in_array($jenis, self::FUND_JENIS, true);
        $amount = (float) $a->amount_dampak;
        $label = self::JENIS_LABEL[$jenis] ?? $jenis;
        $single = ApprovalService::isSingleLevel($jenis);

        $sla = $this->sla($a);
        $sub = ApprovalResource::subjectLabel($a);

        $item = [
            'id' => (string) $a->id,
            'type' => $isFund ? 'FUND' : 'DOC',
            'reqName' => $label,
            'tier' => $this->tier($amount),
            'tag' => $single ? 'GM' : ((int) $a->current_level === 1 ? 'L1' : 'FINAL'),
            'slaMin' => $sla['min'],
            'slc' => $sla['class'],
            'requester' => $a->requester?->name ?: ($a->requester?->full_name ?: '—'),
            'dept' => $this->dept($a),
            'sub' => $sub ?: ($a->alasan ?: '—'),
            'forward' => $this->forward($a, $single),
            'chain' => $this->buildChain($a, $single),
            'docs' => [], // approval J-ERP belum punya lampiran dokumen
        ];

        if ($isFund) {
            $item['amount'] = 'Rp '.number_format($amount, 0, ',', '.');
            $item['purpose'] = $label;
            $item['desc'] = $a->alasan ?: $label;
        } else {
            $item['title'] = $label;
            $item['access'] = $sub ?: '—';
            $item['cls'] = 'INTERNAL';
            $item['desc'] = $a->alasan ?: $label;
        }

        return $item;
    }

    /** HistoryEntry untuk approval yang sudah diputuskan (EXECUTED/APPROVED/REJECTED). */
    public function toHistoryEntry(Approval $a): array
    {
        $jenis = (string) $a->jenis;
        $isFund = in_array($jenis, self::FUND_JENIS, true);
        $label = self::JENIS_LABEL[$jenis] ?? $jenis;
        $rejected = $a->status === 'REJECTED';

        return [
            'id' => (string) $a->id,
            'type' => $isFund ? 'FUND' : 'DOC',
            'label' => $isFund ? 'Rp '.number_format((float) $a->amount_dampak, 0, ',', '.') : $label,
            'who' => ($a->requester?->name ?: '—').' · '.$this->dept($a),
            'dec' => $rejected ? 'rejected' : 'approved',
            'time' => optional($a->approved_at ?? $a->executed_at ?? $a->updated_at)->diffForHumans() ?? '—',
            'reason' => $rejected ? ($a->catatan_approver ?: $a->level1_note) : null,
            'request' => $this->toRequestItem($a),
        ];
    }

    /** PipeStep[] tracker rantai approval. */
    public function buildChain(Approval $a, ?bool $single = null): array
    {
        $single ??= ApprovalService::isSingleLevel((string) $a->jenis);
        $status = (string) $a->status;
        $level = (int) $a->current_level;
        $rejected = $status === 'REJECTED';
        $done = in_array($status, ['APPROVED', 'EXECUTED'], true);

        $steps = [[
            'title' => 'Pengajuan',
            'detail' => $a->requester?->name ?: 'Diajukan',
            'state' => 'done',
        ]];

        if ($single) {
            $steps[] = [
                'title' => 'GM',
                'detail' => $rejected ? 'Ditolak GM' : ($done ? ('Disetujui'.($a->approver ? ' · '.$a->approver->name : '')) : 'Menunggu persetujuan GM'),
                'state' => ($done || $rejected) ? 'done' : 'now',
            ];

            return $steps;
        }

        // 2-level
        $l1Done = $done || $level >= 2 || ($status === 'PENDING' && $level === 2);
        $steps[] = [
            'title' => 'Level 1',
            'detail' => $a->level1Approver?->name
                ? ('Endorse · '.$a->level1Approver->name)
                : ($status === 'PENDING' && $level === 1 ? 'Menunggu endorse L1' : 'Endorse Level 1'),
            'state' => $rejected && $level === 1 ? 'done' : ($l1Done ? 'done' : ($status === 'PENDING' && $level === 1 ? 'now' : 'wait')),
        ];
        $steps[] = [
            'title' => 'Level 2 — Final',
            'detail' => $rejected
                ? 'Ditolak'
                : ($done ? ('Disetujui'.($a->approver ? ' · '.$a->approver->name : '')) : ($status === 'PENDING' && $level === 2 ? 'Menunggu persetujuan final' : 'Persetujuan final')),
            'state' => $done ? 'done' : ($rejected && $level === 2 ? 'done' : ($status === 'PENDING' && $level === 2 ? 'now' : 'wait')),
        ];

        return $steps;
    }

    private function forward(Approval $a, bool $single): ?string
    {
        if ($single || $a->status !== 'PENDING') {
            return null;
        }

        return (int) $a->current_level === 1 ? 'Level 2 — Final' : null;
    }

    private function tier(float $amount): string
    {
        $amount = abs($amount);
        if ($amount >= 100_000_000) {
            return 't1';
        }
        if ($amount >= 25_000_000) {
            return 't2';
        }

        return 't3';
    }

    /** @return array{min:int|null,class:string} */
    private function sla(Approval $a): array
    {
        $start = $a->tgl_approval ?? $a->created_at;
        if (! $start) {
            return ['min' => null, 'class' => 'norm'];
        }
        $deadline = $start->copy()->addHours(self::SLA_HOURS);
        $remaining = (int) round(now()->diffInMinutes($deadline, false));
        $class = $remaining <= 24 * 60 ? 'red' : ($remaining <= 48 * 60 ? 'amb' : 'norm');

        return ['min' => max(0, $remaining), 'class' => $class];
    }

    private function dept(Approval $a): string
    {
        $role = $a->requester?->getRoleNames()->first();

        return $role ? ucwords(str_replace('_', ' ', $role)) : 'Front Office';
    }
}
