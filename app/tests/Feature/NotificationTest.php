<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Jobs\PrepareNotification;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApiKeys;
use App\Services\NotificationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function key(?Tenant $tenant = null, string $application = 'dolibarr'): array
    {
        return app(ApiKeys::class)->issue($tenant ?? Tenant::factory()->create(), User::factory()->create(), ['name' => 'Test', 'application' => $application]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'recipient' => '+221770000001', 'channel' => 'whatsapp',
            'template' => 'appointment_reminder', 'variables' => ['name' => 'Test confidentiel', 'date' => '15/10'],
            'external_reference' => 'rdv-123',
        ], $overrides);
    }

    public function test_acceptance_encrypts_content_and_queues_only_an_identifier(): void
    {
        $key = $this->key();
        $response = $this->withToken($key['secret'])->withHeader('Idempotency-Key', 'rdv-123-reminder')
            ->postJson('/api/v1/notifications', $this->payload())->assertStatus(202)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.status', 'queued')->assertJsonPath('replayed', false);
        $notification = Notification::firstOrFail();
        $response->assertHeader('Location', route('api.notifications.show', $notification->public_id));
        $this->assertSame($key['key']->tenant_id, $notification->tenant_id);
        $this->assertSame('Test confidentiel', $notification->variables['name']);
        $this->assertSame('rdv-123', $notification->external_reference);
        $row = json_encode(DB::table('notifications')->first());
        foreach (['+221770000001', 'Test confidentiel', 'rdv-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $row);
            $this->assertStringNotContainsString($secret, AuditEvent::all()->toJson());
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        Queue::assertPushed(PrepareNotification::class, function ($job) use ($notification): bool {
            $this->assertStringNotContainsString('Test confidentiel', serialize($job));
            $this->assertStringNotContainsString('+221770000001', serialize($job));

            return $job->notificationId === $notification->id;
        });
        $this->getJson($response->headers->get('Location'))->assertOk()->assertJsonPath('data.id', $notification->public_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'notification.accepted', 'tenant_id' => $notification->tenant_id]);
    }

    public function test_retry_with_same_content_is_idempotent_even_after_rotation(): void
    {
        $key = $this->key();
        $this->withToken($key['secret'])->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/notifications', $this->payload())->assertStatus(202);
        $replacement = app(ApiKeys::class)->rotate($key['key']->tenant, $key['key'], User::factory()->create());
        $this->withToken($replacement['secret'])->postJson('/api/v1/notifications', $this->payload(['variables' => ['date' => '15/10', 'name' => 'Test confidentiel']]))
            ->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/notifications', $this->payload(['recipient' => '+221770000002']))->assertStatus(409);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, AuditEvent::where('action', 'notification.accepted')->count());
        Queue::assertPushed(PrepareNotification::class, 1);
    }

    public function test_api_is_scoped_to_tenant_and_application_and_ignores_web_identity(): void
    {
        $tenant = Tenant::factory()->create();
        $key = $this->key($tenant);
        $otherApp = $this->key($tenant, 'erp');
        $otherTenant = $this->key();
        $url = $this->withToken($key['secret'])->withHeader('Idempotency-Key', 'shared-reference')->postJson('/api/v1/notifications', $this->payload())
            ->assertStatus(202)->headers->get('Location');
        foreach ([$otherApp, $otherTenant] as $other) {
            $this->withToken($other['secret'])->getJson($url)->assertNotFound();
            $this->postJson('/api/v1/notifications', $this->payload())->assertStatus(202);
        }
        $this->assertDatabaseCount('notifications', 3);
        $this->withToken('')->actingAs(User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]))
            ->postJson('/api/v1/notifications', $this->payload())->assertUnauthorized();
    }

    public function test_validation_rejects_untrusted_fields_invalid_addresses_and_nested_content(): void
    {
        $key = $this->key();
        $this->withToken($key['secret'])->postJson('/api/v1/notifications', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->withHeader('Idempotency-Key', 'validation');
        foreach ([
            ['recipient' => '770000001'], ['channel' => 'sms'], ['template' => '../template'],
            ['variables' => ['name' => ['nested']]], ['variables' => ['name' => str_repeat('x', 1001)]],
            ['schedule_at' => 'tomorrow'], ['schedule_at' => '2026-02-30T10:00:00Z'],
            ['schedule_at' => now()->subDay()->format('Y-m-d\TH:i:sP')],
            ['schedule_at' => now()->addYears(2)->format('Y-m-d\TH:i:sP')],
            ['tenant_id' => 9], ['status' => 'sent'], ['application' => 'other'], ['provider' => 'meta'],
        ] as $invalid) {
            $this->postJson('/api/v1/notifications', $this->payload($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_scheduled_notifications_wait_and_replay_after_the_scheduled_date(): void
    {
        $key = $this->key();
        $data = $this->payload(['schedule_at' => now()->addHour()->format('Y-m-d\TH:i:sP')]);
        $this->withToken($key['secret'])->withHeader('Idempotency-Key', 'scheduled')->postJson('/api/v1/notifications', $data)->assertStatus(202)->assertJsonPath('data.status', 'scheduled');
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        (new PrepareNotification(Notification::firstOrFail()->id))->handle();
        $this->assertSame('scheduled', Notification::firstOrFail()->status);
        Queue::assertNothingPushed();
        $this->travel(61)->minutes();
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        Queue::assertPushed(PrepareNotification::class, 1);
        $this->postJson('/api/v1/notifications', $data)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_worker_handles_duplicates_without_claiming_a_delivery_or_calling_a_provider(): void
    {
        $notification = Notification::factory()->create();
        $job = new PrepareNotification($notification->id);
        $job->handle();
        $job->handle();
        $this->assertSame('awaiting_provider', $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->prepared_at);
        $this->assertSame(1, AuditEvent::where('action', 'notification.awaiting_provider')->count());
        Http::assertNothingSent();
    }

    public function test_disabled_tenant_is_blocked_at_worker_execution(): void
    {
        $tenant = Tenant::factory()->create();
        $notification = Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'queued']);
        $tenant->forceFill(['is_active' => false])->save();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('blocked', $notification->fresh()->status);
        $this->assertSame('tenant_inactive', $notification->fresh()->error_code);
        $tenant->forceFill(['is_active' => true])->save();
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('blocked', $notification->fresh()->status);
    }

    public function test_redis_failure_preserves_the_request_and_is_recovered_after_the_lease(): void
    {
        $key = $this->key();
        Queue::shouldReceive('connection')->with('redis')->once()->andThrow(new RuntimeException('Redis down'));
        $this->withToken($key['secret'])->withHeader('Idempotency-Key', 'redis-down')->postJson('/api/v1/notifications', $this->payload())->assertStatus(202);
        $notification = Notification::firstOrFail();
        Queue::swap($this->realQueue);
        Queue::fake();
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->travel(6)->minutes();
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        Queue::assertPushed(PrepareNotification::class, fn ($job) => $job->notificationId === $notification->id);
        (new PrepareNotification($notification->id))->handle();
        $this->assertSame('awaiting_provider', $notification->fresh()->status);
    }

    public function test_stale_queue_lease_is_republished_but_completed_work_is_not(): void
    {
        $stale = Notification::factory()->create(['status' => 'queued', 'queued_at' => now()->subMinutes(6)]);
        Notification::factory()->create(['status' => 'queued', 'queued_at' => now()]);
        Notification::factory()->create(['status' => 'awaiting_provider', 'queued_at' => now()->subHour()]);
        $this->artisan('relaxit:queue-notifications')->assertSuccessful();
        Queue::assertPushed(PrepareNotification::class, 1);
        Queue::assertPushed(PrepareNotification::class, fn ($job) => $job->notificationId === $stale->id);
    }

    public function test_audit_failure_rolls_back_acceptance_and_does_not_enqueue(): void
    {
        $key = $this->key();
        Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('Audit unavailable'));
        $this->withToken($key['secret'])->withHeader('Idempotency-Key', 'audit-down')->postJson('/api/v1/notifications', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_web_list_and_dashboard_are_scoped_and_do_not_expose_content(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Notification::factory()->create();
        Notification::factory()->create(['tenant_id' => $tenant->id, 'variables' => ['name' => 'private-variable']]);
        Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'awaiting_provider']);
        foreach (TenantRole::cases() as $role) {
            $user = User::factory()->create();
            $user->tenants()->attach($tenant, ['role' => $role->value]);
            $this->actingAs($user)->get('/tenants/'.$tenant->id.'/notifications')->assertOk()->assertDontSee('private-variable')->assertDontSee('+221770000001')
                ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('notifications.data', 2)->missing('notifications.data.0.variables')->missing('notifications.data.0.recipient'));
            $this->get('/tenants/'.$other->tenant_id.'/notifications')->assertForbidden();
        }
        $this->withSession(['tenant_id' => $tenant->id])->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('notificationCounts.pending', 1)->where('notificationCounts.awaiting_provider', 1));
        $this->get('/tenants/'.$tenant->id.'/notifications?status=pending')->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
        $support = User::factory()->create(['platform_role' => PlatformRole::Support]);
        $this->actingAs($support)->get('/tenants/'.$tenant->id.'/notifications')->assertOk();
    }

    public function test_real_redis_round_trip_contains_only_the_id_and_executes_the_job(): void
    {
        Queue::swap($this->realQueue);
        config(['queue.connections.redis.queue' => 'notification-test-'.Str::uuid()]);
        $notification = Notification::factory()->create();
        $this->assertTrue(app(NotificationQueue::class)->enqueue($notification->id));
        $job = Queue::connection('redis')->pop();
        $this->assertNotNull($job);
        try {
            $this->assertStringNotContainsString('+221770000001', $job->getRawBody());
            $job->fire();
            $this->assertSame('awaiting_provider', $notification->fresh()->status);
        } finally {
            $job->delete();
        }
    }
}
