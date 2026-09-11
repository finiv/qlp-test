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
 *   - "stop sending" / "take me out" — too easily false-positive on
 *     "stop sending until spring" or "take me out of this thread".
 *
 * Five of the six original patterns (unsubscribe, take me off, remove me,
 * opt out, stop emailing) are also present in FakeFlakyClassifier::guess().
 * That overlap doesn't make this rule redundant: guess() only runs on the
 * classifier's "well-formed JSON" branch, never on its timeout, truncated-
 * fence, or invented-label branches — which is precisely when this net is
 * needed. "stop contacting me" is the one pattern that is genuinely ours,
 * absent from guess() entirely.
 *
 * "don't want any more emails" / "do not want any more emails" were added
 * after a manual audit found a real gap: a customer who writes only "I do
 * not want any more emails." (no "take me off", no other pattern) combined
 * with a classifier failure produced no suppression signal at all, so a
 * later re-enrolment would email them again despite the explicit request.
 * A bare "any more emails" was tried first and reverted: it also matched
 * "Can you send me any more emails about installation?" -- a request for
 * MORE contact, not less. The negation has to be part of the pattern
 * itself, not bolted on afterwards, because "any more emails" alone carries
 * no polarity of its own.
 *
 * Negation handling was tried and reverted. A bounded look-behind for
 * "don't" / "do not" / "won't" / "will not" removed the false positive on
 * "I don't want you to stop emailing me" -- and silently un-matched six of
 * seven short, constructed opt-outs of the form "Don't need it,
 * unsubscribe." or "I don't think so, take me off the list." Those are the
 * two error directions this rule can have, and they are not symmetric: a
 * false positive suppresses someone who then shows up on a manager's card
 * with the letter beside the label, and a human undoes it; a false negative
 * on an explicit refusal means the next campaign step goes to someone who
 * asked us to stop, and nobody sees it. So the rule stays a plain substring
 * match and accepts the false positive on negated phrasing as a known
 * limitation. This is a chosen priority, not error-free detection.
 *
 * The rule only ever *adds* a suppression signal on top of the
 * classifier's own label, never subtracts one. If the classifier returns a
 * valid, in-rubric "unsubscribe" for a sentence it misread, this rule does
 * not veto it: teaching a keyword match to overrule a model's answer would
 * trade one class of false positive for another.
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
        "don't want any more emails",
        'do not want any more emails',
    ];


    public static function matches(string $body): bool
    {
        $haystack = self::normalize(self::stripQuotedHistory($body));

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercases and normalises the curly apostrophe (U+2019) that
     * Outlook/Word silently substitute for a typed straight one, so the
     * "don't want any more emails" pattern still matches text that never
     * had a straight apostrophe in it to begin with.
     */
    private static function normalize(string $text): string
    {
        return str_replace("\u{2019}", "'", mb_strtolower($text));
    }


    /**
     * Truncates the body at the first line that looks like the start of
     * quoted reply history, so that our own earlier message (e.g. one
     * that itself contains the word "unsubscribe"), quoted back at us,
     * can't trigger the rule.
     *
     * Public so App\Jobs\ProcessInboundReplyJob can strip the same quoted
     * history from the body before it ever reaches the classifier — an
     * audit found that a quoted "unsubscribe" footer, which this rule
     * correctly ignores, was still reaching FakeFlakyClassifier::guess()
     * (and, in production, the real model) because nothing stripped the
     * raw body before that call.
     */
    public static function stripQuotedHistory(string $body): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*>/', $line)
                || preg_match('/^\s*-----\s*Original Message/i', $line)
                || preg_match('/^\s*On .+ wrote:\s*$/i', $line)
                || preg_match('/^\s*From:\s*\S+/i', $line)
            ) {
                return implode("\n", array_slice($lines, 0, $index));
            }
        }

        return implode("\n", $lines);
    }
}
