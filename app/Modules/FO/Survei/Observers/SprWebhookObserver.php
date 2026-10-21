<?php

namespace App\Modules\FO\Survei\Observers;

use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\Survei\Services\WebhookSurvei;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemicu Webhook Survei — dipasang pada Kontrak, Customer, dan Unit.
 *
 *   Kontrak dibuat                        → spr.created
 *   Kontrak: unit / customer / status     → spr.updated (status jadi BATAL → spr.cancelled)
 *   Kontrak dihapus                       → spr.deleted
 *   Customer: nama berubah                → spr.updated untuk tiap SPR customer itu
 *   Unit: kode unit berubah               → spr.updated untuk tiap SPR di unit itu
 *
 * Observer Eloquent TIDAK terpanggil oleh query massal / insert langsung (migrasi, sinkron
 * eProperty). Sinkron eProperty mengirimnya sendiri sesudah selesai (EpropSinkronCommand).
 */
class SprWebhookObserver
{
    public function created(Model $m): void
    {
        if ($m instanceof Kontrak) {
            WebhookSurvei::antreKontrak($m, 'spr.created');
        }
    }

    public function updated(Model $m): void
    {
        if ($m instanceof Kontrak) {
            if (! $m->wasChanged(['unit_id', 'customer_id', 'status', 'no_kontrak', 'tahap'])) {
                return;   // perubahan kolom lain (harga, cara bayar, …) tidak dikirim
            }
            $batal = $m->wasChanged('status') && strtoupper((string) $m->status) === 'BATAL';
            WebhookSurvei::antreKontrak($m, $batal ? 'spr.cancelled' : 'spr.updated');

            return;
        }

        if ($m instanceof Customer && $m->wasChanged('full_name')) {
            Kontrak::query()->where('customer_id', $m->id)->where('tahap', 'SPR')->get()
                ->each(fn (Kontrak $k) => WebhookSurvei::antreKontrak($k, 'spr.updated'));

            return;
        }

        if ($m instanceof Unit && $m->wasChanged('kode_unit')) {
            Kontrak::query()->where('unit_id', $m->id)->where('tahap', 'SPR')->get()
                ->each(fn (Kontrak $k) => WebhookSurvei::antreKontrak($k, 'spr.updated'));
        }
    }

    public function deleted(Model $m): void
    {
        if ($m instanceof Kontrak && strtoupper((string) $m->tahap) === 'SPR') {
            WebhookSurvei::antre('spr.deleted', ['no_spr' => (string) $m->no_kontrak], now());
        }
    }
}
