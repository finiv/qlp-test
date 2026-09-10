<?php

namespace Tests\Unit;

use App\Support\UnsubscribeRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnsubscribeRuleTest extends TestCase
{
    public function test_the_fixtures_real_opt_out_matches(): void
    {
        $body = 'Please take me off your list. I do not want any more emails about this.';

        $this->assertTrue(UnsubscribeRule::matches($body));
    }

    public function test_dont_contact_me_until_march_does_not_match_with_straight_apostrophe(): void
    {
        $this->assertFalse(UnsubscribeRule::matches("Don't contact me until March."));
    }

    public function test_dont_contact_me_until_march_does_not_match_with_curly_apostrophe(): void
    {
        // U+2019 is what Outlook/Word actually substitute for a typed straight
        // apostrophe. mb_strtolower() does not normalise it, so this exercises
        // a distinct code path from the straight-apostrophe test above.
        $this->assertFalse(UnsubscribeRule::matches("Don\u{2019}t contact me until March."));
    }

    public function test_trigger_word_inside_quoted_history_is_ignored(): void
    {
        $body = "Thanks, but this isn't a fit for us right now.\n"
            . "> On Mon, Jan 5, 2026 at 10:00 AM, Sales Team wrote:\n"
            . "> If you'd like to unsubscribe from these updates, let us know.";

        $this->assertFalse(UnsubscribeRule::matches($body));
    }

    public function test_stop_contacting_me_matches(): void
    {
        // "stop contacting me" is absent from FakeFlakyClassifier::guess(),
        // so this proves the rule is not merely a copy of the fake's own
        // heuristic — it earns its place independently.
        $this->assertTrue(UnsubscribeRule::matches('Please stop contacting me.'));
    }

    public function test_known_limitation_negated_stop_emailing_is_a_false_positive(): void
    {
        // Known limitation, accepted on purpose: this sentence is a request
        // to KEEP emailing, but "stop emailing" still matches as a substring.
        // We do not add negation handling to "fix" it, because the sentence
        // is not one people write in practice.
        $this->assertTrue(UnsubscribeRule::matches("I don't want you to stop emailing me."));
    }

    // --- A little extra breadth over the pattern list ---------------------

    #[DataProvider('triggerPhraseProvider')]
    public function test_each_pattern_is_detected_in_context(string $body): void
    {
        $this->assertTrue(UnsubscribeRule::matches($body));
    }

    public static function triggerPhraseProvider(): array
    {
        return [
            'unsubscribe'         => ['Please unsubscribe me from this list.'],
            'take me off'         => ['Take me off this mailing list, thanks.'],
            'remove me'           => ['Remove me from your list immediately.'],
            'opt out'             => ['I would like to opt out of these emails.'],
            'stop emailing'       => ['Please stop emailing this address.'],
        ];
    }

    public function test_plain_body_without_trigger_words_does_not_match(): void
    {
        $this->assertFalse(UnsubscribeRule::matches('How much would it be for eight windows on the second floor?'));
    }
}
