<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A globally unique email breaks multi-tenancy.
     *
     * 2026_08_01_100000_create_clients_table.php declares unique('email'), which
     * means one person can exist as a client of exactly one tenant across the
     * whole installation. That is wrong: the same person can legitimately be a
     * client of tenant 42 and of tenant 43, and tests/Fixtures/inbound_events.json
     * contains exactly that case — d.walker@northshore-homes.ca appears under
     * both tenants. Uniqueness belongs to the (tenant_id, email) pair.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Resolves to the auto-generated name `clients_email_unique`.
            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique('email');
        });
    }
};
