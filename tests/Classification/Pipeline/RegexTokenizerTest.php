<?php

namespace App\Tests\Classification\Pipeline;

use App\Classification\Pipeline\RegexTokenizer;
use PHPUnit\Framework\TestCase;

final class RegexTokenizerTest extends TestCase
{
    public function testTokenizeSplitsOnWhitespaceAndAppliesMinLength(): void
    {
        $tokenizer = new RegexTokenizer(minWordLength: 4);

        self::assertSame(
            ['senegal', 'election', 'presidentielle'],
            $tokenizer->tokenize('senegal election ne presidentielle'),
        );
    }

    public function testTokenizeReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], (new RegexTokenizer())->tokenize(''));
    }

    public function testMinWordLengthIsConfigurable(): void
    {
        $tokenizer = new RegexTokenizer(minWordLength: 2);

        self::assertSame(['g20', 'ue'], $tokenizer->tokenize('g20 ue'));
    }
}
