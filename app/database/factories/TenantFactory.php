<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CLIENT_????##')),
            'name' => fake()->company(),
            'is_active' => true,
        ];
    }
}
