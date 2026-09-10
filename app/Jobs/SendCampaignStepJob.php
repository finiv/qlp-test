<?php

namespace App\Jobs;

use App\Models\CampaignEnrollment;
use App\Services\MailGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the next step of a drip campaign to a single enrolled client.
 *
 * Scheduled by the campaign dispatcher every 15 minutes for every enrollment
 * whose next_send_at has passed.
 */
class SendCampaignStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $enrollmentId,
    ) {}

    public function handle(MailGateway $mail): void
    {
        $enrollment = CampaignEnrollment::with('client')->find($this->enrollmentId);

        if (!$enrollment || $enrollment->status !== 'active') {
            return;
        }

        $client = $enrollment->client;

        if ($client->isSuppressed()) {
            Log::info('Skipping suppressed client', ['client_id' => $client->id]);
            return;
        }

        $template = $this->resolveTemplate($enrollment);
        $body     = $this->renderBody($template, $client);

        $messageId = $mail->send(
            to: $client->email,
            subject: $template['subject'],
            body: $body,
        );

        DB::table('campaign_enrollments')
            ->where('id', $enrollment->id)
            ->update([
                'current_step' => $enrollment->current_step + 1,
                'next_send_at' => now()->addDays(3),
                'updated_at'   => now(),
            ]);

        Log::info('Campaign step sent', [
            'enrollment_id' => $enrollment->id,
            'message_id'    => $messageId,
        ]);
    }

    private function resolveTemplate(CampaignEnrollment $enrollment): array
    {
        return [
            'subject' => 'Following up on your window quote',
            'body'    => 'Hi {{name}}, just checking in on the quote we sent over.',
        ];
    }

    private function renderBody(array $template, $client): string
    {
        return str_replace('{{name}}', $client->name ?? 'there', $template['body']);
    }
}
