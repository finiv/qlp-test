<?php

namespace Database\Factories;

use App\Models\CampaignEnrollment;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignEnrollmentFactory extends Factory
{
    protected $model = CampaignEnrollment::class;

    public function definition(): array
    {
        return [
            'tenant_id'    => 42,
            'client_id'    => Client::factory(),
            'campaign_id'  => 7,
            'current_step' => 2,
            'status'       => 'active',
            'next_send_at' => now()->addDays(3),
        ];
    }
}
