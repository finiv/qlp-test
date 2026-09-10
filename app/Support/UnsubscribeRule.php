<?php

namespace App\Support;

/**
 * A deterministic safety net for opt-out detection.
 *
 * It exists because the LLM classifier is unreliable and, on its broken
 * branches (timeout, truncated JSON, an invented label, bare prose), it
 * never gets a chance to run its own `unsubscribe` heuristic at all. This
 * rule is the net that catches those cases regardless of what the
 * classifier did or didn't manage to say.
 *
 * The pattern list is deliberately short — six substrings, all lowercase,
 * matched case-insensitively after quoted reply history is stripped off.
 * Patterns that were considered and rejected, on purpose:
 *
 *   - "do not contact" / "don't contact" — this is a *not now*, not a
 *     withdrawal of consent ("Don't contact me until March"). It also has
 *     a hidden trap: Outlook/Word silently substitute U+2019 (curly
 *     apostrophe) for the typed straight one, and mb_strtolower() does not
 *     normalise that away, so a pattern written with a straight apostrophe
 *     would miss real messages while still (correctly) not matching the
 *     ones we don't want to match anyway.
 *   - "no more emails" — doesn't even match the fixture's genuine opt-out,
 *     which reads "I do not want *any* more emails".
 *   - "stop sending" / "take me out" — too easily false-positive on
 *     "stop sending until spring" or "take me out of this thread".
 *
 * Five of the six patterns kept here (unsubscribe, take me off, remove me,
 * opt out, stop emailing) are also present in FakeFlakyClassifier::guess().
 * That overlap doesn't make this rule redundant: guess() only runs on the
 * classifier's "well-formed JSON" branch, never on its timeout, truncated-
 * fence, or invented-label branches — which is precisely when this net is
 * needed. "stop contacting me" is the one pattern that is genuinely ours,
 * absent from guess() entirely.
 */
class UnsubscribeRule
{
    private const PATTERNS = [
        'unsubscribe',
        'take me off',
        'remove me',
        'opt out',
        'stop emailing',
        'stop contacting me',
    ];

    public static function matches(string $body): bool
    {
        $haystack = mb_strtolower(self::stripQuotedHistory($body));

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Truncates the body at the first line that looks like the start of
     * quoted reply history, so that our own earlier message (e.g. one
     * that itself contains the word "unsubscribe"), quoted back at us,
     * can't trigger the rule.
     */
    private static function stripQuotedHistory(string $body): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*>/', $line)
                || preg_match('/^\s*-----\s*Original Message/i', $line)
                || preg_match('/^\s*On .+ wrote:\s*$/i', $line)
            ) {
                return implode("\n", array_slice($lines, 0, $index));
            }
        }

        return implode("\n", $lines);
    }
}
