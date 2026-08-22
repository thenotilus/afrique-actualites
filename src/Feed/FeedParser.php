<?php

namespace App\Feed;

use App\Feed\Exception\FeedParseException;
use App\Feed\ValueObject\FeedItem;
use App\Feed\ValueObject\ParsedFeed;

/**
 * Parseur de flux RSS 2.0 et Atom 1.0, sans dépendance externe (ext-simplexml du cœur PHP) : le
 * projet ne tire que `symfony/http-client` et `symfony/dom-crawler` côté récupération (§9.4), on
 * reste dans cette contrainte plutôt que d'ajouter une bibliothèque de lecture de flux.
 *
 * Volontairement tolérant : une entrée sans lien exploitable est ignorée (on ne peut ni la
 * dédupliquer ni la crawler), une date illisible retombe sur l'instant courant, mais un document
 * qui n'est ni du RSS ni de l'Atom lève {@see FeedParseException} — c'est une erreur de
 * configuration du flux, pas un aléa d'entrée.
 */
final class FeedParser
{
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    public function parse(string $xml): ParsedFeed
    {
        $xml = trim($xml);
        if ('' === $xml) {
            throw new FeedParseException('Flux vide.');
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (false === $root) {
            throw new FeedParseException('Flux XML invalide.');
        }

        if (isset($root->channel)) {
            return $this->parseRss($root->channel);
        }

        if ('feed' === $root->getName()) {
            return $this->parseAtom($root);
        }

        throw new FeedParseException(sprintf('Format de flux non reconnu (racine "%s").', $root->getName()));
    }

    private function parseRss(\SimpleXMLElement $channel): ParsedFeed
    {
        $items = [];
        foreach ($channel->item as $item) {
            $url = $this->normalize((string) $item->link);
            if (null === $url) {
                continue;
            }

            $items[] = new FeedItem(
                title: $this->normalize((string) $item->title) ?? $url,
                url: $url,
                description: $this->stripToText((string) $item->description),
                imageUrl: $this->extractRssImage($item),
                publishedAt: $this->parseDate((string) $item->pubDate),
            );
        }

        return new ParsedFeed($this->normalize((string) $channel->title), $items);
    }

    private function parseAtom(\SimpleXMLElement $feed): ParsedFeed
    {
        $items = [];
        foreach ($feed->entry as $entry) {
            $url = $this->extractAtomLink($entry);
            if (null === $url) {
                continue;
            }

            $summary = (string) ('' !== (string) $entry->summary ? $entry->summary : $entry->content);
            $published = '' !== (string) $entry->published ? (string) $entry->published : (string) $entry->updated;

            $items[] = new FeedItem(
                title: $this->normalize((string) $entry->title) ?? $url,
                url: $url,
                description: $this->stripToText($summary),
                imageUrl: $this->extractMediaImage($entry),
                publishedAt: $this->parseDate($published),
            );
        }

        return new ParsedFeed($this->normalize((string) $feed->title), $items);
    }

    private function extractRssImage(\SimpleXMLElement $item): ?string
    {
        foreach ($item->enclosure as $enclosure) {
            $type = (string) $enclosure['type'];
            $url = $this->normalize((string) $enclosure['url']);
            if (null !== $url && ('' === $type || str_starts_with($type, 'image/'))) {
                return $url;
            }
        }

        return $this->extractMediaImage($item);
    }

    private function extractMediaImage(\SimpleXMLElement $node): ?string
    {
        $media = $node->children(self::NS_MEDIA);
        foreach (['content', 'thumbnail'] as $tag) {
            foreach ($media->{$tag} as $element) {
                $url = $this->normalize((string) $element->attributes()->url);
                if (null !== $url) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function extractAtomLink(\SimpleXMLElement $entry): ?string
    {
        $fallback = null;
        foreach ($entry->link as $link) {
            $href = $this->normalize((string) $link['href']);
            if (null === $href) {
                continue;
            }
            $rel = (string) $link['rel'];
            if ('' === $rel || 'alternate' === $rel) {
                return $href;
            }
            $fallback ??= $href;
        }

        return $fallback;
    }

    private function parseDate(string $raw): \DateTimeImmutable
    {
        $raw = trim($raw);
        if ('' !== $raw) {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Exception) {
                // Date illisible : on ne rejette pas l'entrée pour autant (cf. docblock).
            }
        }

        return new \DateTimeImmutable();
    }

    /** Nettoie un contenu potentiellement HTML (descriptions RSS) en texte simple normalisé. */
    private function stripToText(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', trim($text));
    }

    private function normalize(string $value): ?string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $trimmed ? null : $trimmed;
    }
}
