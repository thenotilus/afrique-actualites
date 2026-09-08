<?php

namespace App\Tests\Feed;

use App\Feed\Exception\SitemapParseException;
use App\Feed\SitemapParser;
use PHPUnit\Framework\TestCase;

/**
 * Parseur de sitemaps (§6bis) : `<urlset>` (feuille), `<sitemapindex>` (nœud), tolérance sur une
 * `<url>` sans `<loc>` ou une `<lastmod>` illisible, et rejet d'un format non reconnu.
 */
final class SitemapParserTest extends TestCase
{
    private SitemapParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SitemapParser();
    }

    public function testParsesUrlset(): void
    {
        $parsed = $this->parser->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url>
                <loc>https://exemple.com/a</loc>
                <lastmod>2024-03-15</lastmod>
              </url>
              <url>
                <loc>https://exemple.com/b</loc>
              </url>
              <url>
                <lastmod>2024-01-01</lastmod>
              </url>
            </urlset>
            XML);

        self::assertSame([], $parsed->childSitemaps);
        self::assertCount(2, $parsed->entries, "L'entrée sans <loc> doit être écartée.");

        self::assertSame('https://exemple.com/a', $parsed->entries[0]->url);
        self::assertSame('2024-03-15', $parsed->entries[0]->lastmod?->format('Y-m-d'));

        self::assertSame('https://exemple.com/b', $parsed->entries[1]->url);
        self::assertNull($parsed->entries[1]->lastmod, 'Une lastmod absente doit rester null (date inconnue).');
    }

    public function testParsesSitemapIndex(): void
    {
        $parsed = $this->parser->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <sitemap><loc>https://exemple.com/sitemap-2023.xml</loc></sitemap>
              <sitemap><loc>https://exemple.com/sitemap-2024.xml</loc></sitemap>
            </sitemapindex>
            XML);

        self::assertSame([], $parsed->entries);
        self::assertSame(
            ['https://exemple.com/sitemap-2023.xml', 'https://exemple.com/sitemap-2024.xml'],
            $parsed->childSitemaps,
        );
    }

    public function testUnreadableLastmodFallsBackToNull(): void
    {
        $parsed = $this->parser->parse(<<<'XML'
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>https://exemple.com/a</loc><lastmod>pas-une-date</lastmod></url>
            </urlset>
            XML);

        self::assertNull($parsed->entries[0]->lastmod);
    }

    public function testRejectsEmptyDocument(): void
    {
        $this->expectException(SitemapParseException::class);

        $this->parser->parse('   ');
    }

    public function testRejectsInvalidXml(): void
    {
        $this->expectException(SitemapParseException::class);

        $this->parser->parse('<not-xml');
    }

    public function testRejectsUnrecognizedRoot(): void
    {
        $this->expectException(SitemapParseException::class);

        $this->parser->parse('<rss version="2.0"><channel></channel></rss>');
    }
}
