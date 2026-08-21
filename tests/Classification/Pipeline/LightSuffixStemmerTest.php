<?php

namespace App\Tests\Classification\Pipeline;

use App\Classification\Pipeline\LightSuffixStemmer;
use App\Shared\ValueObject\Language;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LightSuffixStemmerTest extends TestCase
{
    private LightSuffixStemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = new LightSuffixStemmer();
    }

    /** @return iterable<string, array{string, string}> */
    public static function frenchProvider(): iterable
    {
        yield 'plural noun' => ['elections', 'election'];
        yield 'already singular' => ['election', 'election'];
        yield 'double s is not a plural marker' => ['stress', 'stress'];
        yield 'stripped stem too short falls back to original (pays)' => ['pays', 'pays'];
        yield 'stripped stem too short falls back to original (mois)' => ['mois', 'mois'];
    }

    #[DataProvider('frenchProvider')]
    public function testStemFrench(string $token, string $expected): void
    {
        self::assertSame($expected, $this->stemmer->stem($token, Language::FRENCH));
    }

    /** @return iterable<string, array{string, string}> */
    public static function englishProvider(): iterable
    {
        yield 'ing suffix' => ['meeting', 'meet'];
        yield 'ed suffix' => ['elected', 'elect'];
        yield 'ies suffix' => ['economies', 'economy'];
        yield 'plural s' => ['elections', 'election'];
        yield 'double s is not a plural marker' => ['congress', 'congress'];
    }

    #[DataProvider('englishProvider')]
    public function testStemEnglish(string $token, string $expected): void
    {
        self::assertSame($expected, $this->stemmer->stem($token, Language::ENGLISH));
    }
}
