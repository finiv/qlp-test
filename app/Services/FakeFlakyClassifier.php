<?php

namespace App\Services;

use App\Contracts\SentimentClassifier;
use App\Exceptions\ClassifierTimeoutException;

/**
 * Stand-in for the production LLM classifier.
 *
 * Behaviour is deterministic for a given input, so test runs are repeatable.
 * It reproduces the failure modes we see in production: malformed JSON,
 * labels outside the agreed set, and request timeouts.
 */
class FakeFlakyClassifier implements SentimentClassifier
{
    private const LABELS = [
        'interested',
        'question',
        'not_now',
        'unsubscribe',
        'wrong_person',
        'auto_reply',
    ];

    public function classify(string $body): string
    {
        $bucket = crc32($body) % 10;

        if ($bucket === 0) {
            throw new ClassifierTimeoutException();
        }

        if ($bucket === 1) {
            // Model wrapped the answer in a markdown fence and got cut off.
            return "```json\n{\"sentiment\": \"inter";
        }

        if ($bucket === 2) {
            // Model invented a label that is not in the rubric.
            return '{"sentiment": "negative"}';
        }

        if ($bucket === 3) {
            // Model answered in prose instead of JSON.
            return 'The customer seems interested in scheduling a call.';
        }

        return json_encode(['sentiment' => $this->guess($body)]);
    }

    private function guess(string $body): string
    {
        $text = mb_strtolower($body);

        $rules = [
            'unsubscribe'  => ['unsubscribe', 'take me off', 'stop emailing', 'remove me', 'opt out'],
            'auto_reply'   => ['out of office', 'automatic reply', 'on vacation', 'away from my desk'],
            'wrong_person' => ['wrong person', 'no longer with', 'not the right'],
            'not_now'      => ['next year', 'not right now', 'maybe later', 'busy season'],
            'question'     => ['how much', 'what is', 'do you', 'can you', '?'],
        ];

        foreach ($rules as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $label;
                }
            }
        }

        return self::LABELS[crc32($body) % count(self::LABELS)];
    }
}
