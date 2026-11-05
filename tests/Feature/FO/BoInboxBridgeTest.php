<?php

namespace Tests\Feature\FO;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/mobile/inbox/* → BO /api/v1/fo-bridge/approval-inbox lewat BoInboxBridge.
 * BO dipalsukan (Http::fake); tanda tangan dihitung ulang dengan rumus VerifyFoBridge BO.
 * User tidak disimpan ke DB (Sanctum::actingAs cukup).
 */
class BoInboxBridgeTest extends TestCase
{
    private const SECRET = 'rahasia-uji';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ipro.sso_secret' => self::SECRET, 'ipro.bo_url' => 'http://bo.test']);
    }

    private function login(array $abilities = ['approval:act']): void
    {
        $user = new User(['email' => 'gm@jerp.test']);
        $user->id = 7;
        Sanctum::actingAs($user, $abilities);
    }

    /** Rumus kanonik VerifyFoBridge::kanonik di BO — sengaja ditulis ulang di sini. */
    private function sahDiBo(HttpRequest $r): bool
    {
        $path = parse_url($r->url(), PHP_URL_PATH);
        $kanonik = implode("\n", [
            $r->header('X-Bridge-Ts')[0], $r->header('X-Bridge-Nonce')[0], $r->method(), $path,
            $r->header('X-Bridge-Email')[0], hash('sha256', $r->body()),
        ]);

        return hash_equals(hash_hmac('sha256', $kanonik, self::SECRET), $r->header('X-Bridge-Sig')[0]);
    }

    public function test_daftar_diteruskan_ke_bo_dengan_tanda_tangan_sah(): void
    {
        Http::fake(['bo.test/*' => Http::response(['data' => [['id' => 5, 'kategori' => 'urgent']], 'counts' => ['urgent' => 1, 'reguler' => 0]])]);
        $this->login();

        $this->getJson('/api/mobile/inbox')
            ->assertOk()
            ->assertJsonPath('counts.urgent', 1)
            ->assertJsonPath('data.0.id', 5);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://bo.test/api/v1/fo-bridge/approval-inbox'
            && $r->method() === 'GET'
            && $r->header('X-Bridge-Email')[0] === 'gm@jerp.test'
            && $this->sahDiBo($r));
    }

    public function test_history_profil_dan_detail_pakai_path_yang_benar(): void
    {
        Http::fake(['bo.test/*' => Http::response(['data' => []])]);
        $this->login();

        $this->getJson('/api/mobile/inbox/history')->assertOk();
        $this->getJson('/api/mobile/inbox/profil')->assertOk();
        $this->getJson('/api/mobile/inbox/215')->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/approval-inbox/history') && $this->sahDiBo($r));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/approval-inbox/profil') && $this->sahDiBo($r));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/approval-inbox/215') && $this->sahDiBo($r));
    }

    public function test_setujui_mengirim_body_bertanda_tangan(): void
    {
        Http::fake(['bo.test/*' => Http::response(['ok' => true, 'message' => 'Dokumen disetujui.'])]);
        $this->login();

        $this->postJson('/api/mobile/inbox/215/act', ['action' => 'approve', 'notes' => 'ok'])
            ->assertOk()
            ->assertJsonPath('message', 'Dokumen disetujui.');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/approval-inbox/215/act')
            && $r['action'] === 'approve'
            && $this->sahDiBo($r));
    }

    public function test_tolak_tanpa_alasan_ditahan_di_fo(): void
    {
        Http::fake();
        $this->login();

        $this->postJson('/api/mobile/inbox/215/act', ['action' => 'reject'])
            ->assertStatus(422)
            ->assertJsonPath('errors.notes.0', 'Alasan penolakan wajib diisi.');

        Http::assertNothingSent();
    }

    public function test_token_tanpa_ability_tidak_bisa_memutuskan(): void
    {
        Http::fake();
        $this->login(['lain']);

        $this->postJson('/api/mobile/inbox/215/act', ['action' => 'approve'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_status_dan_pesan_bo_diteruskan(): void
    {
        Http::fake(['bo.test/*' => Http::response(['message' => 'Anda tidak berhak melihat dokumen ini.'], 403)]);
        $this->login();

        $this->getJson('/api/mobile/inbox/9')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak berhak melihat dokumen ini.');
    }

    public function test_bo_mati_jadi_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->login();

        $this->getJson('/api/mobile/inbox')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Server BO tidak bisa dihubungi. Coba lagi sebentar lagi.');
    }

    public function test_tanpa_login_401(): void
    {
        $this->getJson('/api/mobile/inbox')->assertUnauthorized();
    }

    public function test_dashboard_diteruskan_dengan_periode_dan_tanda_tangan_sah(): void
    {
        Http::fake(['bo.test/*' => Http::response(['allowed' => true, 'grup' => [], 'charts' => ['aging' => ['rows' => []]]])]);
        $this->login();

        $this->getJson('/api/mobile/dashboard?date_from=2026-01-01&date_to=2026-11-05')
            ->assertOk()
            ->assertJsonPath('allowed', true);

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'http://bo.test/api/v1/fo-bridge/dashboard?')
            && str_contains($r->url(), 'date_from=2026-01-01')
            && $this->sahDiBo($r));
    }

    public function test_dashboard_periode_salah_format_422(): void
    {
        Http::fake();
        $this->login();

        $this->getJson('/api/mobile/dashboard?date_from=kemarin')->assertStatus(422);
        Http::assertNothingSent();
    }
}
