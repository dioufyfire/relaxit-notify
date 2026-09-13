<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['role' => TenantRole::Admin->value]);

        return $user;
    }

    private function issue(Tenant $tenant, User $user, string $application = 'dolibarr'): array
    {
        return app(ApiKeys::class)->issue($tenant, $user, ['name' => 'Connexion de test', 'application' => $application]);
    }

    public function test_secret_is_returned_once_and_never_in_lists_session_or_audit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $response = $this->actingAs($user)->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Dolibarr', 'application' => 'dolibarr']);
        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $secret = $response->json('secret');
        $key = ApiKey::firstOrFail();
        $this->assertMatchesRegularExpression('/^rln_[0-9A-HJKMNP-TV-Z]{26}_[a-f0-9]{64}$/', $secret);
        $this->assertSame(hash('sha256', $secret), $key->token_hash);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->assertStringNotContainsString($secret, AuditEvent::all()->toJson());
        $this->assertArrayNotHasKey('token_hash', $key->toArray());
        $this->get('/tenants/'.$tenant->id.'/api-keys')->assertOk()->assertDontSee($secret)->assertDontSee($key->token_hash)
            ->assertInertia(fn (Assert $page) => $page->component('ApiKeys/Index')->has('keys.data', 1)->missing('secret')->missing('keys.data.0.token_hash'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'actor_id' => $user->id, 'action' => 'api_key.created']);
    }

    public function test_only_super_admin_and_client_admin_can_manage_keys(): void
    {
        $tenant = Tenant::factory()->create();
        foreach (TenantRole::cases() as $role) {
            $user = User::factory()->create();
            $user->tenants()->attach($tenant, ['role' => $role->value]);
            $response = $this->actingAs($user)->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Test', 'application' => $role->value]);
            $role === TenantRole::Admin ? $response->assertCreated() : $response->assertForbidden();
        }
        $support = User::factory()->create(['platform_role' => PlatformRole::Support]);
        $this->actingAs($support)->get('/tenants/'.$tenant->id.'/api-keys')->assertOk();
        $this->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Test', 'application' => 'support'])->assertForbidden();
        $super = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        $this->actingAs($super)->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Test', 'application' => 'super'])->assertCreated();
    }

    public function test_cross_tenant_access_and_key_id_substitution_are_denied(): void
    {
        $own = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $user = $this->admin($own);
        $foreign = $this->issue($other, User::factory()->create());
        $this->actingAs($user)->get('/tenants/'.$other->id.'/api-keys')->assertForbidden();
        $this->postJson('/tenants/'.$other->id.'/api-keys', ['name' => 'Intrusion', 'application' => 'x'])->assertForbidden();
        $this->postJson('/tenants/'.$own->id.'/api-keys/'.$foreign['key']->id.'/rotate')->assertNotFound();
        $this->deleteJson('/tenants/'.$own->id.'/api-keys/'.$foreign['key']->id)->assertNotFound();
        $this->assertNull($foreign['key']->fresh()->revoked_at);
    }

    public function test_rotation_immediately_invalidates_old_token_and_preserves_expiration(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $old = $this->issue($tenant, $user);
        $old['key']->expires_at = now()->addDays(10);
        $old['key']->save();
        $this->withToken($old['secret'])->getJson('/api/v1/me')->assertOk();
        $response = $this->actingAs($user)->postJson('/tenants/'.$tenant->id.'/api-keys/'.$old['key']->id.'/rotate')->assertCreated();
        $this->assertNotSame($old['secret'], $response->json('secret'));
        $this->assertNotNull($old['key']->fresh()->revoked_at);
        $this->assertEquals($old['key']->expires_at, ApiKey::findOrFail($response->json('key.id'))->expires_at);
        $this->withToken($old['secret'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($response->json('secret'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('tenant.id', $tenant->id);
        $this->postJson('/tenants/'.$tenant->id.'/api-keys/'.$old['key']->id.'/rotate')->assertStatus(409);
        $this->assertDatabaseCount('api_keys', 2);
    }

    public function test_revocation_is_idempotent_and_frees_the_application_identifier(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $issued = $this->issue($tenant, $user);
        $url = '/tenants/'.$tenant->id.'/api-keys/'.$issued['key']->id;
        $this->actingAs($user)->deleteJson($url)->assertOk();
        $this->deleteJson($url)->assertOk();
        $this->assertSame(1, AuditEvent::where('action', 'api_key.revoked')->count());
        $this->withToken($issued['secret'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Nouvelle connexion', 'application' => 'dolibarr'])->assertCreated();
    }

    public function test_bearer_authentication_never_uses_web_session_or_supplied_tenant(): void
    {
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        $user = $this->admin($second);
        $issued = $this->issue($first, $user);
        $this->actingAs($user)->withSession(['tenant_id' => $second->id])->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me?api_key='.urlencode($issued['secret']))->assertUnauthorized();
        $this->withToken($issued['secret'])->getJson('/api/v1/me?tenant_id='.$second->id)->assertOk()->assertJsonPath('tenant.id', $first->id)->assertJsonMissing(['code' => $second->code]);
        $this->assertNotNull($issued['key']->fresh()->last_used_at);
        $this->assertFalse($this->withToken($issued['secret'])->getJson('/api/v1/me')->headers->has('Set-Cookie'));
    }

    public function test_forged_expired_revoked_and_inactive_tenant_credentials_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $issued = $this->issue($tenant, User::factory()->create());
        $forged = substr($issued['secret'], 0, -64).str_repeat('0', 64);
        $this->withToken($forged)->getJson('/api/v1/me')->assertUnauthorized();
        $issued['key']->expires_at = now()->subSecond();
        $issued['key']->save();
        $this->withToken($issued['secret'])->getJson('/api/v1/me')->assertUnauthorized();
        $issued['key']->expires_at = null;
        $issued['key']->save();
        $tenant->forceFill(['is_active' => false])->save();
        $this->withToken($issued['secret'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_one_non_revoked_key_per_application_and_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $this->issue($tenant, $user);
        $this->actingAs($user)->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Duplicate', 'application' => 'dolibarr'])->assertUnprocessable()->assertJsonValidationErrors('application');
        $this->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Bad', 'application' => '../bad', 'expires_at' => '2000-01-01', 'tenant_id' => 9])->assertUnprocessable()->assertJsonValidationErrors(['application', 'expires_at', 'tenant_id']);
        $this->assertDatabaseCount('api_keys', 1);
    }

    public function test_limits_are_shared_by_keys_of_a_tenant_but_not_other_tenants(): void
    {
        config(['api.requests_per_minute' => 2]);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $first = $this->issue($tenant, $user);
        $second = $this->issue($tenant, $user, 'erp');
        $other = $this->issue(Tenant::factory()->create(), $user);
        $this->withToken($first['secret'])->getJson('/api/v1/me')->assertOk();
        $this->withToken($second['secret'])->getJson('/api/v1/me')->assertOk();
        $this->withToken($first['secret'])->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After');
        $this->withToken($other['secret'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_rotation_rolls_back_if_audit_cannot_be_recorded(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $issued = $this->issue($tenant, $user);
        Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(ApiKeys::class)->rotate($tenant, $issued['key'], $user);
            $this->fail('Rotation should fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertNull($issued['key']->fresh()->revoked_at);
        $this->assertDatabaseCount('api_keys', 1);
    }

    public function test_csrf_is_required_for_key_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->admin($tenant);
        $this->app['env'] = 'local';
        $this->actingAs($user)->postJson('/tenants/'.$tenant->id.'/api-keys', ['name' => 'Test', 'application' => 'test'])->assertStatus(419);
        $this->assertDatabaseCount('api_keys', 0);
    }
}
