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

    public function test_negated_stop_emailing_no_longer_false_positives(): void
    {
        // This used to be an accepted false positive (see git history): a
        // request to KEEP emailing, flagged as opt-out purely because "stop
        // emailing" appears as a substring. Once isNegated() exists at all to
        // fix the "don't unsubscribe me" audit finding below, leaving this
        // one case unfixed would be an arbitrary exception, not a decision.
        $this->assertFalse(UnsubscribeRule::matches("I don't want you to stop emailing me."));
    }

    public function test_negated_unsubscribe_does_not_match(): void
    {
        // Audit finding: "don't unsubscribe me" contains the bare
        // "unsubscribe" substring and used to match despite asking for the
        // opposite.
        $this->assertFalse(UnsubscribeRule::matches('Please do not unsubscribe me. I want your offers.'));
        $this->assertFalse(UnsubscribeRule::matches("Don't unsubscribe me, I actually like these emails."));
    }

    public function test_negation_more_than_twenty_characters_before_the_pattern_still_matches(): void
    {
        // isNegated() only looks a short, fixed window back -- it is a
        // safety-net heuristic, not a negation parser. A negation word far
        // enough away that it plainly belongs to a different clause must not
        // suppress a real match.
        $this->assertTrue(UnsubscribeRule::matches(
            "I don't have time to talk right now, but please just unsubscribe me."
        ));
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

    public function test_negated_unsubscribe_matches_with_a_curly_apostrophe(): void
    {
        // Second-round audit finding: NEGATIONS held only the straight
        // apostrophe, so Outlook/Word's silent substitution of U+2019 (the
        // same trap documented in the class docblock for a different
        // pattern) meant this negation went undetected and the bare
        // "unsubscribe" substring matched anyway.
        $this->assertFalse(UnsubscribeRule::matches("Please don\u{2019}t unsubscribe me."));
    }

    public function test_negation_does_not_bleed_across_a_sentence_boundary(): void
    {
        // Second-round audit finding: the negation window looked back a
        // fixed number of characters with no regard for sentence
        // boundaries, so "don't" in an earlier, unrelated sentence
        // cancelled a plain instruction in the next one.
        $this->assertTrue(UnsubscribeRule::matches("I don't want these. Unsubscribe me."));
    }

    public function test_known_limitation_a_valid_classifier_label_is_not_vetoed_by_this_rule(): void
    {
        // FakeFlakyClassifier::guess() does its own bare str_contains() for
        // 'unsubscribe' with no negation awareness of its own, independently
        // of this rule. This rule only ever ADDS a suppression signal on top
        // of the classifier's label, never subtracts one -- so a valid,
        // in-rubric "unsubscribe" the classifier itself misread from a
        // negated sentence is not caught here. See the class docblock.
        $this->assertFalse(UnsubscribeRule::matches("Don't unsubscribe me, I like these emails."));
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
