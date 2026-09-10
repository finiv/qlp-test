<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundReplyJob;
use App\Models\CampaignEnrollment;
use App\Models\Client;
use App\Models\ReplyTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ClaimAtomicityTest extends TestCase
{
    private static ?array $fixtureEvents = null;

    /**
     * Returns a fixture event's payload exactly as stored in
     * tests/Fixtures/inbound_events.json, with optional top-level overrides.
     *
     * Bodies must never be invented: FakeFlakyClassifier is deterministic on
     * crc32($body) % 10, so a hand-written lookalike body would silently
     * exercise a different branch than the fixture's real one.
     */
    private function fixturePayload(string $eventId, array $overrides = []): array
    {
        if (self::$fixtureEvents === null) {
            self::$fixtureEvents = json_decode(
                file_get_contents(base_path('tests/Fixtures/inbound_events.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        foreach (self::$fixtureEvents as $event) {
            if ($event['event_id'] === $eventId) {
                return array_replace($event, $overrides);
            }
        }

        throw new \RuntimeException("Fixture event {$eventId} not found");
    }

    /**
     * Seeds a client plus a single active enrollment for it under the given
     * tenant/email, mirroring the shape DemoSeeder produces.
     */
    private function seedActiveClient(int $tenantId, string $email): Client
    {
        $client = Client::factory()->create([
            'tenant_id' => $tenantId,
            'email'     => $email,
        ]);

        CampaignEnrollment::factory()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'status'    => 'active',
        ]);

        return $client;
    }

    public function test_a_failure_while_writing_the_task_rolls_back_the_claim(): void
    {
        $this->seedActiveClient(42, 'm.tremblay@lakesideprop.ca');

        ReplyTask::creating(function (): void {
            throw new RuntimeException('boom');
        });

        $payload = $this->fixturePayload('evt_01HZ8A0002');

        try {
            ProcessInboundReplyJob::dispatchSync($payload);
            $this->fail('Expected the exception from ReplyTask::creating to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // The claim (processed_events insert) and the ReplyTask write happen
        // inside the same DB transaction specifically so a failure here rolls
        // both back together. If the claim alone had survived, the event
        // would be marked processed forever with no task ever created for
        // it -- the customer's reply would be lost silently, with nothing
        // left afterward to show it ever arrived.
        $this->assertSame(0, DB::table('processed_events')->count());
        $this->assertSame(0, ReplyTask::count());
    }

    public function test_a_job_whose_claim_is_already_taken_writes_nothing(): void
    {
        $this->seedActiveClient(42, 'm.tremblay@lakesideprop.ca');

        $payload = $this->fixturePayload('evt_01HZ8A0002');

        // Simulate another worker having already claimed this event.
        DB::table('processed_events')->insert([
            'tenant_id'  => $payload['tenant_id'],
            'event_id'   => $payload['event_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(0, ReplyTask::count());
    }
}
