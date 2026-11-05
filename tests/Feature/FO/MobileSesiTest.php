<?php

namespace Tests\Feature\FO;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Perangkat & sesi app approver (/api/mobile/auth/sessions, /auth/logout-all) memakai token
 * Bearer sungguhan. Migrasi FO penuh tidak jalan di SQLite, jadi hanya tabel yang perlu yang dibuat.
 */
class MobileSesiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email');
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
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
        // Mencabut perangkat juga menghapus langganan push-nya.
        (include database_path('migrations/2026_11_05_100000_create_fo_push_tables.php'))->up();
    }

    private function user(string $email): User
    {
        return User::withoutEvents(fn () => User::query()->findOrFail(DB::table('users')->insertGetId(['email' => $email, 'name' => $email])));
    }

    private function token(User $u, string $perangkat): string
    {
        return $u->createToken($perangkat, ['approval:act'])->plainTextToken;
    }

    public function test_daftar_hanya_perangkat_milik_sendiri_dan_tandai_yang_ini(): void
    {
        $gm = $this->user('gm@jerp.test');
        $lain = $this->user('lain@jerp.test');
        $hp = $this->token($gm, 'PWA · Android');
        $this->token($gm, 'PWA · iOS');
        $this->token($lain, 'PWA · Windows');

        $res = $this->withToken($hp)->getJson('/api/mobile/auth/sessions')->assertOk();

        $this->assertEqualsCanonicalizing(['PWA · Android', 'PWA · iOS'], collect($res->json('data'))->pluck('perangkat')->all());
        $this->assertSame('PWA · Android', collect($res->json('data'))->firstWhere('ini', true)['perangkat']);
    }

    public function test_keluarkan_perangkat_lain_tapi_bukan_milik_orang(): void
    {
        $gm = $this->user('gm@jerp.test');
        $lain = $this->user('lain@jerp.test');
        $hp = $this->token($gm, 'HP');
        $this->token($gm, 'Laptop');
        $this->token($lain, 'Punya orang');
        $idLaptop = DB::table('personal_access_tokens')->where('name', 'Laptop')->value('id');
        $idOrang = DB::table('personal_access_tokens')->where('name', 'Punya orang')->value('id');
        $idHp = DB::table('personal_access_tokens')->where('name', 'HP')->value('id');

        $this->withToken($hp)->deleteJson("/api/mobile/auth/sessions/{$idOrang}")->assertNotFound();
        $this->withToken($hp)->deleteJson("/api/mobile/auth/sessions/{$idHp}")->assertStatus(422);
        $this->withToken($hp)->deleteJson("/api/mobile/auth/sessions/{$idLaptop}")->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $idLaptop]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $idOrang]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $idHp]);
    }

    public function test_keluar_dari_semua_perangkat(): void
    {
        $gm = $this->user('gm@jerp.test');
        $lain = $this->user('lain@jerp.test');
        $hp = $this->token($gm, 'HP');
        $this->token($gm, 'Laptop');
        $this->token($lain, 'Punya orang');

        $this->withToken($hp)->postJson('/api/mobile/auth/logout-all')->assertOk()->assertJsonPath('jumlah', 2);

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $gm->id)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($hp)->getJson('/api/mobile/auth/sessions')->assertUnauthorized();
    }
}
