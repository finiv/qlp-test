<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => 42,
            'email'         => $this->faker->unique()->safeEmail(),
            'name'          => $this->faker->firstName(),
            'suppressed_at' => null,
        ];
    }

    public function suppressed(): static
    {
        return $this->state(fn () => ['suppressed_at' => now()]);
    }
}
