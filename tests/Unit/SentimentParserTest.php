<?php

namespace Tests\Unit;

use App\Support\SentimentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SentimentParserTest extends TestCase
{
    // --- The four shapes FakeFlakyClassifier actually produces ---------

    public function test_well_formed_json_in_rubric_parses_to_the_label(): void
    {
        $this->assertSame('question', SentimentParser::parse('{"sentiment":"question"}'));
    }

    public function test_truncated_markdown_fence_is_unparsable(): void
    {
        // Verbatim string FakeFlakyClassifier::classify() returns on bucket 1:
        // a markdown fence around JSON that got cut off mid-value.
        $raw = "```json\n{\"sentiment\": \"inter";

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('unparsable', SentimentParser::reasonFor($raw));
    }

    public function test_valid_json_with_invented_label_is_out_of_rubric(): void
    {
        // Verbatim string FakeFlakyClassifier::classify() returns on bucket 2.
        $raw = '{"sentiment": "negative"}';

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('label_out_of_rubric', SentimentParser::reasonFor($raw));
    }

    public function test_prose_with_no_json_is_unparsable_even_though_it_contains_a_rubric_word(): void
    {
        // Verbatim string FakeFlakyClassifier::classify() returns on bucket 3.
        // This is the trap: the word "interested" appears in the text, but a
        // label is only trusted when it arrives as JSON's `sentiment` field.
        $raw = 'The customer seems interested in scheduling a call.';

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('unparsable', SentimentParser::reasonFor($raw));
    }

    // --- Every rubric label is accepted ---------------------------------

    #[DataProvider('rubricLabelsProvider')]
    public function test_every_rubric_label_is_accepted(string $label): void
    {
        $raw = json_encode(['sentiment' => $label]);

        $this->assertSame($label, SentimentParser::parse($raw));
    }

    public static function rubricLabelsProvider(): array
    {
        return [
            'interested'   => ['interested'],
            'question'     => ['question'],
            'not_now'      => ['not_now'],
            'unsubscribe'  => ['unsubscribe'],
            'wrong_person' => ['wrong_person'],
            'auto_reply'   => ['auto_reply'],
        ];
    }

    // --- Tolerant of harmless variation ----------------------------------

    public function test_surrounding_whitespace_is_tolerated(): void
    {
        $this->assertSame('interested', SentimentParser::parse("  \n {\"sentiment\":\"interested\"} \n  "));
    }

    public function test_complete_json_wrapped_in_a_markdown_fence_is_tolerated(): void
    {
        $raw = "```json\n{\"sentiment\": \"question\"}\n```";

        $this->assertSame('question', SentimentParser::parse($raw));
    }

    public function test_label_case_and_padding_is_normalised(): void
    {
        $raw = '{"sentiment": " Interested "}';

        $this->assertSame('interested', SentimentParser::parse($raw));
    }

    // --- Intolerant of everything else ------------------------------------

    public function test_non_string_sentiment_number_is_unparsable(): void
    {
        $raw = '{"sentiment": 42}';

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('unparsable', SentimentParser::reasonFor($raw));
    }

    public function test_non_string_sentiment_array_is_unparsable(): void
    {
        $raw = '{"sentiment": ["interested"]}';

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('unparsable', SentimentParser::reasonFor($raw));
    }

    public function test_non_string_sentiment_null_is_unparsable(): void
    {
        $raw = '{"sentiment": null}';

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('unparsable', SentimentParser::reasonFor($raw));
    }

    public function test_empty_string_is_unparsable(): void
    {
        $this->assertNull(SentimentParser::parse(''));
        $this->assertSame('unparsable', SentimentParser::reasonFor(''));
    }

    public function test_missing_sentiment_field_is_unparsable(): void
    {
        $raw = '{"label": "interested"}';

        $this->assertNull(SentimentParser::parse($raw));
        $this->assertSame('unparsable', SentimentParser::reasonFor($raw));
    }
}
