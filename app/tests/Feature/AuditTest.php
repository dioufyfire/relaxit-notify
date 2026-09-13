<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_client_admin_only_sees_own_tenant_events(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['role' => TenantRole::Admin->value]);
        Audit::record('tenant.created', $tenant, $user);
        Audit::record('tenant.created', $other);
        Audit::record('auth.failed');
        $this->actingAs($user)->get('/tenants/'.$tenant->id.'/audit')->assertInertia(fn (Assert $page) => $page->component('Audit/Index')->has('events.data', 1)->where('events.data.0.tenant', $tenant->name));
        $this->get('/tenants/'.$other->id.'/audit')->assertForbidden();
        $this->get('/audit')->assertForbidden();
        $user->tenants()->updateExistingPivot($tenant->id, ['role' => TenantRole::ReadOnly->value]);
        $this->get('/tenants/'.$tenant->id.'/audit')->assertForbidden();
    }

    public function test_support_can_read_global_journal_but_cannot_modify_it(): void
    {
        $user = User::factory()->create(['platform_role' => PlatformRole::Support]);
        Audit::record('auth.failed');
        $this->actingAs($user)->get('/audit')->assertOk();
        $this->delete('/audit/1')->assertNotFound();
        $this->patch('/audit/1', ['action' => 'changed'])->assertNotFound();
    }

    public function test_authentication_events_never_store_password_or_submitted_email(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => 'missing-secret@example.test', 'password' => 'super-secret-wrong'])->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/logout')->assertRedirect();
        $this->assertSame(['auth.failed', 'auth.login', 'auth.logout'], AuditEvent::orderBy('id')->pluck('action')->all());
        $log = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('super-secret-wrong', $log);
        $this->assertStringNotContainsString('missing-secret@example.test', $log);
        $this->assertStringNotContainsString('password', $log);
    }

    public function test_tenant_changes_record_field_names_without_contact_values(): void
    {
        $user = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        $this->actingAs($user)->post('/tenants', ['name' => 'Client', 'code' => 'CLIENT'])->assertRedirect();
        $tenant = Tenant::firstOrFail();
        $this->patch('/tenants/'.$tenant->id, ['name' => 'Client', 'phone' => '+221000000000'])->assertRedirect();
        $event = AuditEvent::where('action', 'tenant.updated')->firstOrFail();
        $this->assertSame(['phone'], $event->metadata['fields']);
        $this->assertStringNotContainsString('+221000000000', $event->toJson());
    }

    public function test_audit_metadata_rejects_unapproved_fields_and_events_are_immutable(): void
    {
        Audit::record('api_key.created', metadata: ['application' => 'erp', 'secret' => 'do-not-store', 'password' => 'do-not-store']);
        $event = AuditEvent::firstOrFail();
        $this->assertSame(['application' => 'erp'], $event->metadata);
        $this->expectException(LogicException::class);
        $event->action = 'changed';
        $event->save();
    }
}
