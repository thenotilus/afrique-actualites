<?php

namespace App\Tests\Feed;

use App\Feed\Exception\FeedParseException;
use App\Feed\FeedParser;
use PHPUnit\Framework\TestCase;

/**
 * Parseur de flux (§6) : RSS 2.0 et Atom 1.0, extraction d'image (enclosure/media), tolérance
 * (entrée sans lien ignorée, date illisible) et rejet d'un format non reconnu.
 */
final class FeedParserTest extends TestCase
{
    private FeedParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FeedParser();
    }

    public function testParsesRssItems(): void
    {
        $parsed = $this->parser->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
              <channel>
                <title>Média Test</title>
                <item>
                  <title>Premier article</title>
                  <link>https://exemple.com/a</link>
                  <description>&lt;p&gt;Résumé &lt;b&gt;riche&lt;/b&gt;&lt;/p&gt;</description>
                  <pubDate>Wed, 02 Oct 2002 08:00:00 GMT</pubDate>
                  <enclosure url="https://exemple.com/a.jpg" type="image/jpeg" />
                </item>
                <item>
                  <title>Sans lien</title>
                  <description>ignoré</description>
                </item>
                <item>
                  <title>Image via media:content</title>
                  <link>https://exemple.com/b</link>
                  <media:content url="https://exemple.com/b.png" medium="image" />
                </item>
              </channel>
            </rss>
            XML);

        self::assertSame('Média Test', $parsed->title);
        self::assertCount(2, $parsed->items, "L'entrée sans lien doit être écartée.");

        $first = $parsed->items[0];
        self::assertSame('Premier article', $first->title);
        self::assertSame('https://exemple.com/a', $first->url);
        self::assertSame('Résumé riche', $first->description, 'Le HTML de la description doit être nettoyé.');
        self::assertSame('https://exemple.com/a.jpg', $first->imageUrl);
        self::assertSame('2002-10-02', $first->publishedAt->format('Y-m-d'));

        self::assertSame('https://exemple.com/b.png', $parsed->items[1]->imageUrl);
    }

    public function testParsesAtomEntries(): void
    {
        $parsed = $this->parser->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
              <title>Flux Atom</title>
              <entry>
                <title>Entrée Atom</title>
                <link rel="alternate" href="https://exemple.com/atom-1" />
                <link rel="edit" href="https://exemple.com/edit" />
                <summary>Un résumé Atom</summary>
                <published>2003-12-13T18:30:02Z</published>
                <media:thumbnail url="https://exemple.com/atom-1.jpg" />
              </entry>
            </feed>
            XML);

        self::assertSame('Flux Atom', $parsed->title);
        self::assertCount(1, $parsed->items);

        $item = $parsed->items[0];
        self::assertSame('Entrée Atom', $item->title);
        self::assertSame('https://exemple.com/atom-1', $item->url, 'Le lien alternate doit primer.');
        self::assertSame('Un résumé Atom', $item->description);
        self::assertSame('https://exemple.com/atom-1.jpg', $item->imageUrl);
        self::assertSame('2003-12-13', $item->publishedAt->format('Y-m-d'));
    }

    public function testFallsBackToNowOnUnreadableDate(): void
    {
        $before = new \DateTimeImmutable('-1 minute');
        $parsed = $this->parser->parse(<<<'XML'
            <rss version="2.0"><channel><title>T</title>
              <item><title>Sans date lisible</title><link>https://exemple.com/x</link><pubDate>pas une date</pubDate></item>
            </channel></rss>
            XML);

        self::assertGreaterThanOrEqual($before, $parsed->items[0]->publishedAt);
    }

    public function testThrowsOnInvalidXml(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser->parse('<rss><channel><item></broken>');
    }

    public function testThrowsOnUnknownFormat(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser->parse('<html><body>Pas un flux</body></html>');
    }
}
