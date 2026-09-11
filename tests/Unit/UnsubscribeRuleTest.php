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

    // --- Negated phrasing: the accepted false positive ------------------

    public function test_known_limitation_negated_stop_emailing_is_a_false_positive(): void
    {
        // A request to KEEP emailing, flagged as opt-out because "stop
        // emailing" is a substring. Accepted on purpose. Negation handling
        // was tried and reverted -- see the class docblock and the
        // regression tests below for the six explicit refusals it silently
        // un-matched. This sentence is not one people write; those are.
        $this->assertTrue(UnsubscribeRule::matches("I don't want you to stop emailing me."));
    }

    public function test_known_limitation_dont_unsubscribe_me_is_a_false_positive(): void
    {
        // Same limitation, the other phrasing. A wrongly suppressed customer
        // is visible on a manager's card next to this very sentence and is
        // undone by a human; the opposite error is not.
        $this->assertTrue(UnsubscribeRule::matches("Please don't unsubscribe me, I like these."));
    }

    // --- Regression: explicit refusals with a negation word nearby -------

    /**
     * These seven are constructed, not taken from a corpus. Each is a short,
     * explicit refusal in which a negation word happens to sit within a few
     * words of the trigger. A bounded negation look-behind, tried in an
     * earlier commit, un-matched six of the seven. If any of these goes red,
     * negation handling has crept back in.
     */
    #[DataProvider('terseRefusalProvider')]
    public function test_a_terse_refusal_with_a_nearby_negation_word_still_matches(string $body): void
    {
        $this->assertTrue(UnsubscribeRule::matches($body));
    }

    public static function terseRefusalProvider(): array
    {
        return [
            ["Don't need it, unsubscribe."],
            ["don't email, unsubscribe"],
            ["I don't want this, unsubscribe me"],
            ["Won't be needing this, remove me"],
            ["Do not contact, opt out please"],
            ["I don't think so, take me off the list"],
            ["Not interested, don't call, unsubscribe"],
        ];
    }

    public function test_a_curly_apostrophe_on_a_real_refusal_still_matches(): void
    {
        // Outlook/Word substitute U+2019 for the typed apostrophe. The
        // "don't want any more emails" pattern must survive that.
        $this->assertTrue(UnsubscribeRule::matches("I don\u{2019}t want any more emails."));
    }




    public function test_any_more_emails_matches_without_any_other_trigger_word(): void
    {
        // Audit finding: the fixture's real opt-out only matches because of
        // "take me off" -- a customer who writes exactly this sentence and
        // nothing else, combined with a classifier failure, previously
        // produced no suppression signal at all.
        $this->assertTrue(UnsubscribeRule::matches('I do not want any more emails.'));
        $this->assertTrue(UnsubscribeRule::matches("I don't want any more emails."));
    }

    public function test_a_request_for_more_emails_does_not_match(): void
    {
        // Second-round audit finding: a bare "any more emails" pattern (an
        // earlier version of this fix) matched this too -- a request for
        // MORE contact, the opposite of an opt-out. The negation is now
        // baked into the pattern itself for exactly this reason.
        $this->assertFalse(UnsubscribeRule::matches('Can you send me any more emails about installation?'));
    }




    public function test_outlook_style_quote_header_is_stripped(): void
    {
        // Audit finding: stripQuotedHistory() recognised ">", "On ... wrote:"
        // and "-----Original Message-----", but not Outlook's own
        // "From:/Sent:/To:/Subject:" quote block, so a quoted "unsubscribe"
        // in that format still matched.
        $body = "Thanks, we're good for now.\n\n"
            . "From: Sales Team\n"
            . "Sent: Tuesday, September 1, 2026 10:00 AM\n"
            . "To: Customer\n"
            . "Subject: Reminder\n\n"
            . 'If you want to unsubscribe, click here.';

        $this->assertFalse(UnsubscribeRule::matches($body));
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

    public function test_the_fixtures_second_trap_does_not_match(): void
    {
        // evt_01HZ8A0008. The classifier returns a perfectly valid, in-rubric
        // "unsubscribe" for this body -- a customer who only postponed. The rule
        // must stay silent here: it is the deterministic half of the signal, and
        // its silence is what tells a reviewer the suppression came from the
        // model's guess alone rather than from anything the customer wrote.
        $this->assertFalse(UnsubscribeRule::matches(
            'Not this year, our budget is spent. Try us again in the spring.'
        ));
    }

    public function test_trigger_word_below_an_on_wrote_line_is_ignored(): void
    {
        $this->assertFalse(UnsubscribeRule::matches(
            "Sounds good, thanks.\nOn Sep 1, 2026, Sales <sales@example.ca> wrote:\n    Click here to unsubscribe."
        ));
    }

    public function test_trigger_word_below_an_original_message_line_is_ignored(): void
    {
        $this->assertFalse(UnsubscribeRule::matches(
            "Thanks for the quote.\n-----Original Message-----\nTo unsubscribe, follow this link."
        ));
    }
}
