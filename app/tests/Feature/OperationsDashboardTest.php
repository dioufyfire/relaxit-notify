<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeSecond();
    }

    public function test_client_metrics_and_links_are_scoped_and_do_not_expose_payloads(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['role' => TenantRole::ReadOnly->value]);
        $failed = Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed', 'recipient' => '+221770001234', 'variables' => ['secret' => 'private-patient'], 'send_started_at' => now()->subMinutes(10)]);
        Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'read']);
        Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'read', 'created_at' => now()->subHours(25), 'send_started_at' => now()->subHours(25)]);
        Notification::factory()->create(['status' => 'failed', 'send_started_at' => now()]);
        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/dashboard');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('operations.received24h', 2)->where('operations.delivered24h', 1)->where('operations.attempts24h', 1)
            ->where('operations.counts.failed', 1)->where('operations.counts.read', 2)->where('quota', null)
            ->has('operations.issues', 1)->where('operations.issues.0.id', $failed->public_id)
            ->missing('operations.issues.0.recipient')->missing('operations.issues.0.variables'));
        $response->assertDontSee('private-patient')->assertDontSee('+221770001234');
        $this->get(route('notifications.index', ['tenant' => $tenant, 'reference' => $failed->public_id]))
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)->where('notifications.data.0.id', $failed->public_id));
    }

    public function test_only_platform_roles_see_shared_quota_and_old_attempts_expire(): void
    {
        config(['meta_whatsapp.max_attempts_per_24h' => 2]);
        Notification::factory()->create(['send_started_at' => now()->subHours(24), 'status' => 'failed']);
        Notification::factory()->create(['send_started_at' => now()->subMinute(), 'status' => 'delivery_unknown']);
        Notification::factory()->create(['send_started_at' => now()->subHours(24)->subSecond()]);
        foreach (PlatformRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['platform_role' => $role]))->get('/dashboard')
                ->assertInertia(fn (Assert $page) => $page->where('quota.used', 2)->where('quota.remaining', 0)->where('quota.limit', 2)->where('operations', null));
        }
        config(['meta_whatsapp.max_attempts_per_24h' => 'bad']);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('quota.limit', null)->where('quota.remaining', null));
    }

    public function test_overdue_excludes_future_schedules_and_issues_are_bounded(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'scheduled', 'created_at' => now()->subDays(2), 'schedule_at' => now()->addDay()]);
        Notification::factory()->create(['tenant_id' => $tenant->id, 'status' => 'submitted', 'created_at' => now()->subHours(2)]);
        Notification::factory()->count(12)->create(['tenant_id' => $tenant->id, 'status' => 'blocked']);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('operations.overdue', 1)->where('operations.counts.blocked', 12)->has('operations.issues', 10));
    }

    public function test_revoked_membership_and_foreign_reference_never_leak_data(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['role' => TenantRole::ReadOnly->value]);
        $other = Notification::factory()->create();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('notifications.index', ['tenant' => $tenant, 'reference' => $other->public_id]))
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));
        $this->get(route('notifications.index', ['tenant' => $tenant, 'reference' => 'invalid']))->assertSessionHasErrors('reference');
        $user->tenants()->detach($tenant);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('operations', null)->where('quota', null)->where('currentTenant', null));
    }
}
