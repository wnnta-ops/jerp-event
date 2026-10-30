<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Services;

use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifikasi in-app (lonceng topbar) untuk Approval Inbox & Upload Berkas.
 *
 * Ditulis langsung ke tabel `notifications` (bentuk data sama dengan SendNotificationJob:
 * subject, body, payload.url) supaya muncul seketika tanpa menunggu worker outbox.
 */
final class InboxNotifier
{
    /** @param iterable<User> $users */
    public function notify(iterable $users, string $event, string $subject, string $body, ?string $url): void
    {
        $rows = [];
        foreach (collect($users)->filter()->unique('id') as $u) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => $event,
                'notifiable_type' => $u->getMorphClass(),
                'notifiable_id' => $u->id,
                'data' => json_encode(['subject' => $subject, 'body' => $body, 'payload' => ['url' => $url]]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::table('notifications')->insert($rows);
        }
    }

    /** User yang berhak bertindak pada langkah yang sedang menunggu. */
    public function nextApprovers(Approval $approval): Collection
    {
        $step = $approval->steps()->where('status', 'pending')->orderBy('level')->first();
        if (! $step) {
            return collect();
        }
        $chainStep = ApprovalChainStep::query()
            ->where('approval_chain_id', $approval->approval_chain_id)->where('level', $step->level)->first();

        return match ($chainStep?->approver_type) {
            'role' => $this->usersWithRole((string) $chainStep->approver_value),
            'user' => User::query()->whereKey((int) $chainStep->approver_value)->get(),
            'assigned' => User::query()->whereKey((int) $step->approver_id)->get(),
            default => collect(),
        };
    }

    public function usersWithRole(string $role): Collection
    {
        return User::role($role)->where('is_active', true)->get();
    }

    /** @return Collection<int, User> */
    public function usersWithPermission(string $permission): Collection
    {
        return User::permission($permission)->where('is_active', true)->get();
    }
}
