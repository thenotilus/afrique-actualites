<?php

namespace App\Crawler;

use Symfony\Component\DomCrawler\Crawler as DomCrawler;

/**
 * Extraction Open Graph / Twitter Cards en priorité (§9.4), avec repli sur les balises HTML
 * standard (`<title>`, `<meta name="description">`) lorsqu'une page ne fournit pas ces
 * métadonnées enrichies — cas fréquent sur les sites de presse les plus anciens.
 */
final class OpenGraphMetaExtractor implements MetaExtractorInterface
{
    public function extract(string $html, string $pageUrl): CrawledMeta
    {
        if ('' === trim($html)) {
            return new CrawledMeta();
        }

        $crawler = new DomCrawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        $title = $this->firstMetaContent($crawler, ['og:title', 'twitter:title'])
            ?? $this->firstText($crawler, 'title');
        $description = $this->firstMetaContent($crawler, ['og:description', 'twitter:description'])
            ?? $this->firstMetaContentByName($crawler, 'description');
        $image = $this->resolveUrl($this->firstMetaContent($crawler, ['og:image', 'twitter:image']), $pageUrl);

        return new CrawledMeta(
            $this->normalize($title),
            $this->normalize($description),
            $image,
        );
    }

    /** @param list<string> $properties */
    private function firstMetaContent(DomCrawler $crawler, array $properties): ?string
    {
        foreach ($properties as $property) {
            $node = $crawler->filter(\sprintf('meta[property="%s"]', $property));
            if ($node->count() > 0) {
                return $node->first()->attr('content');
            }
        }

        return null;
    }

    private function firstMetaContentByName(DomCrawler $crawler, string $name): ?string
    {
        $node = $crawler->filter(\sprintf('meta[name="%s"]', $name));

        return $node->count() > 0 ? $node->first()->attr('content') : null;
    }

    private function firstText(DomCrawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        return $node->count() > 0 ? $node->first()->text('') : null;
    }

    private function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function resolveUrl(?string $value, string $pageUrl): ?string
    {
        $value = $this->normalize($value);
        if (null === $value) {
            return null;
        }

        // Une image Open Graph en chemin relatif ("/images/une.jpg") est inutilisable telle
        // quelle côté public : elle doit être résolue par rapport à la page source.
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $base = parse_url($pageUrl);
        if (!isset($base['scheme'], $base['host'])) {
            return $value;
        }

        $baseUrl = \sprintf('%s://%s', $base['scheme'], $base['host']);

        return str_starts_with($value, '/') ? $baseUrl.$value : $baseUrl.'/'.$value;
    }
}
