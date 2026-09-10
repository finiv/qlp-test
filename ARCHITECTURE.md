# Architecture

Internal notes on how the reply pipeline is put together. Written for whoever
picks this up next.

Last updated: 2026-08-05 — I. Sydorenko

---

## Overview

The platform runs drip campaigns against dormant leads for a window
manufacturer in Canada. Outbound mail goes through the `email-gateway`
microservice (Lumen); inbound mail comes back to us as events on the bus.

```
campaign dispatcher ──> SendCampaignStepJob ──> email-gateway ──> Mailgun
                                                                     │
                                                                     ▼
Reply Center <── ProcessInboundReplyJob <── bus (NATS) <── mail webhook handler
```

Everything below the dashed line is what this repository owns. The webhook
handler itself lives in `email-gateway` and is out of scope here — by the time
an event reaches us it has already been normalised into the `reply.received`
shape.

## Multi-tenancy

Every table carries `tenant_id`. Tenant isolation is applied through a global
Eloquent scope registered in `AppServiceProvider::boot()`, which reads the
current tenant from the request or job context. In practice this means you do
not need to filter by `tenant_id` in application code — the scope takes care of
it, and forgetting it is not a security issue the way it would be in a plain
query.

Raw queries (`DB::table`, `DB::statement`) bypass the scope, so those must
filter by tenant explicitly. There are a few of these left in
`SendCampaignStepJob`.

## Event bus

NATS, at-least-once delivery. Two consequences we live with:

- **Duplicates.** Any event can be delivered more than once, including twice
  in parallel to two workers. Consumers must be idempotent.
- **Ordering.** There is no global ordering guarantee. Events for the same
  client can arrive out of order, particularly around retries.

Outbound events are written to an outbox table in the same transaction as the
state change, and a relay publishes them. That part is in the CRM connector
service, not here.

## Deduplication

Handled by `App\Support\IdempotencyGuard`. Each consumed event id is recorded
in `processed_events`, which has a unique index on `(tenant_id, event_id)`.
The uniqueness constraint is what actually does the work: the guard's `exists()`
check is an optimisation to avoid the round trip, and the database is the thing
that makes it correct under concurrency.

If you add a new consumer, use the guard rather than rolling your own — the
correctness argument here is subtle and was worked out once already.

## Queues

Database driver in dev, Redis in production. `after_commit` is enabled globally
in `config/queue.php`, so jobs dispatched inside a transaction are only handed
to a worker once that transaction commits. This bit us once, hence the global
setting rather than per-dispatch `afterCommit()` calls.

Retries: three attempts with backoff, then the dead letter queue. Nothing in
the reply pipeline is safe to retry blindly, which is why the guard exists.

## Classification

`App\Contracts\SentimentClassifier` wraps the LLM call. Production
implementation goes to OpenAI through Prism with the prompt versioned in the
admin panel; `FakeFlakyClassifier` stands in for it locally and in tests.

The classifier owns its own reliability: it retries twice internally on
transport errors and on malformed output, and it validates the label against
the rubric before returning. Callers get a label from the agreed set or an
exception — they do not need to parse or validate anything themselves.

Rubric: `interested`, `question`, `not_now`, `unsubscribe`, `wrong_person`,
`auto_reply`.

Cost note: we classify every inbound reply. At current volume this is a few
dollars a month and not worth optimising.

## Reply Center

`reply_tasks` is the manager-facing queue. The admin UI (Vue 3 + PrimeVue)
lists open tasks filtered by tenant and status, which is what the composite
index on `(tenant_id, status)` is for. Tasks are closed manually by managers;
nothing closes them automatically.

`sentiment` is nullable on purpose — a task can exist before it has been
classified.

## Suppression

`clients.suppressed_at` is the single source of truth for "do not email this
person". It is set from three places: the support desk, the CRM connector when
a suppression lands in the CRM, and the reply pipeline when a client asks to be
removed.

`SendCampaignStepJob` checks it before sending. The Mailgun-side suppression
list is not wired up yet — it is on the list.

## Local data

`DemoSeeder` creates the clients referenced by
`tests/Fixtures/inbound_events.json`. The fixture is three days of real inbound
events from production, with addresses and names replaced.

## Known gaps

- No backpressure on the classifier: a burst of replies means a burst of API
  calls.
- `reply_tasks.body` stores the full reply text with no size limit.
- No foreign keys anywhere. Was going to add them with the next migration.
