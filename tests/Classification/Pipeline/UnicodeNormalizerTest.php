<?php

namespace App\Tests\Classification\Pipeline;

use App\Classification\Pipeline\UnicodeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeNormalizerTest extends TestCase
{
    private UnicodeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new UnicodeNormalizer();
    }

    /** @return iterable<string, array{string, string}> */
    public static function textProvider(): iterable
    {
        yield 'lowercases' => ['ÉLECTION', 'election'];
        yield 'strips diacritics beyond Western Europe' => ['Économie ouest-africaine', 'economie ouest africaine'];
        yield 'reduces punctuation to spaces' => ["Sénégal : l'élection présidentielle", 'senegal l election presidentielle'];
        yield 'collapses repeated whitespace' => ["Mali,   Niger\net Tchad", 'mali niger et tchad'];
        yield 'preserves digits' => ['Sommet du G20 en 2026', 'sommet du g20 en 2026'];
        yield 'empty string stays empty' => ['', ''];
    }

    #[DataProvider('textProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input));
    }
}
