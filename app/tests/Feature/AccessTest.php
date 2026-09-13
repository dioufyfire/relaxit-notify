<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $this->withoutVite();
    }

    public function test_guests_are_redirected_and_registration_is_not_exposed(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/tenants')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->get('/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_login_normalizes_email_rotates_session_and_logout_clears_context(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $this->withSession(['tenant_id' => 99]);
        $previousId = session()->getId();
        $this->post('/login', ['email' => ' ADMIN@example.test ', 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertSessionMissing('tenant_id');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousId, session()->getId());
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('tenant_id');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_wrong_password_and_inactive_account_have_same_error(): void
    {
        $user = User::factory()->create();
        $credentials = ['email' => $user->email, 'password' => 'wrong'];
        $this->from('/login')->post('/login', $credentials)->assertSessionHasErrors([
            'email' => 'Adresse email ou mot de passe incorrect.',
        ]);
        $this->assertGuest();
        $user->forceFill(['is_active' => false])->save();
        $this->post('/login', [...$credentials, 'password' => 'password'])->assertSessionHasErrors([
            'email' => 'Adresse email ou mot de passe incorrect.',
        ]);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_and_array_input_is_rejected(): void
    {
        $this->post('/login', ['email' => ['invalid'], 'password' => 'x'])->assertSessionHasErrors('email');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertRedirect();
        }
        $this->post('/login', ['email' => 'MISSING@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_client_cannot_list_view_select_or_edit_another_tenant(): void
    {
        $user = User::factory()->create();
        $own = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $user->tenants()->attach($own, ['role' => TenantRole::Admin->value]);
        $this->actingAs($user)->get('/tenants')->assertInertia(fn (Assert $page) => $page
            ->component('Tenants/Index')->has('tenants.data', 1)->where('tenants.data.0.id', $own->id));
        $this->get('/tenants/'.$other->id)->assertForbidden();
        $this->post('/tenants/'.$other->id.'/select')->assertForbidden()->assertSessionMissing('tenant_id');
        $this->patch('/tenants/'.$other->id, ['name' => 'Intrusion'])->assertForbidden();
        $this->get('/tenants/'.$own->id)->assertOk();
        $this->assertDatabaseMissing('tenants', ['name' => 'Intrusion']);
    }

    public function test_each_role_has_only_the_initial_permissions_defined(): void
    {
        $tenant = Tenant::factory()->create();
        foreach (TenantRole::cases() as $role) {
            $user = User::factory()->create();
            $user->tenants()->attach($tenant, ['role' => $role->value]);
            $this->assertTrue(Gate::forUser($user)->allows('view', $tenant));
            $this->assertSame($role === TenantRole::Admin, Gate::forUser($user)->allows('update', $tenant));
            $this->actingAs($user)->post('/tenants', ['code' => 'FORBIDDEN', 'name' => 'Forbidden'])->assertForbidden();
            $response = $this->patch('/tenants/'.$tenant->id, ['name' => 'Updated '.$role->value]);
            $role === TenantRole::Admin ? $response->assertRedirect() : $response->assertForbidden();
        }
        foreach (PlatformRole::cases() as $role) {
            $user = User::factory()->create(['platform_role' => $role]);
            $this->actingAs($user)->get('/tenants/'.$tenant->id)->assertOk();
            $this->assertSame($role === PlatformRole::SuperAdmin, Gate::forUser($user)->allows('update', $tenant));
            $this->assertSame($role === PlatformRole::SuperAdmin, Gate::forUser($user)->allows('create', Tenant::class));
        }
    }

    public function test_support_cannot_write_even_if_given_a_client_admin_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $support = User::factory()->create(['platform_role' => PlatformRole::Support]);
        $support->tenants()->attach($tenant, ['role' => TenantRole::Admin->value]);
        $this->actingAs($support)->patch('/tenants/'.$tenant->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->get('/tenants/create')->assertForbidden();
    }

    public function test_super_admin_can_create_select_and_update_tenant_but_not_duplicate_code(): void
    {
        $user = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        $this->actingAs($user)->get('/tenants/create')->assertOk();
        $this->post('/tenants', ['name' => 'Globale Santé', 'code' => 'GLOBALE_SANTE'])->assertRedirect();
        $tenant = Tenant::firstOrFail();
        $this->post('/tenants', ['name' => 'Duplicate', 'code' => $tenant->code])->assertSessionHasErrors('code');
        $this->post('/tenants/'.$tenant->id.'/select')->assertRedirect('/dashboard')->assertSessionHas('tenant_id', $tenant->id);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('currentTenant.id', $tenant->id)->where('auth.user.role', 'Super Admin RelaxIT'));
        $this->patch('/tenants/'.$tenant->id, ['name' => 'Globale Santé Dakar', 'phone' => '+221 33 860 81 81'])->assertRedirect();
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Globale Santé Dakar']);
    }

    public function test_roles_and_status_cannot_be_injected_in_a_tenant_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['role' => TenantRole::Admin->value]);
        $this->actingAs($user)->patch('/tenants/'.$tenant->id, [
            'name' => 'Malicious', 'platform_role' => 'super_admin', 'role' => 'admin_client',
            'is_active' => false, 'code' => 'REPLACED',
        ])->assertSessionHasErrors(['platform_role', 'role', 'is_active', 'code']);
        $this->assertNull($user->fresh()->platform_role);
        $this->assertDatabaseMissing('tenants', ['name' => 'Malicious']);
    }

    public function test_membership_revocation_clears_a_previously_selected_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['role' => TenantRole::ReadOnly->value]);
        $this->actingAs($user)->post('/tenants/'.$tenant->id.'/select')->assertRedirect();
        $user->tenants()->detach($tenant);
        $this->get('/dashboard')->assertSessionMissing('tenant_id')->assertInertia(fn (Assert $page) => $page->where('currentTenant', null));
        $this->get('/tenants/'.$tenant->id)->assertForbidden();
    }

    public function test_inactive_tenant_is_inaccessible_even_to_super_admin(): void
    {
        $user = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        $tenant = Tenant::factory()->create(['is_active' => false]);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/dashboard')->assertSessionMissing('tenant_id');
        $this->get('/tenants/'.$tenant->id)->assertForbidden();
        $this->post('/tenants/'.$tenant->id.'/select')->assertForbidden();
    }

    public function test_disabled_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->withSession(['tenant_id' => 1])->get('/dashboard')->assertRedirect('/login')->assertSessionMissing('tenant_id');
        $this->assertGuest();
    }

    public function test_membership_is_unique_per_user_and_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant);
        $this->expectException(UniqueConstraintViolationException::class);
        $user->tenants()->attach($tenant);
    }

    public function test_database_rejects_a_platform_role_as_a_tenant_role(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->expectException(QueryException::class);
        $user->tenants()->attach($tenant, ['role' => 'super_admin']);
    }

    public function test_csrf_protection_rejects_post_without_token_outside_test_bypass(): void
    {
        $this->app['env'] = 'local';
        $this->post('/login', ['email' => 'a@example.test', 'password' => 'x'])->assertStatus(419);
    }

    public function test_session_cookie_has_secure_http_only_and_same_site_flags(): void
    {
        config(['session.secure' => true]);
        $response = $this->get('/login')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_admin_role_does_not_follow_the_user_to_another_membership(): void
    {
        $user = User::factory()->create();
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        $user->tenants()->attach($first, ['role' => TenantRole::Admin->value]);
        $user->tenants()->attach($second, ['role' => TenantRole::ReadOnly->value]);
        $this->actingAs($user)->post('/tenants/'.$first->id.'/select')->assertRedirect();
        $this->patch('/tenants/'.$second->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->patch('/tenants/'.$first->id, ['name' => 'Allowed'])->assertRedirect();
        $this->patch('/tenants/'.$first->id, ['name' => 'Allowed', 'code' => null])->assertSessionHasErrors('code');
        $this->assertSame($first->code, $first->fresh()->code);
    }

    public function test_forwarded_headers_are_accepted_only_from_configured_proxies(): void
    {
        config(['trustedproxy.proxies' => ['10.20.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])
            ->get('http://localhost/login')->assertInertia(fn (Assert $page) => $page->where('urls.login', 'https://localhost/login'));
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.2'])
            ->get('http://localhost/login')->assertInertia(fn (Assert $page) => $page->where('urls.login', 'http://localhost/login'));
    }

    public function test_redis_connection_and_session_storage_round_trip(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Ce test utilise Redis dans la stack PostgreSQL dédiée.');
        }
        config(['session.driver' => 'redis']);
        $store = $this->app['session']->driver('redis');
        $store->start();
        $store->put('tenant_id', 123);
        $store->save();
        $payload = $store->getHandler()->read($store->getId());
        $this->assertStringContainsString('tenant_id', $payload);
        $this->assertTrue((bool) Redis::connection()->ping());
        $store->getHandler()->destroy($store->getId());
    }
}
