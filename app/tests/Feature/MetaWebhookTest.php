<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\AuditEvent;
use App\Models\MetaWebhookReceipt;
use App\Models\Notification;
use App\Models\User;
use App\Services\MetaWebhookStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'meta_whatsapp.webhook_enabled' => true,
            'meta_whatsapp.enabled' => false,
            'meta_whatsapp.verify_token' => 'private-verification-token',
            'meta_whatsapp.app_secret' => 'private-app-secret',
            'meta_whatsapp.waba_id' => '123456789',
            'meta_whatsapp.phone_number_id' => '987654321',
        ]);
        Http::fake();
        Queue::fake();
    }

    private function notification(array $attributes = []): Notification
    {
        return Notification::factory()->create(array_replace([
            'status' => 'submitted', 'provider_message_id' => 'wamid.test123',
            'provider_phone_number_id' => '987654321', 'provider_waba_id' => '123456789',
        ], $attributes));
    }

    private function payload(string $status = 'delivered', string $messageId = 'wamid.test123', ?int $timestamp = null): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '123456789', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '987654321'],
                'statuses' => [['id' => $messageId, 'status' => $status, 'timestamp' => (string) ($timestamp ?? now()->timestamp),
                    'recipient_id' => '221770000001',
                    'errors' => [['code' => 131026, 'title' => 'private-error', 'error_data' => ['details' => 'private-details']]]]],
            ]]]]],
        ];
    }

    private function postSigned(array $payload, string $secret = 'private-app-secret'): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);
    }

    public function test_get_challenge_uses_verification_token_without_web_login_or_api_key(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=private-verification-token&hub.challenge=12345')
            ->assertOk()->assertContent('12345')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token[]=private-verification-token&hub.challenge=12345')->assertForbidden();
        config(['meta_whatsapp.verify_token' => '']);
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=12345')->assertStatus(503);
    }

    public function test_missing_forged_and_tampered_signatures_do_not_store_or_update(): void
    {
        $notification = $this->notification();
        $this->postJson('/api/webhooks/whatsapp', $this->payload())->assertForbidden();
        $this->postSigned($this->payload(), 'wrong')->assertForbidden();
        $raw = json_encode($this->payload());
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'private-app-secret')], $raw.' ')->assertForbidden();
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
        $this->assertSame('submitted', $notification->fresh()->status);
    }

    public function test_valid_event_is_durable_and_only_applied_by_reconciliation(): void
    {
        $notification = $this->notification();
        $this->postSigned($this->payload())->assertOk()->assertJsonPath('received', true);
        $this->assertSame('submitted', $notification->fresh()->status);
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $this->assertSame('delivered', $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->delivered_at);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame($notification->id, MetaWebhookReceipt::firstOrFail()->notification_id);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_repeated_and_reordered_events_do_not_regress_or_duplicate_audit(): void
    {
        $notification = $this->notification();
        $now = now()->timestamp;
        foreach (['read' => $now, 'sent' => $now - 10, 'delivered' => $now - 5, 'failed' => $now + 1] as $status => $time) {
            $this->postSigned($this->payload($status, timestamp: $time))->assertOk();
            $this->postSigned($this->payload($status, timestamp: $time))->assertOk();
            $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        }
        $this->assertDatabaseCount('meta_webhook_receipts', 4);
        $notification->refresh();
        $this->assertSame('read', $notification->status);
        $this->assertSame($now, $notification->read_at->timestamp);
        $this->assertSame($now - 5, $notification->delivered_at->timestamp);
        $this->assertSame($now - 10, $notification->sent_at->timestamp);
        $this->assertNull($notification->error_code);
        $this->assertSame(1, AuditEvent::where('action', 'notification.read')->count());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_failed_delivery_is_not_overwritten_by_sent_but_delivery_evidence_wins(): void
    {
        $notification = $this->notification();
        foreach (['failed', 'sent', 'delivered'] as $status) {
            $this->postSigned($this->payload($status))->assertOk();
            $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
            $notification->refresh();
            $this->assertSame($status === 'delivered' ? 'delivered' : 'failed', $notification->status);
            $this->assertSame($status === 'delivered' ? null : 'meta_delivery_131026', $notification->error_code);
        }
    }

    public function test_early_receipt_matches_after_the_sender_records_the_message_id(): void
    {
        $notification = $this->notification(['provider_message_id' => null, 'status' => 'sending']);
        $this->postSigned($this->payload())->assertOk();
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $this->assertNull(MetaWebhookReceipt::firstOrFail()->processed_at);
        $notification->provider_message_id = 'wamid.test123';
        $notification->status = 'submitted';
        $notification->save();
        $this->travel(6)->minutes();
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $this->assertSame('delivered', $notification->fresh()->status);
    }

    public function test_wrong_waba_phone_and_sender_snapshot_cannot_change_notification(): void
    {
        $notification = $this->notification();
        $payload = $this->payload();
        $payload['entry'][0]['id'] = '111111111';
        $this->postSigned($payload)->assertOk();
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '222222222';
        $this->postSigned($payload)->assertOk();
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
        $notification->provider_phone_number_id = '333333333';
        $notification->save();
        $this->postSigned($this->payload())->assertOk();
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $this->assertSame('submitted', $notification->fresh()->status);
    }

    public function test_message_id_matches_only_one_client_and_legacy_pilot_rows_are_supported(): void
    {
        $notification = $this->notification(['provider_phone_number_id' => null, 'provider_waba_id' => null]);
        $other = Notification::factory()->create(['provider_message_id' => 'wamid.other', 'status' => 'submitted']);
        $this->postSigned($this->payload())->assertOk();
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $this->assertSame('delivered', $notification->fresh()->status);
        $this->assertSame('submitted', $other->fresh()->status);
    }

    public function test_inbound_messages_and_sensitive_status_fields_are_not_persisted(): void
    {
        $notification = $this->notification();
        $payload = $this->payload('failed');
        $payload['entry'][0]['changes'][0]['value']['messages'] = [['text' => ['body' => 'private-medical-text'], 'from' => '221770000001']];
        $this->postSigned($payload)->assertOk();
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $stored = json_encode(DB::table('meta_webhook_receipts')->get()).AuditEvent::all()->toJson();
        foreach (['private-medical-text', '221770000001', 'private-error', 'private-details', 'private-app-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        unset($payload['entry'][0]['changes'][0]['value']['statuses']);
        $this->postSigned($payload)->assertOk();
        $this->assertDatabaseCount('meta_webhook_receipts', 1);
    }

    public function test_bad_json_shape_timestamps_and_disabled_webhooks_are_rejected(): void
    {
        $this->postSigned(['object' => 'whatsapp_business_account', 'entry' => 'invalid'])->assertStatus(400);
        $this->postSigned($this->payload(timestamp: -1))->assertStatus(400);
        $this->postSigned($this->payload(timestamp: 9999999999))->assertStatus(400);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', '{', 'private-app-secret')], '{')->assertStatus(400);
        config(['meta_whatsapp.webhook_enabled' => false]);
        $this->postSigned($this->payload())->assertStatus(503);
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
    }

    public function test_database_failure_is_not_acknowledged_as_received(): void
    {
        DB::statement('DROP TABLE meta_webhook_receipts');
        $this->postSigned($this->payload())->assertStatus(500);
    }

    public function test_audit_failure_rolls_back_status_and_keeps_receipt_pending(): void
    {
        $notification = $this->notification();
        $this->postSigned($this->payload())->assertOk();
        Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(MetaWebhookStatus::class)->reconcile(MetaWebhookReceipt::firstOrFail()->id);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('submitted', $notification->fresh()->status);
        $this->assertNull(MetaWebhookReceipt::firstOrFail()->processed_at);
    }

    public function test_retention_removes_only_expired_receipts_without_deleting_notifications(): void
    {
        $notification = $this->notification(['status' => 'read', 'read_at' => now()->subDays(35)]);
        MetaWebhookReceipt::factory()->create(['received_at' => now()->subDays(8)]);
        MetaWebhookReceipt::factory()->create(['received_at' => now()->subDays(31), 'processed_at' => now()->subDays(30), 'notification_id' => $notification->id]);
        MetaWebhookReceipt::factory()->create(['received_at' => now()->subDays(2), 'next_attempt_at' => now()->addMinute()]);
        $this->artisan('relaxit:reconcile-meta-webhooks')->assertSuccessful();
        $this->assertDatabaseCount('meta_webhook_receipts', 1);
        $this->assertSame('read', $notification->fresh()->status);
    }

    public function test_delivery_dates_are_visible_only_in_the_authorized_client_console(): void
    {
        $notification = $this->notification(['status' => 'read', 'read_at' => now()]);
        $user = User::factory()->create();
        $user->tenants()->attach($notification->tenant_id, ['role' => TenantRole::ReadOnly->value]);
        $this->actingAs($user)->get('/tenants/'.$notification->tenant_id.'/notifications?status=read')
            ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('notifications.data', 1)
                ->where('notifications.data.0.status', 'read')->where('notifications.data.0.read_at', $notification->read_at->toISOString()));
        $other = User::factory()->create();
        $this->actingAs($other)->get('/tenants/'.$notification->tenant_id.'/notifications')->assertForbidden();
    }
}
