<?php

namespace App\Support;

/**
 * Turns whatever the (flaky) LLM classifier returned into a trustworthy
 * rubric label, or nothing.
 *
 * The upstream classifier can hand back truncated markdown-fenced JSON,
 * well-formed JSON carrying a label outside the agreed rubric, or plain
 * prose with no JSON at all. A label is only ever trusted when it arrives
 * as the `sentiment` field of a successfully decoded JSON object — free
 * text is never scanned for rubric words, since prose can innocently
 * contain them ("the customer seems interested...") without the model
 * actually having committed to that label.
 *
 * This class must never throw. Anything that cannot be trusted maps to
 * null, and the caller falls back accordingly.
 */
class SentimentParser
{
    private const RUBRIC = [
        'interested',
        'question',
        'not_now',
        'unsubscribe',
        'wrong_person',
        'auto_reply',
    ];

    /**
     * @return string|null a rubric label, or null if the response cannot be trusted.
     */
    public static function parse(string $raw): ?string
    {
        $decoded = self::decode($raw);

        if ($decoded === null || !self::hasStringSentiment($decoded)) {
            return null;
        }

        $label = self::normalize($decoded['sentiment']);

        return in_array($label, self::RUBRIC, true) ? $label : null;
    }

    /**
     * Only meant to be called on the failure path (i.e. after parse()
     * returned null), to write a log line explaining why. Re-parses the
     * raw string rather than caching state from a prior parse() call, so
     * the two methods stay independent and the class stays stateless.
     */
    public static function reasonFor(string $raw): string
    {
        $decoded = self::decode($raw);

        if ($decoded !== null && self::hasStringSentiment($decoded)) {
            return 'label_out_of_rubric';
        }

        return 'unparsable';
    }

    /**
     * Strips a leading (and, if present, trailing) markdown code fence,
     * then attempts to JSON-decode the remainder into an associative
     * array. Returns null on any failure — truncated JSON, prose, a
     * non-object payload, etc.
     */
    private static function decode(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private static function hasStringSentiment(array $decoded): bool
    {
        return array_key_exists('sentiment', $decoded) && is_string($decoded['sentiment']);
    }

    private static function normalize(string $sentiment): string
    {
        return mb_strtolower(trim($sentiment));
    }
}
