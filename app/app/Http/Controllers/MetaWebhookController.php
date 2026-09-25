<?php

namespace App\Http\Controllers;

use App\Services\MetaWebhookStatus;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class MetaWebhookController extends Controller
{
    private function ensureConfigured(): void
    {
        abort_unless(config('meta_whatsapp.webhook_enabled')
            && is_string(config('meta_whatsapp.app_secret')) && config('meta_whatsapp.app_secret') !== ''
            && is_string(config('meta_whatsapp.verify_token')) && config('meta_whatsapp.verify_token') !== ''
            && preg_match('/\A[0-9]{5,30}\z/', (string) config('meta_whatsapp.waba_id'))
            && preg_match('/\A[0-9]{5,30}\z/', (string) config('meta_whatsapp.phone_number_id')), 503, 'Webhook indisponible.');
    }

    public function verify(Request $request): Response
    {
        $this->ensureConfigured();
        // PHP normalizes dots in query parameter names to underscores.
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless($mode === 'subscribe' && is_string($token)
            && hash_equals(config('meta_whatsapp.verify_token'), $token)
            && is_string($challenge) && strlen($challenge) <= 512 && $challenge !== '', 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain')->header('Cache-Control', 'no-store');
    }

    public function receive(Request $request, MetaWebhookStatus $statuses): Response
    {
        $this->ensureConfigured();
        $raw = $request->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        $signature = $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $raw, config('meta_whatsapp.app_secret'));
        abort_unless(is_string($signature) && hash_equals($expected, $signature), 403);
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'JSON invalide.');
        }
        abort_unless(is_array($payload), 400, 'Objet JSON attendu.');
        $statuses->receive($payload);

        return response()->json(['received' => true])->header('Cache-Control', 'no-store');
    }
}
