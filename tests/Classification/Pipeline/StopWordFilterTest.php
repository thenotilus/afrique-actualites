<?php

namespace App\Tests\Classification\Pipeline;

use App\Classification\Pipeline\StopWordFilter;
use App\Shared\ValueObject\Language;
use PHPUnit\Framework\TestCase;

final class StopWordFilterTest extends TestCase
{
    private string $fixturesDirectory;

    protected function setUp(): void
    {
        $this->fixturesDirectory = sys_get_temp_dir().'/stopwords-'.uniqid();
        mkdir($this->fixturesDirectory);
        file_put_contents($this->fixturesDirectory.'/fr.yaml', "- dans\n- pour\n- avec\n");
        file_put_contents($this->fixturesDirectory.'/en.yaml', "- the\n- with\n- from\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesDirectory.'/*.yaml'));
        rmdir($this->fixturesDirectory);
    }

    public function testFilterRemovesStopWordsForTheGivenLanguage(): void
    {
        $filter = new StopWordFilter($this->fixturesDirectory);

        self::assertSame(
            ['senegal', 'election'],
            $filter->filter(['dans', 'senegal', 'pour', 'election', 'avec'], Language::FRENCH),
        );
    }

    public function testFilterUsesADistinctListPerLanguage(): void
    {
        $filter = new StopWordFilter($this->fixturesDirectory);

        self::assertSame(
            ['nigeria', 'election'],
            $filter->filter(['the', 'nigeria', 'with', 'election', 'from'], Language::ENGLISH),
        );
    }

    public function testMissingLanguageFileYieldsNoFiltering(): void
    {
        array_map('unlink', glob($this->fixturesDirectory.'/*.yaml'));
        $filter = new StopWordFilter($this->fixturesDirectory);

        self::assertSame(['dans', 'senegal'], $filter->filter(['dans', 'senegal'], Language::FRENCH));
    }

    public function testRealFrenchAndEnglishResourcesLoadWithoutError(): void
    {
        $filter = new StopWordFilter(dirname(__DIR__, 3).'/src/Classification/Resources/stopwords');

        self::assertSame([], $filter->filter(['alors', 'afin'], Language::FRENCH));
        self::assertSame([], $filter->filter(['about', 'above'], Language::ENGLISH));
    }
}
