<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'public_id' => (string) Str::ulid(),
            'name' => 'Connexion de test',
            'application' => fake()->unique()->lexify('app_??????'),
            'token_hash' => hash('sha256', Str::random(64)),
        ];
    }
}
