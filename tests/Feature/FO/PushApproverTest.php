<?php

namespace Tests\Feature\FO;

use App\Models\User;
use App\Modules\FO\Approval\Models\PushSubscription;
use App\Modules\FO\Approval\Services\BoInboxBridge;
use App\Modules\FO\Approval\Services\PushApprover;
use App\Modules\FO\Approval\Services\PushPengirim;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notifikasi push app approver: PushApprover (giliran / ajuan / harian, jejak) dan API langganan.
 * BO & pengirim Web Push dipalsukan. Hanya tabel users + migrasi push yang dibuat (SQLite).
 */
class PushApproverTest extends TestCase
{
    /** @var array{perlu: array<int, array<string,mixed>>, ajuan: array<int, array<string,mixed>>, status: int} */
    private array $bo = ['perlu' => [], 'ajuan' => [], 'status' => 200];

    /** @var list<array{sub:int, pesan:array<string,mixed>}> */
    private array $terkirim = [];

    /** @var list<int> id langganan yang dianggap kedaluwarsa oleh layanan push */
    private array $kedaluwarsa = [];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email');
            $t->string('password')->nullable();
            $t->timestamps();
        });
        (include database_path('migrations/2026_11_05_100000_create_fo_push_tables.php'))->up();

        $uji = $this;
        $this->app->instance(BoInboxBridge::class, new class($uji) extends BoInboxBridge
        {
            public function __construct(private PushApproverTest $t) {}

            public function get(string $email, string $path = ''): array
            {
                return $this->t->jawabBo($path);
            }
        });
        $this->app->instance(PushPengirim::class, new class($uji) extends PushPengirim
        {
            public function __construct(private PushApproverTest $t) {}

            public function siap(): bool
            {
                return true;
            }

            public function kirim(PushSubscription $s, array $pesan): bool
            {
                return $this->t->catatKirim($s, $pesan);
            }
        });
    }

    public function jawabBo(string $path): array
    {
        return ['status' => $this->bo['status'], 'body' => ['data' => $path === '/pengajuan' ? $this->bo['ajuan'] : $this->bo['perlu']]];
    }

    public function catatKirim(PushSubscription $s, array $pesan): bool
    {
        if (in_array($s->id, $this->kedaluwarsa, true)) {
            return false;
        }
        $this->terkirim[] = ['sub' => $s->id, 'pesan' => $pesan];

        return true;
    }

    private function user(string $email = 'gm@jerp.test'): User
    {
        return User::withoutEvents(fn () => User::query()->findOrFail(DB::table('users')->insertGetId(['email' => $email, 'name' => $email])));
    }

    private function langganan(User $u, array $attrs = []): PushSubscription
    {
        $ep = 'https://push.test/'.uniqid();

        return PushSubscription::create($attrs + [
            'user_id' => $u->id, 'endpoint' => $ep, 'endpoint_hash' => PushSubscription::hashEndpoint($ep),
            'p256dh' => 'p', 'auth' => 'a', 'harian' => false,
        ]);
    }

    private function dok(int $id, string $kategori = 'reguler', string $status = 'pending'): array
    {
        return ['id' => $id, 'type' => 'Invoice Hutang', 'kategori' => $kategori, 'number' => "INV-{$id}", 'subject' => 'Invoice PT Beton', 'amount' => 1500000, 'status' => $status];
    }

    private function jalan(string $waktu = '2026-11-05 10:00'): void
    {
        app(PushApprover::class)->jalankan(Carbon::parse($waktu));
    }

    public function test_putaran_pertama_hanya_mencatat_jejak(): void
    {
        $this->langganan($this->user());
        $this->bo['perlu'] = [$this->dok(1), $this->dok(2)];

        $this->jalan();

        $this->assertSame([], $this->terkirim);
        $this->assertSame([1, 2], json_decode(DB::table('fo_push_jejak')->value('perlu_ids'), true));
    }

    public function test_giliran_baru_dikirim_hanya_ke_perangkat_yang_memilihnya(): void
    {
        $u = $this->user();
        $mau = $this->langganan($u);
        $this->langganan($u, ['giliran' => false]);
        $this->bo['perlu'] = [$this->dok(1)];
        $this->jalan();

        $this->bo['perlu'] = [$this->dok(1), $this->dok(5, 'urgent')];
        $this->jalan();

        $this->assertCount(1, $this->terkirim);
        $this->assertSame($mau->id, $this->terkirim[0]['sub']);
        $this->assertSame('URGENT · Perlu persetujuan', $this->terkirim[0]['pesan']['title']);
        $this->assertSame('INV-5 — Invoice PT Beton · Rp 1.500.000', $this->terkirim[0]['pesan']['body']);
        $this->assertSame('./?buka=5', $this->terkirim[0]['pesan']['url']);

        $this->jalan();
        $this->assertCount(1, $this->terkirim, 'dokumen yang sama tidak dikirim dua kali');
    }

    public function test_banyak_dokumen_baru_jadi_satu_ringkasan(): void
    {
        $this->langganan($this->user());
        $this->jalan();
        $this->bo['perlu'] = [$this->dok(1, 'urgent'), $this->dok(2), $this->dok(3), $this->dok(4)];

        $this->jalan();

        $this->assertCount(1, $this->terkirim);
        $this->assertSame('4 dokumen baru menunggu Anda', $this->terkirim[0]['pesan']['title']);
        $this->assertSame('1 di antaranya urgent.', $this->terkirim[0]['pesan']['body']);
    }

    public function test_ajuan_saya_diputuskan(): void
    {
        $this->langganan($this->user());
        $this->bo['ajuan'] = [$this->dok(9, status: 'in_review'), $this->dok(10, status: 'pending')];
        $this->jalan();

        $this->bo['ajuan'] = [$this->dok(9, status: 'approved'), $this->dok(10, status: 'rejected')];
        $this->jalan();

        $this->assertSame(['Ajuan Anda disetujui', 'Ajuan Anda ditolak'], array_map(fn ($k) => $k['pesan']['title'], $this->terkirim));
    }

    public function test_pengingat_harian_sekali_sehari_setelah_jamnya(): void
    {
        $s = $this->langganan($this->user(), ['harian' => true, 'giliran' => false, 'jam_harian' => '08:00']);
        $this->bo['perlu'] = [$this->dok(1, 'urgent'), $this->dok(2)];

        $this->jalan('2026-11-05 07:59');
        $this->assertSame([], $this->terkirim);

        $this->jalan('2026-11-05 08:00');
        $this->jalan('2026-11-05 15:00');
        $this->assertCount(1, $this->terkirim);
        $this->assertSame('2 dokumen menunggu Anda (1 urgent).', $this->terkirim[0]['pesan']['body']);

        $this->jalan('2026-11-06 08:01');
        $this->assertCount(2, $this->terkirim);
        $this->assertSame('2026-11-06', $s->fresh()->harian_terakhir->toDateString());
    }

    public function test_langganan_kedaluwarsa_dihapus(): void
    {
        $s = $this->langganan($this->user());
        $this->jalan();
        $this->kedaluwarsa = [$s->id];
        $this->bo['perlu'] = [$this->dok(1)];

        $this->jalan();

        $this->assertNull(PushSubscription::find($s->id));
    }

    public function test_bo_gagal_jejak_tidak_berubah(): void
    {
        $this->langganan($this->user());
        $this->bo['perlu'] = [$this->dok(1)];
        $this->jalan();

        $this->bo = ['perlu' => [], 'ajuan' => [], 'status' => 503];
        $this->jalan();
        $this->bo = ['perlu' => [$this->dok(1), $this->dok(2)], 'ajuan' => [], 'status' => 200];
        $this->jalan();

        $this->assertSame(['./?buka=2'], array_map(fn ($k) => $k['pesan']['url'], $this->terkirim));
    }

    // ── API langganan ───────────────────────────────────────────────────────────

    private function body(string $ep = 'https://push.test/hp-1'): array
    {
        return ['endpoint' => $ep, 'keys' => ['p256dh' => 'pk', 'auth' => 'au'], 'perangkat' => 'PWA · Android'];
    }

    public function test_subscribe_status_ubah_pilihan_dan_berhenti(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u, ['approval:act']);

        $this->postJson('/api/mobile/push/subscribe', $this->body() + ['harian' => false])
            ->assertOk()->assertJsonPath('data.harian', false)->assertJsonPath('data.giliran', true);
        $this->postJson('/api/mobile/push/status', ['endpoint' => 'https://push.test/hp-1'])->assertJsonPath('data.ajuan', true);

        $this->putJson('/api/mobile/push/subscription', ['endpoint' => 'https://push.test/hp-1', 'ajuan' => false, 'jam_harian' => '07:30'])
            ->assertOk()->assertJsonPath('data.ajuan', false)->assertJsonPath('data.jam_harian', '07:30');
        $this->putJson('/api/mobile/push/subscription', ['endpoint' => 'https://push.test/hp-1', 'jam_harian' => '25:00'])->assertStatus(422);

        $this->postJson('/api/mobile/push/unsubscribe', ['endpoint' => 'https://push.test/hp-1'])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_endpoint_sama_pindah_ke_user_yang_login_dan_tidak_bisa_diubah_orang_lain(): void
    {
        $a = $this->user('a@jerp.test');
        $b = $this->user('b@jerp.test');

        Sanctum::actingAs($a, ['approval:act']);
        $this->postJson('/api/mobile/push/subscribe', $this->body())->assertOk();

        Sanctum::actingAs($b, ['approval:act']);
        $this->postJson('/api/mobile/push/unsubscribe', ['endpoint' => 'https://push.test/hp-1'])->assertOk();
        $this->assertSame($a->id, PushSubscription::first()->user_id, 'B tidak bisa menghapus langganan A');
        $this->postJson('/api/mobile/push/uji', ['endpoint' => 'https://push.test/hp-1'])->assertNotFound();

        $this->postJson('/api/mobile/push/subscribe', $this->body())->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($b->id, PushSubscription::first()->user_id);
    }

    public function test_kirim_uji_ke_perangkat_ini(): void
    {
        Sanctum::actingAs($this->user(), ['approval:act']);
        $this->postJson('/api/mobile/push/subscribe', $this->body())->assertOk();

        $this->postJson('/api/mobile/push/uji', ['endpoint' => 'https://push.test/hp-1'])->assertOk();

        $this->assertSame('Notifikasi uji J-ERP', $this->terkirim[0]['pesan']['title']);
    }

    public function test_perangkat_dikeluarkan_langganannya_ikut_terhapus(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        $u = $this->user();
        $hp = $u->createToken('HP', ['approval:act'])->plainTextToken;
        $laptop = $u->createToken('Laptop', ['approval:act'])->plainTextToken;
        $this->withToken($hp)->postJson('/api/mobile/push/subscribe', $this->body('https://push.test/hp'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->postJson('/api/mobile/push/subscribe', $this->body('https://push.test/laptop'))->assertOk();
        $this->assertSame(2, PushSubscription::count());

        // Dari HP, keluarkan laptop → langganan laptop hilang.
        $this->app['auth']->forgetGuards();
        $idLaptop = DB::table('personal_access_tokens')->where('name', 'Laptop')->value('id');
        $this->withToken($hp)->deleteJson("/api/mobile/auth/sessions/{$idLaptop}")->assertOk();
        $this->assertSame(['https://push.test/hp'], PushSubscription::pluck('endpoint')->all());

        // Logout di HP → langganan HP hilang.
        $this->app['auth']->forgetGuards();
        $this->withToken($hp)->postJson('/api/mobile/auth/logout')->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }
}
