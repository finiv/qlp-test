<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deduplication is only correct if the database enforces it.
     *
     * ARCHITECTURE.md states that `processed_events` has a unique index on
     * (tenant_id, event_id) and that this uniqueness "is what actually does the
     * work". The original migration
     * (2026_08_01_100200_create_processed_events_table.php) declares a plain
     * index(...), not unique — so the claim was never true in the schema.
     *
     * Why the gap is dangerous in a silent way: Laravel's insertOrIgnore()
     * compiles on PostgreSQL to a bare `on conflict do nothing` with NO conflict
     * target. That form needs no unique arbiter, so it never errors and never
     * reports a skipped row for a duplicate — it just inserts. Two concurrent
     * workers handling the same event both "win" the guard, both insert, and
     * both create a reply task. There is no exception, no failed job, no log
     * line: just two tasks for one event, discovered later by a human.
     *
     * The unique index on reply_tasks backs the literal requirement "exactly one
     * task per event", which today is enforced only by application logic while
     * the database happily accepts a second row.
     */
    public function up(): void
    {
        Schema::table('processed_events', function (Blueprint $table) {
            $table->unique(['tenant_id', 'event_id']);
        });

        Schema::table('reply_tasks', function (Blueprint $table) {
            $table->unique(['tenant_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('processed_events', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'event_id']);
        });

        Schema::table('reply_tasks', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'event_id']);
        });
    }
};
