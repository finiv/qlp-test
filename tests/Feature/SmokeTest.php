<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_environment_works(): void
    {
        $client = Client::factory()->create();

        $this->assertTrue(true);
    }
}
