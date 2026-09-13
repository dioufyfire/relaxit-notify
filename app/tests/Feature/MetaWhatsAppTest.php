<?php

namespace Tests\Feature;

use App\Jobs\PrepareNotification;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['meta_whatsapp' => [
            'enabled' => true, 'version' => 'v25.0', 'phone_number_id' => '12345678',
            'access_token' => 'private-test-token', 'tenant_code' => 'GLOBALE_SANTE',
            'recipient' => '+221770000001', 'enabled_after' => now()->subMinute()->format('Y-m-d\TH:i:sP'),
            'template' => 'appointment_reminder', 'language' => 'fr', 'body_variables' => ['date', 'name'],
        ]]);
    }

    private function notification(array $attributes = []): Notification
    {
        return Notification::factory()->create(array_replace([
            'tenant_id' => Tenant::factory()->create(['code' => 'GLOBALE_SANTE'])->id,
            'variables' => ['name' => 'Private example', 'date' => '20 octobre'],
        ], $attributes));
    }

    public function test_successful_send_uses_ordered_template_parameters_and_does_not_claim_delivery(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test123']]], 200)]);
        $notification = $this->notification();
        $job = new PrepareNotification($notification->id);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/12345678/messages'
            && $request->hasHeader('Authorization', 'Bearer private-test-token')
            && $request['to'] === '221770000001'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => '20 octobre'], ['type' => 'text', 'text' => 'Private example'],
            ]);
        $this->assertSame('submitted', $notification->fresh()->status);
        $this->assertSame('wamid.test123', $notification->fresh()->provider_message_id);
        $this->assertNotNull($notification->fresh()->submitted_at);
        $log = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('Private example', $log);
        $this->assertStringNotContainsString('private-test-token', $log);
    }

    public function test_disabled_provider_keeps_requests_waiting(): void
    {
        config(['meta_whatsapp.enabled' => false]);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('awaiting_provider', $notification->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_activation_never_sends_old_pending_requests_or_prepared_backlog(): void
    {
        $notification = $this->notification(['created_at' => now()->subHour()]);
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('awaiting_provider', $notification->fresh()->status);
        $notification->created_at = now();
        $notification->save();
        (new PrepareNotification($notification->id))->handle();
        Http::assertNothingSent();
    }

    public function test_other_recipients_are_blocked(): void
    {
        $notification = $this->notification(['recipient' => '+221770000002']);
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('outside_whatsapp_pilot', $notification->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_other_tenants_are_blocked_even_with_the_allowed_recipient(): void
    {
        config(['meta_whatsapp.tenant_code' => 'OTHER']);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('blocked', $notification->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_missing_token_and_unconfigured_templates_never_send(): void
    {
        $notification = $this->notification();
        config(['meta_whatsapp.access_token' => '']);
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('whatsapp_config_invalid', $notification->fresh()->error_code);
        config(['meta_whatsapp.access_token' => 'test']);
        $notification->refresh();
        $notification->status = 'pending';
        $notification->variables = ['unexpected' => 'value'];
        $notification->save();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('whatsapp_template_mismatch', $notification->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_connection_failure_is_uncertain_and_never_automatically_resent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('delivery_unknown', $notification->fresh()->status);
        Queue::fake();
        $this->travel(10)->minutes();
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        Queue::assertNothingPushed();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('meta_connection_uncertain', $notification->fresh()->error_code);
    }

    public function test_rejection_is_distinct_from_server_failure_and_malformed_success(): void
    {
        $notification = $this->notification();
        $responses = [400 => 'failed', 401 => 'failed', 429 => 'failed', 500 => 'delivery_unknown', 200 => 'delivery_unknown'];
        $sequence = Http::sequence();
        foreach ($responses as $code => $status) {
            $sequence->push(['error' => ['message' => 'do-not-log-private-value']], $code);
        }
        Http::fake(['graph.facebook.com/*' => $sequence]);
        foreach ($responses as $code => $status) {
            $notification->refresh();
            $notification->status = 'pending';
            $notification->save();
            (new PrepareNotification($notification->id))->handle();
            $this->assertSame($status, $notification->fresh()->status);
        }
        $this->assertStringNotContainsString('do-not-log-private-value', AuditEvent::all()->toJson());
    }

    public function test_interrupted_sending_is_not_retried_and_becomes_uncertain(): void
    {
        $notification = $this->notification(['status' => 'sending', 'send_started_at' => now()->subMinutes(6)]);
        (new PrepareNotification($notification->id))->handle();
        Http::assertNothingSent();
        Queue::fake();
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        $this->assertSame('delivery_unknown', $notification->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_readiness_command_does_not_reveal_secrets_or_send_requests(): void
    {
        $this->artisan('relaxit:whatsapp-status')->assertSuccessful();
        config(['meta_whatsapp.enabled_after' => null]);
        $this->artisan('relaxit:whatsapp-status')->assertFailed();
        Http::assertNothingSent();
    }
}
