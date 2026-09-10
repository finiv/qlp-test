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
 * Negation handling (isNegated()) was added for the same audit: "don't
 * unsubscribe me" and "please do not unsubscribe" both contain the bare
 * "unsubscribe" substring and used to match anyway. It only looks a short,
 * fixed window immediately before the match, cut off at the nearest
 * preceding sentence boundary (., !, ?, newline) so a negation from an
 * earlier, unrelated sentence ("I don't want these. Unsubscribe me.")
 * can't cancel a plain instruction in the current one. This is a
 * safety-net heuristic, not a negation parser, and does not try to be one.
 * It deliberately also flips the one negation case this class used to
 * accept on purpose ("I don't want you to stop emailing me" — see the test
 * for that string): once we're checking for negation at all, silently
 * ignoring one instance of it would be an arbitrary exception, not a
 * design decision.
 *
 * Known, accepted gap: this rule only ever *adds* a suppression signal on
 * top of the classifier's own label, never subtracts one. If the
 * classifier itself (real or fake) returns a valid, in-rubric
 * "unsubscribe" for a negated sentence it misread, this rule does not
 * veto it. Teaching the rule to override a model's own answer would trade
 * one class of false positive for another (a keyword match overruling a
 * genuinely correct classification elsewhere in a longer message), so
 * that trade was not made here.
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

    /**
     * Negation words/phrases that, found immediately before a matched
     * pattern (and within the same sentence), flip a match to a non-match.
     * Kept as short and literal as the pattern list itself.
     */
    private const NEGATIONS = [
        "don't",
        'do not',
        "won't",
        'will not',
    ];

    /**
     * How many characters before a pattern match to scan for a negation
     * word. Long enough to catch "please do not unsubscribe" (14 chars of
     * lead-in), short enough that it won't reach back across an unrelated
     * earlier clause in the same sentence -- and further capped at the
     * nearest sentence boundary regardless (see isNegated()).
     */
    private const NEGATION_WINDOW = 20;

    /**
     * Marks the end of a sentence for the purpose of bounding the negation
     * window. Not used to strip anything -- only to stop isNegated() from
     * looking past it.
     */
    private const SENTENCE_BOUNDARIES = ['.', '!', '?', "\n"];

    public static function matches(string $body): bool
    {
        $haystack = self::normalize(self::stripQuotedHistory($body));

        foreach (self::PATTERNS as $pattern) {
            $offset = 0;

            while (($pos = mb_strpos($haystack, $pattern, $offset)) !== false) {
                if (!self::isNegated($haystack, $pos)) {
                    return true;
                }

                $offset = $pos + 1;
            }
        }

        return false;
    }

    /**
     * Lowercases and normalises the curly apostrophe (U+2019) that
     * Outlook/Word silently substitute for a typed straight one, so
     * NEGATIONS' straight-apostrophe entries ("don't") still match text
     * that never had a straight apostrophe in it to begin with.
     */
    private static function normalize(string $text): string
    {
        return str_replace("\u{2019}", "'", mb_strtolower($text));
    }

    private static function isNegated(string $haystack, int $matchPos): bool
    {
        $start = max(0, $matchPos - self::NEGATION_WINDOW);
        $window = mb_substr($haystack, $start, $matchPos - $start);

        $boundary = null;
        foreach (self::SENTENCE_BOUNDARIES as $marker) {
            $pos = mb_strrpos($window, $marker);
            if ($pos !== false && ($boundary === null || $pos > $boundary)) {
                $boundary = $pos;
            }
        }

        if ($boundary !== null) {
            $window = mb_substr($window, $boundary + 1);
        }

        foreach (self::NEGATIONS as $negation) {
            if (str_contains($window, $negation)) {
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
