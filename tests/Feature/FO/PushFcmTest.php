<?php

namespace Tests\Feature\FO;

use App\Models\User;
use App\Modules\FO\Approval\Models\PushSubscription;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notifikasi APK Android lewat FCM: langganan "fcm:<token>" tanpa kunci Web Push, token OAuth
 * dari service account (JWT), kirim ke FCM HTTP v1, token tak berlaku → langganan dihapus.
 * Google dipalsukan dengan Http::fake; service account memakai kunci RSA buatan test.
 */
class PushFcmTest extends TestCase
{
    private const TOKEN = 'fcm:token-hp-android-0123456789';

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

        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $kunci);
        config(['push.fcm.credentials_b64' => base64_encode((string) json_encode([
            'type' => 'service_account', 'project_id' => 'jerp-uji', 'client_email' => 'fcm@jerp-uji.iam.gserviceaccount.com',
            'private_key' => $kunci, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ])), 'push.vapid.public_key' => null, 'push.vapid.private_key' => null]);
    }

    private function masuk(): User
    {
        $u = User::withoutEvents(fn () => User::query()->findOrFail(DB::table('users')->insertGetId(['email' => 'gm@jerp.test', 'name' => 'GM'])));
        Sanctum::actingAs($u, ['approval:act']);

        return $u;
    }

    public function test_langganan_fcm_tanpa_kunci_web_push(): void
    {
        $this->masuk();
        $this->getJson('/api/mobile/push/config')->assertJsonPath('fcm_aktif', true)->assertJsonPath('aktif', false);

        $this->postJson('/api/mobile/push/subscribe', ['endpoint' => self::TOKEN, 'perangkat' => 'Aplikasi · Android'])->assertOk();
        $this->assertSame('token-hp-android-0123456789', PushSubscription::first()->fcm());
        // Endpoint Web Push biasa tetap wajib URL + kunci.
        $this->postJson('/api/mobile/push/subscribe', ['endpoint' => 'bukan-url'])->assertStatus(422);
    }

    public function test_kirim_uji_lewat_fcm(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'akses-uji', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/jerp-uji/messages/1']),
        ]);
        $this->masuk();
        $this->postJson('/api/mobile/push/subscribe', ['endpoint' => self::TOKEN])->assertOk();

        $this->postJson('/api/mobile/push/uji', ['endpoint' => self::TOKEN])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count((string) $r['assertion'], '.') === 2);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/jerp-uji/messages:send'
            && $r->hasHeader('Authorization', 'Bearer akses-uji')
            && $r['message']['token'] === 'token-hp-android-0123456789'
            && $r['message']['notification']['title'] === 'Notifikasi uji J-ERP'
            && $r['message']['data']['tag'] === 'uji');
    }

    public function test_token_tidak_berlaku_langganan_dihapus(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'akses-uji']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'status' => 'UNREGISTERED']], 404),
        ]);
        $this->masuk();
        $this->postJson('/api/mobile/push/subscribe', ['endpoint' => self::TOKEN])->assertOk();

        $this->postJson('/api/mobile/push/uji', ['endpoint' => self::TOKEN])->assertStatus(410);
        $this->assertSame(0, PushSubscription::count());
    }
}
