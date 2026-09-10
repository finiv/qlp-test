<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundReplyJob;
use App\Models\CampaignEnrollment;
use App\Models\Client;
use App\Models\ReplyTask;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK.md requires the handler to be run over every event in
 * tests/Fixtures/inbound_events.json -- three days of real production traffic
 * with names and addresses replaced. This pins the whole outcome, so that a
 * change anywhere in the pipeline has to be argued for rather than absorbed.
 *
 * Eight of the twelve events are not ordinary replies: three are out-of-office
 * autoresponders, one is a bounce, one is our own campaign address writing to
 * itself, one arrives with an empty body_plain and its text in HTML, one is a
 * verbatim duplicate, and one belongs to a tenant whose client we do not have.
 */
class FixtureRunTest extends TestCase
{
    private function runFixture(bool $reversed = false): void
    {
        $this->seed(DemoSeeder::class);

        $events = json_decode(
            file_get_contents(base_path('tests/Fixtures/inbound_events.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($reversed ? array_reverse($events) : $events as $event) {
            ProcessInboundReplyJob::dispatchSync($event);
        }
    }

    /**
     * The bus gives no ordering guarantee, and TASK.md says so explicitly, so
     * the outcome is asserted twice: once in the order the fixture stores the
     * events, once reversed. Anything order-dependent shows up here as a
     * difference between the two runs rather than as a mystery in production.
     */
    public static function deliveryOrders(): array
    {
        return [
            'у порядку файлу' => [false],
            'у зворотному'    => [true],
        ];
    }

    #[DataProvider('deliveryOrders')]
    public function test_the_fixture_produces_one_task_per_actionable_event(bool $reversed): void
    {
        $this->runFixture($reversed);

        // 12 rows, 11 distinct event ids (evt_01HZ8A0001 is delivered twice),
        // minus the bounce, the loop and the event whose client we do not have.
        $this->assertSame(8, ReplyTask::count());

        $this->assertSame(
            ['evt_01HZ8A0001', 'evt_01HZ8A0002', 'evt_01HZ8A0003', 'evt_01HZ8A0004',
             'evt_01HZ8A0005', 'evt_01HZ8A0006', 'evt_01HZ8A0008', 'evt_01HZ8A0012'],
            ReplyTask::query()->orderBy('event_id')->pluck('event_id')->all(),
        );

        // Ten claims, not eleven: the tenant-43 event is deliberately left
        // unclaimed so it can still be replayed once that client exists.
        $this->assertSame(10, DB::table('processed_events')->count());
    }

    #[DataProvider('deliveryOrders')]
    public function test_the_fixture_classification_outcome(bool $reversed): void
    {
        $this->runFixture($reversed);

        $this->assertSame(
            [
                'evt_01HZ8A0001' => null,          // model returned an out-of-rubric label
                'evt_01HZ8A0002' => 'question',
                'evt_01HZ8A0003' => 'unsubscribe', // caught by the rule; the model broke
                'evt_01HZ8A0004' => 'auto_reply',  // header, not model (it says wrong_person)
                'evt_01HZ8A0005' => 'auto_reply',
                'evt_01HZ8A0006' => 'auto_reply',  // header, not model (it says not_now)
                'evt_01HZ8A0008' => 'unsubscribe', // model's own valid label; rule stays silent
                'evt_01HZ8A0012' => null,          // model truncated its JSON mid-value
            ],
            ReplyTask::query()->orderBy('event_id')->pluck('sentiment', 'event_id')->all(),
        );

        // Every manager-facing task is 'open'. The discriminator is sentiment,
        // which is nullable precisely for "not classified yet".
        $this->assertSame(['open'], ReplyTask::query()->distinct()->pluck('status')->all());
    }

    #[DataProvider('deliveryOrders')]
    public function test_the_fixture_suppression_outcome(bool $reversed): void
    {
        $this->seed(DemoSeeder::class);
        $mensahOptedOutAt = Client::query()->where('email', 'k.mensah@fairlawn.ca')->sole()->suppressed_at;

        $events = json_decode(file_get_contents(base_path('tests/Fixtures/inbound_events.json')), true);
        foreach ($reversed ? array_reverse($events) : $events as $event) {
            ProcessInboundReplyJob::dispatchSync($event);
        }

        $this->assertEqualsCanonicalizing(
            [
                'r.osei@maplecourt.ca',    // asked to be removed, in so many words
                'l.beaulieu@ridgetop.ca',  // only postponed -- suppressed on the model's word alone
                'k.mensah@fairlawn.ca',    // already suppressed before this run
            ],
            Client::query()->whereNotNull('suppressed_at')->pluck('email')->all(),
        );

        $this->assertTrue(
            $mensahOptedOutAt->equalTo(Client::query()->where('email', 'k.mensah@fairlawn.ca')->sole()->suppressed_at),
            'a reply that arrives after an opt-out must not rewrite when that opt-out happened',
        );
    }

    #[DataProvider('deliveryOrders')]
    public function test_only_human_replies_stop_a_campaign(bool $reversed): void
    {
        $this->runFixture($reversed);

        $statuses = CampaignEnrollment::query()
            ->join('clients', 'clients.id', '=', 'campaign_enrollments.client_id')
            ->orderBy('clients.email')
            ->pluck('campaign_enrollments.status', 'clients.email')
            ->all();

        $this->assertSame([
            'a.ferreira@stonegate.ca'        => 'active',   // out-of-office: a robot did not withdraw
            'd.walker@northshore-homes.ca'   => 'stopped',
            'j.kowalczyk@bridgeportbuild.ca' => 'active',   // out-of-office
            'k.mensah@fairlawn.ca'           => 'stopped',
            'l.beaulieu@ridgetop.ca'         => 'stopped',
            'm.tremblay@lakesideprop.ca'     => 'stopped',
            'r.osei@maplecourt.ca'           => 'stopped',
            's.lindqvist@harbourview.ca'     => 'active',   // out-of-office
        ], $statuses);
    }
}
