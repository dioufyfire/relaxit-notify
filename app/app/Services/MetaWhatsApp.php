<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetaWhatsApp
{
    /** @return list<string> Missing or invalid setting names, never their values. */
    public function invalidSettings(): array
    {
        $rules = [
            'version' => '/\Av\d+\.0\z/',
            'phone_number_id' => '/\A[0-9]{5,30}\z/',
            'tenant_code' => '/\A[A-Z][A-Z0-9_]{0,63}\z/',
            'recipient' => '/\A\+[1-9][0-9]{7,14}\z/',
            'template' => '/\A[a-z][a-z0-9_]{0,99}\z/',
            'language' => '/\A[a-z]{2,3}(?:_[A-Z]{2})?\z/',
        ];
        $invalid = [];
        foreach ($rules as $name => $pattern) {
            if (! is_string(config('meta_whatsapp.'.$name)) || ! preg_match($pattern, config('meta_whatsapp.'.$name))) {
                $invalid[] = $name;
            }
        }
        if (! is_string(config('meta_whatsapp.access_token')) || trim(config('meta_whatsapp.access_token')) === '') {
            $invalid[] = 'access_token';
        }
        $cutoff = config('meta_whatsapp.enabled_after');
        try {
            if (! is_string($cutoff) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $cutoff)
                || ! CarbonImmutable::parse($cutoff)) {
                $invalid[] = 'enabled_after';
            }
        } catch (Throwable) {
            $invalid[] = 'enabled_after';
        }
        $variables = config('meta_whatsapp.body_variables');
        if (! is_array($variables) || count($variables) > 30 || count($variables) !== count(array_unique($variables))) {
            $invalid[] = 'body_variables';
        } else {
            foreach ($variables as $variable) {
                if (! is_string($variable) || ! preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $variable)) {
                    $invalid[] = 'body_variables';
                    break;
                }
            }
        }

        return $invalid;
    }

    /** @return array{status: string, error: ?string} */
    public function disposition(Notification $notification, Tenant $tenant): array
    {
        if (! config('meta_whatsapp.enabled')) {
            return ['status' => 'awaiting_provider', 'error' => null];
        }
        if ($this->invalidSettings() !== []) {
            return ['status' => 'blocked', 'error' => 'whatsapp_config_invalid'];
        }
        if ($notification->created_at->lte(CarbonImmutable::parse(config('meta_whatsapp.enabled_after')))) {
            return ['status' => 'awaiting_provider', 'error' => null];
        }
        if ($tenant->code !== config('meta_whatsapp.tenant_code') || $notification->recipient !== config('meta_whatsapp.recipient')) {
            return ['status' => 'blocked', 'error' => 'outside_whatsapp_pilot'];
        }
        $expected = config('meta_whatsapp.body_variables');
        $actual = array_map('strval', array_keys($notification->variables));
        sort($expected);
        sort($actual);
        if ($notification->channel !== 'whatsapp' || $notification->template !== config('meta_whatsapp.template') || $actual !== $expected) {
            return ['status' => 'blocked', 'error' => 'whatsapp_template_mismatch'];
        }

        return ['status' => 'sending', 'error' => null];
    }

    /** @return array{status: string, error: ?string, message_id: ?string} */
    public function send(Notification $notification): array
    {
        $template = ['name' => config('meta_whatsapp.template'), 'language' => ['code' => config('meta_whatsapp.language')]];
        $parameters = [];
        foreach (config('meta_whatsapp.body_variables') as $variable) {
            $parameters[] = ['type' => 'text', 'text' => $notification->variables[$variable]];
        }
        if ($parameters !== []) {
            $template['components'] = [['type' => 'body', 'parameters' => $parameters]];
        }
        try {
            $response = Http::withToken(config('meta_whatsapp.access_token'))->acceptJson()
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://graph.facebook.com/'.config('meta_whatsapp.version').'/'.config('meta_whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($notification->recipient, '+'),
                    'type' => 'template',
                    'template' => $template,
                ]);
        } catch (Throwable) {
            return ['status' => 'delivery_unknown', 'error' => 'meta_connection_uncertain', 'message_id' => null];
        }
        $id = $response->json('messages.0.id');
        if ($response->successful() && is_string($id) && preg_match('/\Awamid\.[A-Za-z0-9_+=.\/-]{1,249}\z/', $id)) {
            return ['status' => 'submitted', 'error' => null, 'message_id' => $id];
        }
        if ($response->clientError() && $response->status() !== 408) {
            return ['status' => 'failed', 'error' => 'meta_http_'.$response->status(), 'message_id' => null];
        }

        return ['status' => 'delivery_unknown', 'error' => 'meta_response_uncertain', 'message_id' => null];
    }
}
