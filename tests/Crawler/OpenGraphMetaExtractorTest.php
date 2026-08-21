<?php

namespace App\Tests\Crawler;

use App\Crawler\OpenGraphMetaExtractor;
use PHPUnit\Framework\TestCase;

final class OpenGraphMetaExtractorTest extends TestCase
{
    private OpenGraphMetaExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new OpenGraphMetaExtractor();
    }

    public function testExtractsOpenGraphTagsInPriority(): void
    {
        $html = <<<'HTML'
            <html><head>
                <title>Titre HTML brut</title>
                <meta name="description" content="Description HTML brute">
                <meta property="og:title" content="Titre Open Graph">
                <meta property="og:description" content="Description Open Graph">
                <meta property="og:image" content="https://exemple.com/images/une.jpg">
            </head><body></body></html>
            HTML;

        $meta = $this->extractor->extract($html, 'https://exemple.com/article-1');

        self::assertSame('Titre Open Graph', $meta->title);
        self::assertSame('Description Open Graph', $meta->description);
        self::assertSame('https://exemple.com/images/une.jpg', $meta->image);
    }

    public function testFallsBackToStandardHtmlTagsWhenOpenGraphIsAbsent(): void
    {
        $html = <<<'HTML'
            <html><head>
                <title>Titre HTML brut</title>
                <meta name="description" content="Description HTML brute">
            </head><body></body></html>
            HTML;

        $meta = $this->extractor->extract($html, 'https://exemple.com/article-1');

        self::assertSame('Titre HTML brut', $meta->title);
        self::assertSame('Description HTML brute', $meta->description);
        self::assertNull($meta->image);
    }

    public function testResolvesARelativeImageUrlAgainstThePageUrl(): void
    {
        $html = '<html><head><meta property="og:image" content="/images/une.jpg"></head></html>';

        $meta = $this->extractor->extract($html, 'https://exemple.com/actualites/article-1');

        self::assertSame('https://exemple.com/images/une.jpg', $meta->image);
    }

    public function testCollapsesInternalWhitespaceInTextValues(): void
    {
        $html = '<html><head><title>  Titre   avec   espaces  </title></head></html>';

        $meta = $this->extractor->extract($html, 'https://exemple.com/article-1');

        self::assertSame('Titre avec espaces', $meta->title);
    }

    public function testEmptyHtmlYieldsNoMetadata(): void
    {
        $meta = $this->extractor->extract('', 'https://exemple.com/article-1');

        self::assertTrue($meta->isEmpty());
    }

    public function testPageWithNoUsableTagsYieldsNoMetadata(): void
    {
        $meta = $this->extractor->extract('<html><head></head><body>Rien ici</body></html>', 'https://exemple.com/article-1');

        self::assertTrue($meta->isEmpty());
    }
}
