<?php

namespace App\Jobs;

use App\Contracts\SentimentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Models\CampaignEnrollment;
use App\Models\Client;
use App\Models\ReplyTask;
use App\Support\SentimentParser;
use App\Support\UnsubscribeRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consumes reply.received events from the bus.
 *
 * There is no global tenant scope in this app (AppServiceProvider::boot()
 * is empty, despite what ARCHITECTURE.md claims), so every query in this
 * job carries an explicit where('tenant_id', ...).
 */
class ProcessInboundReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array $payload decoded reply.received event
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(SentimentClassifier $classifier): void
    {
        // --- Step 1: validate -------------------------------------------------
        $eventId  = $this->payload['event_id']  ?? null;
        $tenantId = $this->payload['tenant_id'] ?? null;
        $sender   = $this->payload['sender']    ?? null;

        if (!is_string($eventId) || $eventId === ''
            || !is_int($tenantId)
            || !is_string($sender) || $sender === ''
        ) {
            Log::error('Malformed reply.received event', [
                'event_id'  => $eventId,
                'tenant_id' => $tenantId,
                'sender'    => $sender,
            ]);

            // Never substitute a default: an event with a blank event_id
            // would claim the row (tenant, '') and every later malformed
            // event would then be silently swallowed as a duplicate of it.
            $this->fail(new \InvalidArgumentException('Malformed reply.received event payload'));

            return;
        }

        $recipient = $this->payload['recipient'] ?? '';

        // --- Step 2: guards, before touching the database ----------------------
        // These run before client resolution: the checks are cheap, resolution
        // is a query, and the log reason must say bounce/loop rather than
        // no_client.
        $localPart = mb_strtolower(strtok($sender, '@') ?: $sender);

        if (in_array($localPart, ['mailer-daemon', 'postmaster'], true)) {
            $this->claimEvent($tenantId, $eventId);
            Log::info('Discarding bounce', ['tenant_id' => $tenantId, 'event_id' => $eventId, 'reason' => 'bounce']);

            return;
        }

        if (mb_strtolower($sender) === mb_strtolower($recipient)) {
            $this->claimEvent($tenantId, $eventId);
            Log::info('Discarding loop', ['tenant_id' => $tenantId, 'event_id' => $eventId, 'reason' => 'loop']);

            return;
        }

        // --- Step 3: resolve the client ----------------------------------------
        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('email', mb_strtolower($sender))
            ->first();

        if (!$client) {
            // Deliberately asymmetric with Step 2: bounce/loop are permanent
            // properties of the event, so consuming them forever is right. A
            // missing client is temporal -- events may arrive out of order, so
            // a reply can genuinely arrive before the client row exists.
            // Claiming the event here would make it unreplayable once the
            // client is created, silently losing the reply forever.
            Log::warning('No client for reply', [
                'tenant_id' => $tenantId,
                'event_id'  => $eventId,
                'sender'    => $sender,
            ]);

            return;
        }

        // --- Step 4: body extraction --------------------------------------------
        $bodyPlain = $this->payload['body_plain'] ?? null;

        if (is_string($bodyPlain) && $bodyPlain !== '') {
            $body = $bodyPlain;
        } else {
            $body = trim(html_entity_decode(strip_tags($this->payload['body_html'] ?? '')));
        }

        $bodyEmpty = $body === '';

        // --- Step 5: machine-generated reply ------------------------------------
        if ($this->isMachineGenerated($this->payload['headers'] ?? [])) {
            $this->claimEvent($tenantId, $eventId, function () use ($tenantId, $client, $eventId, $body) {
                ReplyTask::create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'event_id'  => $eventId,
                    'sentiment' => 'auto_reply',
                    'body'      => $body,
                    'status'    => 'open',
                ]);
            });

            // A robot's out-of-office is not a human withdrawing from the
            // conversation: do not call the classifier, do not pause the
            // campaign, do not suppress.
            return;
        }

        // --- Step 5b through Step 8: claim, pause, classify, write -- ONE ------
        // transaction, deliberately including the classifier call.
        //
        // An earlier version claimed in its own committed statement (visible
        // to other workers immediately) and used a catch(\Throwable) block to
        // delete that claim if anything later failed. That handles a thrown
        // exception, but not a worker that dies outright -- SIGKILL, an
        // OOM-kill, a crashed container -- because no userspace code, catch
        // blocks included, runs after that signal. Verified directly: killing
        // a worker mid-classify() left processed_events claimed with zero
        // reply_tasks, permanently -- two more redeliveries of the same event
        // (the bus's own at-least-once retry, which is exactly what's
        // supposed to happen when a worker dies mid-job) still produced zero
        // tasks. That is the one outcome this whole pipeline exists to
        // prevent: a customer's reply lost with no trace.
        //
        // Wrapping everything in one transaction fixes both failure modes at
        // once, because Postgres itself -- not our code -- provides the
        // safety net:
        //   - A crash anywhere in this block means the transaction never
        //     commits. Postgres rolls back everything, claim included, the
        //     moment the connection dies. A later redelivery sees the event
        //     as never having been touched and reprocesses it from scratch.
        //   - A genuinely concurrent duplicate's own claim INSERT, for the
        //     same (tenant_id, event_id), blocks on Postgres's row lock for
        //     as long as this transaction stays open -- it does not resolve
        //     to "0 rows, someone else has it" the instant it's issued the
        //     way two back-to-back committed statements would. If this
        //     transaction commits, the other worker's insert then resolves
        //     as a real conflict and it does nothing. If this transaction
        //     never commits (crash), the other worker's insert proceeds as
        //     if there were no conflict, and it becomes the one that fully
        //     processes the event. Verified directly: a session held an
        //     uncommitted row for 4s while a second session's own
        //     `INSERT ... ON CONFLICT DO NOTHING` blocked for that entire
        //     window, then succeeded once the first session was gone.
        //
        // The real cost: a slow or hanging classifier call now ties up a DB
        // connection and this transaction's locks for as long as it takes to
        // answer, where it used to run outside any transaction entirely.
        // Under load with a real LLM this is worse than the split-transaction
        // version it replaces. It was worth it anyway -- the alternative's
        // failure mode was silent, permanent loss of a customer's reply,
        // which is strictly worse than a slower worker. A production system
        // under real load might instead keep the split-transaction shape and
        // add a reconciliation job that finds processed_events rows with no
        // matching reply_tasks row and requeues them; that's a reasonable
        // direction not implemented here.
        DB::transaction(function () use ($tenantId, $eventId, $client, $classifier, $bodyEmpty, $body) {
            $claimed = DB::table('processed_events')->insertOrIgnore([
                'tenant_id'  => $tenantId,
                'event_id'   => $eventId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($claimed === 0) {
                return;
            }

            // --- Step 6: pause the campaign, BEFORE classifying -----------------
            // The pause follows from the *fact* that a human replied, not from
            // what they said, so it runs before the classifier call, which may
            // be slow or fail. where('status', 'active') keeps this monotonic
            // -- a 'stopped' enrollment never returns to 'active'. Updating ALL
            // matching active rows is intentional: the event carries no campaign
            // identifier (campaign+c42@ encodes only the tenant, In-Reply-To only
            // the step), so when a client sits in several campaigns there is no
            // way to tell which one the reply belongs to, and pausing too much is
            // cheaper than mailing someone who is mid-conversation. The claim
            // above is what makes this safe to run unconditionally: no other
            // copy of this exact event will ever reach this line while this
            // transaction is open, and if it never commits, this update is
            // undone right along with the claim.
            CampaignEnrollment::query()
                ->where('tenant_id', $tenantId)
                ->where('client_id', $client->id)
                ->where('status', 'active')
                ->update(['status' => 'stopped', 'next_send_at' => null, 'updated_at' => now()]);

            // --- Step 7: classify, and decide suppression -----------------------
            $raw = null;

            if (!$bodyEmpty) {
                try {
                    // This try wraps ONLY the classifier call and catches ONLY
                    // ClassifierTimeoutException -- never \Throwable. A broad catch
                    // would disguise a bug in our own code, a dropped database
                    // connection, or an expired API key as "the model failed",
                    // producing a pile of unclassified tasks and no signal at all.
                    //
                    // The body is stripped of quoted reply history before it goes
                    // to the classifier, same as UnsubscribeRule::matches() below.
                    // Without this, a quoted marketing footer ("...to unsubscribe,
                    // click here") that the rule correctly ignores was still
                    // reaching the model, which has no way to know the words
                    // aren't the customer's own.
                    $raw = $classifier->classify(UnsubscribeRule::stripQuotedHistory($body));
                } catch (ClassifierTimeoutException) {
                    $raw = null;
                }
            }

            $label = $raw === null ? null : SentimentParser::parse($raw);

            if ($label === null) {
                // crc32('') % 10 === 0, so an empty body would deterministically
                // produce a timeout and disguise a body-extraction problem as a
                // model failure -- report empty_body explicitly instead.
                $reason = $bodyEmpty
                    ? 'empty_body'
                    : ($raw === null ? 'timeout' : SentimentParser::reasonFor($raw));

                // Never log the raw response or the message body -- that is
                // customer text, and it belongs in reply_tasks.body, inside the
                // tenant's own row, not in a shared log file.
                Log::warning('classifier failed', [
                    'event_id'   => $eventId,
                    'tenant_id'  => $tenantId,
                    'client_id'  => $client->id,
                    'reason'     => $reason,
                    'raw_length' => $raw === null ? 0 : strlen($raw),
                ]);
            }

            // Either signal alone is enough to suppress: the rule overrides
            // whatever the model said, because stopping an enrollment is not
            // equivalent protection -- SendCampaignStepJob only checks
            // enrollment.status and client.suppressed_at, so a new enrollment
            // created tomorrow would start mailing an opted-out person again.
            // Only the suppression flag survives re-enrolment.
            $optOut = UnsubscribeRule::matches($body) || $label === 'unsubscribe';

            if ($optOut) {
                $label = 'unsubscribe';
            }

            // --- Step 8: write ---------------------------------------------------
            ReplyTask::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'event_id'  => $eventId,
                // null is legitimate here: it means "not yet classified".
                'sentiment' => $label,
                'body'      => $body,
                // Always 'open'. ARCHITECTURE.md says the admin UI lists
                // tasks filtered by tenant and status, and we cannot see
                // that UI -- inventing a status value risks a task the
                // manager never sees. The discriminator already exists:
                // sentiment, which is nullable on purpose for "not yet
                // classified".
                'status' => 'open',
            ]);

            if ($optOut) {
                Client::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $client->id)
                    // So an earlier suppression date is never overwritten
                    // with today's.
                    ->whereNull('suppressed_at')
                    ->update(['suppressed_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    /**
     * Claims an event in processed_events and, if this worker is the one
     * that actually claims it, runs $onClaimed inside the same transaction.
     *
     * Used by the two guard paths only (Step 2's bounce/loop discard, Step
     * 5's auto-reply): there, the claim and the entire effect happen
     * together, so a shared transaction is the natural fit. The main path
     * (Step 5b onward) claims first and separately instead, specifically so
     * a losing concurrent duplicate returns before Step 6's campaign pause
     * runs at all -- see the comment at Step 5b for why a read-only check
     * isn't enough for that.
     */
    private function claimEvent(int $tenantId, string $eventId, ?\Closure $onClaimed = null): void
    {
        DB::transaction(function () use ($tenantId, $eventId, $onClaimed) {
            $claimed = DB::table('processed_events')->insertOrIgnore([
                'tenant_id'  => $tenantId,
                'event_id'   => $eventId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($claimed === 0) {
                // Another worker already owns this event.
                return;
            }

            if ($onClaimed !== null) {
                $onClaimed();
            }
        });
    }

    /**
     * Headers are looked up case-insensitively: $payload['headers'] is an
     * associative array with mixed casing in practice.
     */
    private function isMachineGenerated(array $headers): bool
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[mb_strtolower((string) $name)] = $value;
        }

        if (array_key_exists('auto-submitted', $normalized)
            && mb_strtolower((string) $normalized['auto-submitted']) !== 'no'
        ) {
            return true;
        }

        if (array_key_exists('x-autoreply', $normalized)) {
            return true;
        }

        if (array_key_exists('precedence', $normalized)
            && mb_strtolower((string) $normalized['precedence']) === 'bulk'
        ) {
            return true;
        }

        if (array_key_exists('content-type', $normalized)
            && str_contains(mb_strtolower((string) $normalized['content-type']), 'multipart/report')
        ) {
            return true;
        }

        return false;
    }
}
