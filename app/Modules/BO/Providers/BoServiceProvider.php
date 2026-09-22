<?php

namespace App\Modules\BO\Providers;

use App\Modules\BO\FoBoBridge\Listeners\PostFoEventToGl;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Services\JurnalPostingService;
use App\Models\User;
use App\Modules\BO\IproBridge\Jobs\SyncFoToIproJob;
use App\Modules\BO\IproBridge\Services\IproMasterSync;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\KprPengajuan;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the BO (Back Office / Accounting) module:
 * binds the GL posting service and registers the FO→BO event subscriber.
 */
class BoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JurnalPostingServiceInterface::class, JurnalPostingService::class);
        $this->app->singleton(IproMasterSync::class);
    }

    public function boot(): void
    {
        // (DIPENSIUNKAN) FOBO bridge ke GL J-ERP lama (bo_jurnal). GL kini ke ipro-fo.
        // Event::listen(FoFinancialEvent::class, PostFoEventToGl::class);

        // FO → GL ipro-fo (ipro_bo.bs_journals). BO ipro-fo = back office baru.
        if (config('ipro.gl_enabled', true)) {
            Event::listen(FoFinancialEvent::class, \App\Modules\BO\IproBridge\Listeners\PostFoEventToIproGl::class);
        }

        // Sync OTOMATIS master FO → ipro_bo (BO ipro-fo). Saat data FO disimpan,
        // langsung ditulis ke ipro_bo. Dibungkus try/catch: kegagalan sync TIDAK
        // boleh menggagalkan simpan FO (backfill `ipro:backfill` merekonsiliasi).
        if (config('ipro.sync_enabled', true)) {
            $this->wireIproSync();
        }
    }

    private function wireIproSync(): void
    {
        // Sync ASINKRON: dispatch job SETELAH transaksi FO commit (afterCommit) →
        // tak menambah latensi simpan, retry + outbox bila gagal. QUEUE=sync pun aman.
        $dispatch = function (string $method, $model, bool $withTrashed = false): void {
            SyncFoToIproJob::dispatch($method, $model::class, $model->getKey(), $withTrashed)->afterCommit();
        };

        // Master Lokasi (Setup Unit) = "cluster" di BO. Disinkronkan SENDIRI, bukan lagi
        // sebagai efek samping sinkron unit: Lokasi yang belum punya satu unit pun tetap harus
        // muncul sebagai pilihan Cluster di Setup Kategori Gimmick, persis seperti ia muncul
        // di FO. Selama ia menumpang syncUnit(), sebuah cluster baru tidak akan pernah lahir
        // sampai ada unit pertama yang diunggah ke sana.
        //
        // Ketiga peristiwa diperlukan: runSoftDelete() TIDAK memicu `saved`, jadi tanpa
        // `deleted` sebuah Lokasi yang dinonaktifkan di FO akan terus ditawarkan di BO.
        Lokasi::saved(fn ($m) => $dispatch('syncLokasi', $m));
        Lokasi::deleted(fn ($m) => $dispatch('syncLokasi', $m, true));
        Lokasi::restored(fn ($m) => $dispatch('syncLokasi', $m, true));

        Customer::saved(fn ($m) => $dispatch('syncCustomer', $m));
        Unit::saved(fn ($m) => $dispatch('syncUnit', $m));
        Kontrak::saved(fn ($m) => $dispatch('syncSpr', $m));

        // ── Pembayaran (Kwitansi & baris tagihan) → sinkron ulang jadwal SPR-nya ──
        //
        // Tanpa kedua hook ini pembayaran TIDAK PERNAH sampai ke BO. Menerima pembayaran
        // hanya menyentuh KontrakTagihan (pelunasan) lalu membuat Bkm — baris Kontrak-nya
        // sendiri tak ikut tersimpan, padahal satu-satunya pemicu syncSpr adalah
        // Kontrak::saved. Akibatnya kolom Collection Cash / UTJ & DP di Master Data KPR
        // membeku pada nilai saat SPR terakhir kali disimpan (umumnya nol), dan pembatalan
        // kwitansi pun tak pernah tercermin. Penjadwal juga tak menolong: `ipro:backfill`
        // yang berjalan tiap jam hanya mengerjakan entitas `lokasi`, bukan `spr`.
        //
        // Yang di-dispatch adalah KONTRAK-nya, bukan model yang berubah: syncSpr memang
        // bekerja pada kontrak, dan job memuat ulang barisnya sendiri sehingga selalu
        // membaca tagihan & kwitansi terbaru.
        $syncKontrak = function ($kontrakId): void {
            if ($kontrakId) {
                SyncFoToIproJob::dispatch('syncSpr', Kontrak::class, (int) $kontrakId)->afterCommit();
            }
        };

        // Dipasang pada `created`/`updated` TERPISAH, bukan `saved` + penanda
        // wasRecentlyCreated: penanda itu bertahan sepanjang umur instance modelnya, jadi
        // penyimpanan BERIKUTNYA atas objek yang sama masih mengaku "baru dibuat" dan
        // penyaring apa pun yang bersandar padanya meleset ke dua arah sekaligus.
        Bkm::created(fn ($m) => $syncKontrak($m->kontrak_id));
        Bkm::updated(function ($m) use ($syncKontrak) {
            // `bo_posted_at` WAJIB ikut: justru stempel itulah yang membuat sebuah kwitansi
            // memenuhi syarat "Terkirim", dan tombol "Posting BO" di daftar Kwitansi tidak
            // mengubah kolom lain apa pun.
            if ($m->wasChanged(['status', 'bo_posted_at', 'amount', 'tgl_bkm', 'kontrak_tagihan_id', 'kontrak_id'])) {
                $syncKontrak($m->kontrak_id);
            }
        });

        // Baris tagihan: HANYA saat pelunasannya berubah (pembayaran & pembatalan).
        // Pembuatannya sengaja TIDAK dipasang — baris tagihan lahir massal saat jadwal
        // digenerate, dan itu sudah diikuti Kontrak::saved; satu job per baris hanya
        // mengulang pekerjaan yang sama belasan kali untuk satu SPR.
        KontrakTagihan::updated(function ($m) use ($syncKontrak) {
            if ($m->wasChanged('pelunasan')) {
                $syncKontrak($m->kontrak_id);
            }
        });
        Tts::saved(fn ($m) => $dispatch('syncTts', $m));
        KprPengajuan::saved(fn ($m) => $dispatch('syncKpr', $m));
        // Transfer Anonim → tts_receipts TANPA UNIT (muncul di BO Approval UTJ&DP "Tanpa Unit").
        TransferAnonim::saved(fn ($m) => $dispatch('syncTransferAnonim', $m));

        // Provisioning pengguna FO (jerp.users) → ipro_bo.users BY EMAIL agar SSO FO→BO
        // cocok. Hanya saat user baru / identitas berubah (hindari sync tiap kali login
        // memperbarui last_login_at). Bisa dimatikan lewat config ipro.user_sync_enabled.
        if (config('ipro.user_sync_enabled', true)) {
            User::saved(function ($m) use ($dispatch) {
                // Lewati update yang HANYA mencatat login agar tak sync tiap login.
                // Perubahan role FO tak mengubah kolom users → getChanges() kosong →
                // TIDAK login-only → tetap tersinkron (job reload user, baca role terbaru).
                $changed = array_keys($m->getChanges());
                $loginOnly = $changed !== [] && ! array_diff($changed, [
                    'last_login_at', 'last_login_ip', 'failed_attempts', 'locked_until', 'remember_token', 'updated_at',
                ]);
                if ($loginOnly) {
                    return;
                }
                $dispatch('syncUser', $m);
            });
        }
    }
}
