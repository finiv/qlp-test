<?php

namespace Database\Seeders;

use App\Models\CampaignEnrollment;
use App\Models\Client;
use Illuminate\Database\Seeder;

/**
 * Seeds the clients referenced by tests/Fixtures/inbound_events.json.
 */
class DemoSeeder extends Seeder
{
    private const CLIENTS = [
        ['tenant_id' => 42, 'email' => 'd.walker@northshore-homes.ca',   'name' => 'Dana',   'step' => 2],
        ['tenant_id' => 42, 'email' => 'm.tremblay@lakesideprop.ca',     'name' => 'Marc',   'step' => 1],
        ['tenant_id' => 42, 'email' => 'r.osei@maplecourt.ca',           'name' => 'Rita',   'step' => 3],
        ['tenant_id' => 42, 'email' => 'j.kowalczyk@bridgeportbuild.ca', 'name' => 'Jan',    'step' => 2],
        ['tenant_id' => 42, 'email' => 's.lindqvist@harbourview.ca',     'name' => 'Sofia',  'step' => 1],
        ['tenant_id' => 42, 'email' => 'a.ferreira@stonegate.ca',        'name' => 'Ana',    'step' => 2],
        ['tenant_id' => 42, 'email' => 'l.beaulieu@ridgetop.ca',         'name' => 'Luc',    'step' => 3],
    ];

    public function run(): void
    {
        foreach (self::CLIENTS as $row) {
            $client = Client::create([
                'tenant_id' => $row['tenant_id'],
                'email'     => $row['email'],
                'name'      => $row['name'],
            ]);

            CampaignEnrollment::create([
                'tenant_id'    => $row['tenant_id'],
                'client_id'    => $client->id,
                'campaign_id'  => 7,
                'current_step' => $row['step'],
                'status'       => 'active',
                'next_send_at' => now()->addDays(3),
            ]);
        }

        // This client asked to be removed a week ago through the support desk.
        $mensah = Client::create([
            'tenant_id'     => 42,
            'email'         => 'k.mensah@fairlawn.ca',
            'name'          => 'Kwame',
            'suppressed_at' => now()->subDays(3),
        ]);

        CampaignEnrollment::create([
            'tenant_id'    => 42,
            'client_id'    => $mensah->id,
            'campaign_id'  => 7,
            'current_step' => 1,
            'status'       => 'stopped',
            'next_send_at' => null,
        ]);
    }
}
