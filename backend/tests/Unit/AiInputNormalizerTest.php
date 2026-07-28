<?php

namespace Tests\Unit;

use App\Services\AI\AiInputNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Phase G4C.2C-2 — deterministic, provider-independent input normalisation.
 */
class AiInputNormalizerTest extends TestCase
{
    public function test_identical_content_with_different_whitespace_measures_identically(): void
    {
        $tight = 'Clause 4.9.1 requires payment within 14 days.';
        $loose = "Clause 4.9.1   requires\n\n\npayment   within\t14 days.\n\n";

        $this->assertSame(
            AiInputNormalizer::normalizedCharCount($tight),
            AiInputNormalizer::normalizedCharCount($loose)
        );
    }

    public function test_line_ending_style_does_not_affect_measurement(): void
    {
        $unix = "Line one.\nLine two.\nLine three.";
        $windows = "Line one.\r\nLine two.\r\nLine three.";
        $mac = "Line one.\rLine two.\rLine three.";

        $count = AiInputNormalizer::normalizedCharCount($unix);
        $this->assertSame($count, AiInputNormalizer::normalizedCharCount($windows));
        $this->assertSame($count, AiInputNormalizer::normalizedCharCount($mac));
    }

    public function test_docx_table_separator_artefact_is_stripped(): void
    {
        // Mirrors ContractAnalysisService::extractTextFromElement()'s real
        // DOCX table-cell join behaviour.
        $withArtefact = 'Item | Quantity | Price';
        $withoutArtefact = 'Item Quantity Price';

        $this->assertSame(
            AiInputNormalizer::normalizedCharCount($withoutArtefact),
            AiInputNormalizer::normalizedCharCount($withArtefact)
        );
    }

    public function test_leading_and_trailing_whitespace_is_trimmed(): void
    {
        $this->assertSame(
            AiInputNormalizer::normalizedCharCount('hello'),
            AiInputNormalizer::normalizedCharCount("   hello   \n\n")
        );
    }

    public function test_empty_input_measures_zero(): void
    {
        $this->assertSame(0, AiInputNormalizer::normalizedCharCount(''));
        $this->assertSame(0, AiInputNormalizer::normalizedCharCount("   \n\t  "));
    }

    public function test_utf8_multibyte_content_is_measured_safely(): void
    {
        // mb_strlen, not strlen — a multibyte character counts as one
        // character, not the number of bytes it occupies. "café ☺" is 6
        // characters (c-a-f-é-space-☺); strlen() would report far more
        // (é and ☺ are multi-byte in UTF-8).
        $this->assertSame(6, AiInputNormalizer::normalizedCharCount('café ☺'));
        $this->assertGreaterThan(6, strlen('café ☺'));
    }

    public function test_normalization_is_stable_and_repeatable(): void
    {
        $text = "Some\r\ncontract   text with | table | artefacts.\n\n\n";

        $first = AiInputNormalizer::normalizedCharCount($text);
        $second = AiInputNormalizer::normalizedCharCount($text);

        $this->assertSame($first, $second);
    }

    public function test_version_constant_is_stable(): void
    {
        $this->assertSame('v1', AiInputNormalizer::VERSION);
    }
}
