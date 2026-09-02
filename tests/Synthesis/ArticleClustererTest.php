<?php

namespace App\Tests\Synthesis;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use App\Synthesis\ArticleClusterer;
use App\Synthesis\Enum\Theme;
use App\Synthesis\Pipeline\ThemeClassifierInterface;
use PHPUnit\Framework\TestCase;

final class ArticleClustererTest extends TestCase
{
    public function testGroupsArticlesByThemeWithLargestClusterFirst(): void
    {
        $feed = new Feed('https://example.com/rss.xml', Language::FRENCH);
        $election1 = new Article('E1', 'https://example.com/e1', $feed, new \DateTimeImmutable());
        $election2 = new Article('E2', 'https://example.com/e2', $feed, new \DateTimeImmutable());
        $economy1 = new Article('C1', 'https://example.com/c1', $feed, new \DateTimeImmutable());

        $themesByTitle = [
            'E1' => Theme::ELECTIONS,
            'E2' => Theme::ELECTIONS,
            'C1' => Theme::ECONOMY,
        ];
        $classifier = new class($themesByTitle) implements ThemeClassifierInterface {
            public function __construct(private readonly array $themesByTitle)
            {
            }

            public function classify(Article $article): Theme
            {
                return $this->themesByTitle[$article->getTitle()];
            }
        };

        $clusters = (new ArticleClusterer($classifier))->cluster([$economy1, $election1, $election2]);

        self::assertSame([Theme::ELECTIONS->value, Theme::ECONOMY->value], array_keys($clusters));
        self::assertSame([$election1, $election2], $clusters[Theme::ELECTIONS->value]);
        self::assertSame([$economy1], $clusters[Theme::ECONOMY->value]);
    }

    public function testReturnsNoClusterForAnEmptyArticleList(): void
    {
        $classifier = new class implements ThemeClassifierInterface {
            public function classify(Article $article): Theme
            {
                throw new \LogicException('Ne devrait jamais être appelé.');
            }
        };

        self::assertSame([], (new ArticleClusterer($classifier))->cluster([]));
    }
}
