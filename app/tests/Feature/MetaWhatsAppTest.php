<?php

namespace Tests\Feature;

use App\Jobs\PrepareNotification;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_image_header_is_sent_with_ordered_body_parameters(): void
    {
        config(['meta_whatsapp.header_image_url' => 'https://example.com/logo.png']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.image123']]], 200)]);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['template']['components'] === [
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://example.com/logo.png']]]],
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => '20 octobre'], ['type' => 'text', 'text' => 'Private example'],
            ]],
        ]);
        $this->assertSame('submitted', $notification->fresh()->status);
    }

    public function test_invalid_image_configuration_blocks_sending(): void
    {
        $notification = $this->notification();
        foreach (['http://example.com/logo.png', 'not-a-url', 'https://user:password@example.com/logo.png', 'https://example.com/logo.png#fragment', ['invalid']] as $url) {
            config(['meta_whatsapp.header_image_url' => $url]);
            $notification->refresh();
            $notification->status = 'pending';
            $notification->save();
            (new PrepareNotification($notification->id))->handle();
            $this->assertSame('whatsapp_config_invalid', $notification->fresh()->error_code);
            $this->artisan('relaxit:whatsapp-status')->assertFailed();
        }
        Http::assertNothingSent();
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

    public function test_production_sends_to_another_patient_without_a_test_recipient(): void
    {
        config(['meta_whatsapp.mode' => 'production', 'meta_whatsapp.application' => 'dolibarr', 'meta_whatsapp.recipient' => '']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.production']]], 200)]);
        $notification = $this->notification(['recipient' => '+221770000099']);
        (new PrepareNotification($notification->id))->handle();
        Http::assertSent(fn ($request) => $request['to'] === '221770000099');
        $this->assertSame('submitted', $notification->fresh()->status);
    }

    public function test_production_keeps_tenant_application_and_template_boundaries(): void
    {
        config(['meta_whatsapp.mode' => 'production', 'meta_whatsapp.application' => 'dolibarr']);
        $notification = $this->notification();
        foreach ([
            ['application' => 'other', 'template' => 'appointment_reminder', 'error' => 'whatsapp_application_not_allowed'],
            ['application' => 'dolibarr', 'template' => 'other', 'error' => 'whatsapp_template_mismatch'],
        ] as $case) {
            $notification->refresh();
            $notification->status = 'pending';
            $notification->application = $case['application'];
            $notification->template = $case['template'];
            $notification->save();
            (new PrepareNotification($notification->id))->handle();
            $this->assertSame($case['error'], $notification->fresh()->error_code);
        }
        config(['meta_whatsapp.tenant_code' => 'OTHER']);
        $notification->refresh();
        $notification->status = 'pending';
        $notification->save();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('blocked', $notification->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_invalid_mode_or_limit_never_opens_sending(): void
    {
        $notification = $this->notification();
        foreach (['Production', '', 'all'] as $mode) {
            config(['meta_whatsapp.mode' => $mode]);
            $notification->refresh();
            $notification->status = 'pending';
            $notification->save();
            (new PrepareNotification($notification->id))->handle();
            $this->assertSame('whatsapp_config_invalid', $notification->fresh()->error_code);
        }
        config(['meta_whatsapp.mode' => 'pilot']);
        foreach ([0, -1, 251, 'invalid', true, 1.5, null] as $limit) {
            config(['meta_whatsapp.max_attempts_per_24h' => $limit]);
            $notification->refresh();
            $notification->status = 'pending';
            $notification->save();
            (new PrepareNotification($notification->id))->handle();
            $this->assertSame('whatsapp_config_invalid', $notification->fresh()->error_code);
        }
        Http::assertNothingSent();
    }

    public function test_daily_limit_counts_failed_and_uncertain_attempts_across_tenants_and_numbers(): void
    {
        config(['meta_whatsapp.max_attempts_per_24h' => 2]);
        Notification::factory()->create(['status' => 'failed', 'send_started_at' => now()->subHour(), 'provider_phone_number_id' => '99999999']);
        Notification::factory()->create(['status' => 'delivery_unknown', 'send_started_at' => now()->subHours(2)]);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('blocked', $notification->fresh()->status);
        $this->assertSame('whatsapp_daily_limit_reached', $notification->fresh()->error_code);
        $this->assertNull($notification->fresh()->send_started_at);
        $this->travel(25)->hours();
        Queue::fake();
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        (new PrepareNotification($notification->id))->handle();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_expired_attempts_and_unsent_rows_do_not_consume_capacity(): void
    {
        config(['meta_whatsapp.max_attempts_per_24h' => '1']);
        Notification::factory()->create(['status' => 'read', 'send_started_at' => now()->subHours(24)->subSecond()]);
        Notification::factory()->create(['status' => 'blocked', 'send_started_at' => null]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.capacity']]], 200)]);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('submitted', $notification->fresh()->status);
        $next = Notification::factory()->create([
            'tenant_id' => $notification->tenant_id, 'variables' => ['name' => 'Another', 'date' => '20 octobre'],
        ]);
        (new PrepareNotification($next->id))->handle();
        $this->assertSame('whatsapp_daily_limit_reached', $next->fresh()->error_code);
        Http::assertSentCount(1);
    }

    public function test_attempt_at_the_24_hour_boundary_is_still_counted(): void
    {
        $this->freezeSecond();
        config(['meta_whatsapp.max_attempts_per_24h' => 1]);
        Notification::factory()->create(['status' => 'sending', 'send_started_at' => now()->subHours(24)]);
        $notification = $this->notification();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('whatsapp_daily_limit_reached', $notification->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_reservation_holds_a_database_lock_against_other_connections(): void
    {
        config(['database.connections.quota_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('quota_probe');
        try {
            $this->assertTrue($probe->selectOne('SELECT pg_try_advisory_xact_lock(724613, 1) AS acquired')->acquired);
            Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.lock']]], 200)]);
            $notification = $this->notification();
            (new PrepareNotification($notification->id))->handle();
            // RefreshDatabase keeps the outer transaction open until this test ends.
            $this->assertFalse($probe->selectOne('SELECT pg_try_advisory_xact_lock(724613, 1) AS acquired')->acquired);
        } finally {
            DB::purge('quota_probe');
        }
    }

    public function test_additional_approved_template_is_sent_by_its_own_name(): void
    {
        config(['meta_whatsapp.additional_templates' => ['appointment_changed', 'appointment_cancelled', 'appointment_reminder_24h']]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.changed']]], 200)]);
        $notification = $this->notification(['template' => 'appointment_changed']);
        (new PrepareNotification($notification->id))->handle();
        Http::assertSent(fn ($request) => $request['template']['name'] === 'appointment_changed'
            && $request['template']['language']['code'] === 'fr'
            && $request['template']['components'][0]['parameters'][0]['text'] === '20 octobre');
        $this->assertSame('submitted', $notification->fresh()->status);
    }

    public function test_extra_templates_cannot_bypass_variable_or_recipient_restrictions(): void
    {
        config(['meta_whatsapp.additional_templates' => ['appointment_changed']]);
        $notification = $this->notification(['template' => 'appointment_changed', 'variables' => ['unexpected' => 'value']]);
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('whatsapp_template_mismatch', $notification->fresh()->error_code);
        $notification->refresh();
        $notification->status = 'pending';
        $notification->variables = ['name' => 'Example', 'date' => '20 octobre'];
        $notification->recipient = '+221770000002';
        $notification->save();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('outside_whatsapp_pilot', $notification->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_invalid_additional_template_list_blocks_sending(): void
    {
        $notification = $this->notification();
        foreach (['not-an-array', ['Uppercase'], [['nested']], ['appointment_reminder'], ['changed', 'changed'], array_fill(0, 11, 'changed')] as $templates) {
            config(['meta_whatsapp.additional_templates' => $templates]);
            $notification->refresh();
            $notification->status = 'pending';
            $notification->save();
            (new PrepareNotification($notification->id))->handle();
            $this->assertSame('whatsapp_config_invalid', $notification->fresh()->error_code);
        }
        Http::assertNothingSent();
    }

    public function test_readiness_command_does_not_reveal_secrets_or_send_requests(): void
    {
        $this->artisan('relaxit:whatsapp-status')->assertSuccessful();
        config(['meta_whatsapp.enabled_after' => null]);
        $this->artisan('relaxit:whatsapp-status')->assertFailed();
        Http::assertNothingSent();
    }
}
