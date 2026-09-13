<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_is_idempotent_and_preserves_existing_details(): void
    {
        $this->seed();
        Tenant::firstOrFail()->update(['name' => 'Nom personnalisé']);
        $this->seed();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('tenants', ['code' => 'GLOBALE_SANTE', 'name' => 'Nom personnalisé']);
    }

    public function test_bootstrap_creates_a_hashed_admin_and_is_idempotent(): void
    {
        $password = 'Test-Only-Password-2026!';
        $this->artisan('relaxit:bootstrap')
            ->expectsQuestion('Nom', 'Admin RelaxIT')
            ->expectsQuestion('Adresse email', 'ADMIN@example.test')
            ->expectsQuestion('Mot de passe (12 caractères minimum)', $password)
            ->expectsQuestion('Confirmez le mot de passe', $password)
            ->assertSuccessful();
        $admin = User::firstOrFail();
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertSame(PlatformRole::SuperAdmin, $admin->platform_role);
        $this->assertNotSame($password, $admin->password);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertDatabaseHas('tenants', ['code' => 'GLOBALE_SANTE']);
        $this->artisan('relaxit:bootstrap')->expectsOutput('Un Super Admin existe déjà. Aucun compte modifié.')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($admin->password, $admin->fresh()->password);
    }

    public function test_existing_account_cannot_be_promoted_by_bootstrap(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);
        $password = 'Test-Only-Password-2026!';
        $this->artisan('relaxit:bootstrap')
            ->expectsQuestion('Nom', 'Admin')
            ->expectsQuestion('Adresse email', $user->email)
            ->expectsQuestion('Mot de passe (12 caractères minimum)', $password)
            ->expectsQuestion('Confirmez le mot de passe', $password)
            ->assertFailed();
        $this->assertNull($user->fresh()->platform_role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_weak_password_is_rejected_without_creating_records(): void
    {
        $this->artisan('relaxit:bootstrap')
            ->expectsQuestion('Nom', 'Admin')
            ->expectsQuestion('Adresse email', 'admin@example.test')
            ->expectsQuestion('Mot de passe (12 caractères minimum)', 'weak')
            ->expectsQuestion('Confirmez le mot de passe', 'weak')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_non_interactive_bootstrap_requires_secure_manual_input(): void
    {
        $this->artisan('relaxit:bootstrap', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
