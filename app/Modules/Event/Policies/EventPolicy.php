<?php

namespace App\Modules\Event\Policies;

use App\Models\User;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtScanPoint;
use App\Support\Akses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aturan akses modul Event.
 *
 * - Superadmin = peran di config('akses.peran_super'), aturan yang sama dengan
 *   seluruh J-ERP (Akses::peranSuper): selalu lolos.
 * - Selain itu, hak per event datang dari et_access. Ini hak tingkat DATA (event
 *   mana, pos mana), jadi tidak ikut dilonggarkan MODE LAPOR — sama seperti
 *   tenant scope tidak dilonggarkan.
 * - Gerbang panel & menu tetap lewat Akses::boleh (config/akses.php) sehingga
 *   sakelar akses.tegakkan berlaku seperti panel lain.
 */
class EventPolicy
{
    public function isSuper(?User $user): bool
    {
        return $user instanceof User && Akses::peranSuper($user);
    }

    /** Buat / ubah / buka / tutup event & atur akses. */
    public function canManage(?User $user): bool
    {
        return $this->isSuper($user);
    }

    /** Boleh scan di titik ini? (status event diperiksa terpisah oleh ScanService.) */
    public function canScanAt(?User $user, EtScanPoint $scanPoint): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ($this->isSuper($user)) {
            return true;
        }

        $eventId = $scanPoint->station?->event_id;

        return $eventId !== null && EtAccess::query()
            ->where('event_id', $eventId)
            ->where('user_id', $user->id)
            ->where('scan_point_id', $scanPoint->id)
            ->exists();
    }

    public function canLiveBoard(?User $user, EtEvent $event): bool
    {
        return $this->isSuper($user) || $this->punyaFlag($user, $event, 'can_live_board');
    }

    public function canReports(?User $user, EtEvent $event): bool
    {
        return $this->isSuper($user) || $this->punyaFlag($user, $event, 'can_reports');
    }

    /** Koreksi kunjungan di live board: superadmin, atau koor lap (can_live_board). */
    public function canCorrect(?User $user, EtEvent $event): bool
    {
        return $this->canLiveBoard($user, $event);
    }

    /**
     * Event yang terlihat oleh user (superadmin: semua).
     *
     * @param  Builder<EtEvent>  $query
     * @return Builder<EtEvent>
     */
    public function scopeVisible(Builder $query, ?User $user): Builder
    {
        if ($this->isSuper($user)) {
            return $query;
        }

        return $query->whereIn('id', EtAccess::query()
            ->select('event_id')
            ->where('user_id', $user?->id ?? 0));
    }

    /**
     * Titik scan yang boleh dipakai user di event OPEN.
     *
     * @return Collection<int, EtScanPoint>
     */
    public function scanPointsFor(User $user, EtEvent $event): Collection
    {
        $q = EtScanPoint::query()
            ->with('station')
            ->whereHas('station', fn ($s) => $s->where('event_id', $event->id));

        if (! $this->isSuper($user)) {
            $q->whereIn('id', EtAccess::query()->select('scan_point_id')
                ->where('event_id', $event->id)->where('user_id', $user->id)->whereNotNull('scan_point_id'));
        }

        return $q->get()->sortBy(fn (EtScanPoint $p) => [$p->station->sequence, $p->kind === EtScanPoint::KELUAR ? 1 : 0])->values();
    }

    /**
     * Event OPEN yang punya titik scan untuk user ini (untuk halaman scanner).
     *
     * @return Collection<int, EtEvent>
     */
    public function scannerEvents(User $user): Collection
    {
        $q = EtEvent::query()->where('status', EtEvent::OPEN)->orderByDesc('event_date');
        if (! $this->isSuper($user)) {
            $q->whereIn('id', EtAccess::query()->select('event_id')
                ->where('user_id', $user->id)->whereNotNull('scan_point_id'));
        }

        return $q->get();
    }

    /**
     * Untuk daftar di halaman scanner: event OPEN (bisa dipilih) + DRAFT (tampil
     * sebagai "belum aktif"). Event yang sudah ditutup tidak ditampilkan.
     *
     * @return Collection<int, EtEvent>
     */
    public function scannerEventsTermasukDraft(User $user): Collection
    {
        $q = EtEvent::query()->whereIn('status', [EtEvent::OPEN, EtEvent::DRAFT])
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('event_date');
        if (! $this->isSuper($user)) {
            $q->whereIn('id', EtAccess::query()->select('event_id')
                ->where('user_id', $user->id)->whereNotNull('scan_point_id'));
        }

        return $q->get();
    }

    private function punyaFlag(?User $user, EtEvent $event, string $flag): bool
    {
        return $user instanceof User && EtAccess::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where($flag, true)
            ->exists();
    }
}
