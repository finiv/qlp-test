<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Thin wrapper over the outbound mail microservice.
 *
 * In the test environment it does not send anything; it only returns a
 * synthetic message id so the rest of the pipeline can be exercised.
 */
class MailGateway
{
    public function send(string $to, string $subject, string $body): string
    {
        return '<' . Str::uuid() . '@mg.ourdomain.com>';
    }
}
