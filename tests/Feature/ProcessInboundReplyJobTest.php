<?php

namespace Tests\Feature;

use App\Contracts\SentimentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Jobs\ProcessInboundReplyJob;
use App\Models\CampaignEnrollment;
use App\Models\Client;
use App\Models\ReplyTask;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessInboundReplyJobTest extends TestCase
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

    public function test_opt_out_is_honoured_even_though_the_classifier_breaks(): void
    {
        $body = 'Please take me off your list. I do not want any more emails about this.';

        // Precondition, pinned down explicitly: if this assertion ever fails,
        // the classifier changed, not our code. crc32($body) % 10 lands on
        // FakeFlakyClassifier's bucket 2, which returns well-formed JSON
        // carrying a label outside the agreed rubric.
        $this->assertSame(
            '{"sentiment": "negative"}',
            app(SentimentClassifier::class)->classify($body)
        );

        $client = $this->seedActiveClient(42, 'r.osei@maplecourt.ca');
        $enrollment = $client->enrollments()->first();

        $payload = $this->fixturePayload('evt_01HZ8A0003');
        $this->assertSame($body, $payload['body_plain']);

        ProcessInboundReplyJob::dispatchSync($payload);

        $client->refresh();
        $enrollment->refresh();

        $this->assertNotNull($client->suppressed_at);

        $task = ReplyTask::sole();
        $this->assertSame('unsubscribe', $task->sentiment);

        $this->assertSame('stopped', $enrollment->status);
    }

    public function test_a_model_only_opt_out_still_suppresses_via_the_html_fallback(): void
    {
        $extractedBody = 'Not this year, our budget is spent. Try us again in the spring.';

        // Precondition: the classifier's guess() for this exact text lands,
        // by the luck of the crc32 bucket, on a *valid, in-rubric*
        // "unsubscribe" -- not on one of its broken branches. If this ever
        // fails, the classifier changed.
        $raw = app(SentimentClassifier::class)->classify($extractedBody);
        $this->assertSame(['sentiment' => 'unsubscribe'], json_decode($raw, true));

        $client = $this->seedActiveClient(42, 'l.beaulieu@ridgetop.ca');
        $enrollment = $client->enrollments()->first();

        $payload = $this->fixturePayload('evt_01HZ8A0008');
        $this->assertSame('', $payload['body_plain']);
        $this->assertStringContainsString($extractedBody, $payload['body_html']);

        ProcessInboundReplyJob::dispatchSync($payload);

        $client->refresh();
        $enrollment->refresh();

        $this->assertNotNull($client->suppressed_at);

        $task = ReplyTask::sole();
        $this->assertSame('unsubscribe', $task->sentiment);
        // reply_tasks.body equalling the extracted plain text simultaneously
        // proves the body_html -> plain text fallback worked.
        $this->assertSame($extractedBody, $task->body);

        $this->assertSame('stopped', $enrollment->status);

        // This customer only postponed -- "try us again in the spring" -- they
        // did not withdraw consent. UnsubscribeRule deliberately stays silent
        // on this text (see
        // UnsubscribeRuleTest::test_the_fixtures_second_trap_does_not_match).
        // The suppression above rests on the model's guess alone. We accept
        // that: it is visible on the reply-task card and a human can undo it.
    }

    public function test_an_auto_reply_is_recognised_from_headers_and_never_reaches_the_classifier(): void
    {
        // The classifier returns "wrong_person" for this exact body (verified
        // separately). shouldNotReceive proves that wrong label never reaches
        // the database, because the header check short-circuits first.
        $this->mock(SentimentClassifier::class)->shouldNotReceive('classify');

        $client = $this->seedActiveClient(42, 'j.kowalczyk@bridgeportbuild.ca');
        $enrollment = $client->enrollments()->first();

        $payload = $this->fixturePayload('evt_01HZ8A0004');
        $this->assertSame('auto-replied', $payload['headers']['Auto-Submitted']);
        $this->assertSame('All', $payload['headers']['X-Auto-Response-Suppress']);

        ProcessInboundReplyJob::dispatchSync($payload);

        $task = ReplyTask::sole();
        $this->assertSame('auto_reply', $task->sentiment);

        $enrollment->refresh();
        $this->assertSame('active', $enrollment->status);

        $client->refresh();
        $this->assertNull($client->suppressed_at);
    }

    public function test_an_unparsable_model_response_parks_the_task_without_losing_anything(): void
    {
        $body = 'Actually, could you send the brochure again? The link expired.';

        $client = $this->seedActiveClient(42, 'k.mensah@fairlawn.ca');
        $enrollment = $client->enrollments()->first();

        $payload = $this->fixturePayload('evt_01HZ8A0012');
        $this->assertSame($body, $payload['body_plain']);

        ProcessInboundReplyJob::dispatchSync($payload);

        $task = ReplyTask::sole();
        $this->assertNull($task->sentiment);
        $this->assertSame('open', $task->status);
        $this->assertSame($body, $task->body);

        $enrollment->refresh();
        $this->assertSame('stopped', $enrollment->status);

        $this->assertSame(1, DB::table('processed_events')->count());
    }

    public function test_a_timeout_behaves_the_same_way_as_an_unparsable_response(): void
    {
        $this->mock(SentimentClassifier::class)
            ->shouldReceive('classify')
            ->andThrow(new ClassifierTimeoutException());

        $client = $this->seedActiveClient(42, 'm.tremblay@lakesideprop.ca');
        $enrollment = $client->enrollments()->first();

        $payload = $this->fixturePayload('evt_01HZ8A0002');

        ProcessInboundReplyJob::dispatchSync($payload);

        $task = ReplyTask::sole();
        $this->assertNull($task->sentiment);
        $this->assertSame('open', $task->status);

        $enrollment->refresh();
        $this->assertSame('stopped', $enrollment->status);

        $this->assertSame(1, DB::table('processed_events')
            ->where('tenant_id', $payload['tenant_id'])
            ->where('event_id', $payload['event_id'])
            ->count());
    }

    public function test_a_duplicate_event_yields_exactly_one_task(): void
    {
        $this->seedActiveClient(42, 'd.walker@northshore-homes.ca');

        $payload = $this->fixturePayload('evt_01HZ8A0001');

        ProcessInboundReplyJob::dispatchSync($payload);
        $firstTaskId = ReplyTask::sole()->id;

        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(1, ReplyTask::count());
        $this->assertSame($firstTaskId, ReplyTask::sole()->id);
        $this->assertSame(1, DB::table('processed_events')->count());
    }

    /**
     * Audit finding: Step 6 (pause every active enrollment) ran unconditionally,
     * before the idempotency claim check. A stale at-least-once redelivery of an
     * event that finished processing long ago -- for a client since re-enrolled
     * in a brand new campaign -- would pause that new, unrelated campaign too.
     * The task-level dedup (asserted below) was never the problem; the campaign
     * side effect escaping it was.
     */
    public function test_a_duplicate_of_an_already_processed_event_does_not_touch_a_newer_enrollment(): void
    {
        $client = $this->seedActiveClient(42, 'd.walker@northshore-homes.ca');
        $originalEnrollment = $client->enrollments()->first();

        $payload = $this->fixturePayload('evt_01HZ8A0001');

        ProcessInboundReplyJob::dispatchSync($payload);

        $originalEnrollment->refresh();
        $this->assertSame('stopped', $originalEnrollment->status);

        // Re-enrolled in a brand new campaign, weeks later.
        $newEnrollment = CampaignEnrollment::factory()->create([
            'tenant_id'   => 42,
            'client_id'   => $client->id,
            'campaign_id' => 99,
            'status'      => 'active',
        ]);

        // The bus redelivers the same, already-claimed event -- at-least-once.
        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(1, ReplyTask::count());

        $newEnrollment->refresh();
        $this->assertSame(
            'active',
            $newEnrollment->status,
            'a stale duplicate of an old reply must not pause a campaign the client was enrolled into afterwards',
        );
    }

    /**
     * Audit finding: the classifier received the raw, un-stripped body, so a
     * quoted marketing footer containing "unsubscribe" -- which UnsubscribeRule
     * correctly ignores -- could still flip the model's own answer. Asserted by
     * mock expectation rather than by picking a FakeFlakyClassifier crc32
     * bucket: if the raw body ever reaches the classifier again, Mockery fails
     * this test outright on the ->with() mismatch.
     */
    public function test_the_classifier_receives_the_body_with_quoted_history_stripped(): void
    {
        $body = "Yes, please call me.\n> To unsubscribe click here.";

        $this->mock(SentimentClassifier::class)
            ->shouldReceive('classify')
            ->once()
            ->with('Yes, please call me.')
            ->andReturn('{"sentiment": "interested"}');

        $client = $this->seedActiveClient(42, 'd.walker@northshore-homes.ca');

        ProcessInboundReplyJob::dispatchSync($this->fixturePayload('evt_01HZ8A0001', [
            'body_plain' => $body,
        ]));

        $task = ReplyTask::sole();
        $this->assertSame('interested', $task->sentiment);
        // The full body, quote included, is still what the manager sees.
        $this->assertSame($body, $task->body);
        $this->assertNull($client->fresh()->suppressed_at);
    }

    /**
     * Audit finding: "I do not want any more emails." alone (no "take me
     * off", no other pattern) combined with a classifier failure produced no
     * suppression signal at all -- closed by adding "any more emails" to
     * UnsubscribeRule's pattern list (see UnsubscribeRuleTest for the
     * rule-level case; this pins the same finding through the full job).
     */
    public function test_opt_out_without_a_keyword_still_suppresses_when_the_classifier_times_out(): void
    {
        $this->mock(SentimentClassifier::class)
            ->shouldReceive('classify')
            ->andThrow(new ClassifierTimeoutException());

        $client = $this->seedActiveClient(42, 'd.walker@northshore-homes.ca');

        ProcessInboundReplyJob::dispatchSync($this->fixturePayload('evt_01HZ8A0001', [
            'body_plain' => 'I do not want any more emails.',
        ]));

        $task = ReplyTask::sole();
        $this->assertSame('unsubscribe', $task->sentiment);

        $this->assertNotNull($client->fresh()->suppressed_at);
    }

    public function test_tenants_do_not_leak(): void
    {
        $client42 = $this->seedActiveClient(42, 'd.walker@northshore-homes.ca');
        $enrollment42 = $client42->enrollments()->first();

        $client43 = $this->seedActiveClient(43, 'd.walker@northshore-homes.ca');

        $payload = $this->fixturePayload('evt_01HZ8A0011');
        $this->assertSame(43, $payload['tenant_id']);
        $this->assertSame('campaign+c43@mg.ourdomain.com', $payload['recipient']);

        ProcessInboundReplyJob::dispatchSync($payload);

        $task = ReplyTask::sole();
        $this->assertSame(43, $task->tenant_id);
        $this->assertSame($client43->id, $task->client_id);

        $this->assertSame(0, ReplyTask::where('tenant_id', 42)->count());

        $enrollment42->refresh();
        $this->assertSame('active', $enrollment42->status);
    }

    #[DataProvider('bounceAndLoopEventIdsProvider')]
    public function test_bounces_and_loops_are_dropped_even_when_a_client_row_exists(string $eventId): void
    {
        $payload = $this->fixturePayload($eventId);

        // Seed a client for that very address. Without it, this event would
        // also be dropped at client resolution (Step 3), so a deleted
        // bounce/loop guard would never even be exercised: the job would
        // fall through, find no client, and quietly return with zero tasks
        // anyway -- leaving this test green for the wrong reason. With the
        // client present, a deleted guard would fall through all the way to
        // classification and WRITE a reply task, which the assertion below
        // catches.
        Client::factory()->create([
            'tenant_id' => $payload['tenant_id'],
            'email'     => mb_strtolower($payload['sender']),
        ]);

        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(0, ReplyTask::count());

        // Consumed on purpose: replaying a bounce/loop event must not
        // re-trigger processing on retry.
        $this->assertSame(1, DB::table('processed_events')->count());
    }

    public static function bounceAndLoopEventIdsProvider(): array
    {
        return [
            'bounce: MAILER-DAEMON'          => ['evt_01HZ8A0007'],
            'loop: sender equals recipient' => ['evt_01HZ8A0009'],
        ];
    }

    public function test_an_event_with_no_resolvable_client_is_not_claimed(): void
    {
        // Deliberately no client seeded for this sender/tenant.
        $payload = $this->fixturePayload('evt_01HZ8A0002');

        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(0, ReplyTask::count());
        // Asymmetric with the bounce/loop test on purpose: a missing client
        // is temporal (the reply may have arrived before the client record
        // was created), so the event must stay replayable -- claiming it
        // here would lose the reply forever once the client does show up.
        $this->assertSame(0, DB::table('processed_events')->count());
    }

    public function test_a_malformed_payload_never_poisons_the_claim_table(): void
    {
        $payload = $this->fixturePayload('evt_01HZ8A0001');
        unset($payload['event_id']);

        // Under QUEUE_CONNECTION=sync (phpunit.xml), $this->fail(...) inside
        // handle() does not throw out to the caller -- it returns quietly.
        // So we assert on observable state, not on an expected exception.
        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(0, DB::table('processed_events')->count());

        // In particular: no row with an empty-string event_id, which would
        // otherwise silently swallow every later malformed event as a
        // duplicate of the first one ever claimed.
        $this->assertSame(
            0,
            DB::table('processed_events')->where('event_id', '')->count()
        );
    }

    /**
     * Out-of-order delivery is a stated property of the bus, so the pipeline's
     * two monotonicity guards have to be pinned down. Without this test,
     * dropping ->whereNull('suppressed_at') from the job passes the whole
     * suite: the flag stays set either way, and only the *date* silently moves
     * to today. That date is the record of when consent was actually
     * withdrawn -- the one field a compliance question would ask about.
     */
    public function test_an_earlier_opt_out_date_is_never_overwritten_by_a_later_reply(): void
    {
        $optedOutAt = now()->subDays(9)->startOfSecond();

        $client = $this->seedActiveClient(42, 'k.mensah@fairlawn.ca');
        $client->forceFill(['suppressed_at' => $optedOutAt])->save();

        // A second, later reply that also reads as an opt-out.
        ProcessInboundReplyJob::dispatchSync($this->fixturePayload('evt_01HZ8A0003', [
            'event_id' => 'evt_later_optout',
            'sender'   => 'k.mensah@fairlawn.ca',
        ]));

        $this->assertTrue(
            $optedOutAt->equalTo($client->fresh()->suppressed_at),
            'the original opt-out timestamp must survive a later reply',
        );
    }

    /**
     * The mirror guard: ->where('status', 'active') on the pause. A stopped
     * enrollment is never touched again, so a late-arriving reply cannot
     * rewrite the scheduling of a campaign that has already been ended.
     */
    public function test_a_stopped_enrollment_is_left_untouched_by_a_later_reply(): void
    {
        $client = $this->seedActiveClient(42, 'k.mensah@fairlawn.ca');

        $sendAt = now()->addDays(3)->startOfSecond();
        CampaignEnrollment::query()->where('client_id', $client->id)
            ->update(['status' => 'stopped', 'next_send_at' => $sendAt]);

        ProcessInboundReplyJob::dispatchSync($this->fixturePayload('evt_01HZ8A0002', [
            'event_id' => 'evt_late_reply',
            'sender'   => 'k.mensah@fairlawn.ca',
        ]));

        $enrollment = CampaignEnrollment::query()->where('client_id', $client->id)->sole();
        $this->assertSame('stopped', $enrollment->status);
        $this->assertTrue(
            $sendAt->equalTo($enrollment->next_send_at),
            'a stopped enrollment must not be rewritten by a later reply',
        );
    }
}
