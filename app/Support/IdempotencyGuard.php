<?php

namespace App\Support;

use App\Models\ProcessedEvent;

/**
 * Guards against duplicate processing of bus events.
 *
 * The bus delivers at-least-once, so every consumer must check whether an
 * event has already been handled. Deduplication is enforced at the database
 * level, which makes this guard safe to use from multiple workers running
 * concurrently.
 *
 * Usage:
 *
 *     if ($guard->alreadyProcessed($tenantId, $eventId)) {
 *         return;
 *     }
 *
 *     // ... handle the event ...
 *
 *     $guard->markProcessed($tenantId, $eventId);
 */
class IdempotencyGuard
{
    public function alreadyProcessed(int $tenantId, string $eventId): bool
    {
        return ProcessedEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->exists();
    }

    public function markProcessed(int $tenantId, string $eventId): void
    {
        ProcessedEvent::create([
            'tenant_id' => $tenantId,
            'event_id'  => $eventId,
        ]);
    }
}
