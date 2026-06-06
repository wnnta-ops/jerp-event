<?php

namespace App\Services\Recaptcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verify a reCAPTCHA v3 token. Returns the score (0..1) on success, or null on failure.
 * When disabled in settings, returns 1.0 to short-circuit form validation.
 */
class RecaptchaVerifier
{
    public function verify(?string $token, ?string $action = null, ?string $ip = null): ?float
    {
        $enabled = (bool) config('services.recaptcha.enabled', env('RECAPTCHA_ENABLED', false));
        if (! $enabled) {
            return 1.0;
        }

        if (! $token) {
            return null;
        }

        $secret = config('services.recaptcha.secret', env('RECAPTCHA_SECRET_KEY'));
        if (! $secret) {
            Log::warning('Recaptcha: secret key not set; treating as failed verification.');

            return null;
        }

        try {
            $resp = Http::asForm()
                ->timeout(5)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            $payload = $resp->json();
            if (! ($payload['success'] ?? false)) {
                return null;
            }

            if ($action && ($payload['action'] ?? null) !== $action) {
                return null;
            }

            return (float) ($payload['score'] ?? 0);
        } catch (\Throwable $e) {
            Log::warning('Recaptcha verify error: '.$e->getMessage());

            return null;
        }
    }
}
