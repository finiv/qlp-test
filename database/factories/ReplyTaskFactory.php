<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ReplyTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReplyTaskFactory extends Factory
{
    protected $model = ReplyTask::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 42,
            'client_id' => Client::factory(),
            'event_id'  => 'evt_' . $this->faker->unique()->numerify('##########'),
            'sentiment' => 'interested',
            'body'      => $this->faker->sentence(),
            'status'    => 'open',
        ];
    }
}
