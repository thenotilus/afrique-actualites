<?php

namespace App\Tests\Synthesis\Pipeline;

use App\Article\Entity\Article;
use App\Classification\Pipeline\UnicodeNormalizer;
use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use App\Synthesis\Enum\Theme;
use App\Synthesis\Pipeline\KeywordDictionaryThemeClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie le choix de clustering documenté sur `KeywordDictionaryThemeClassifier` : un article
 * est classé dans le sous-thème dont le dictionnaire (`Resources/themes/{langue}.yaml`) compte le
 * plus de correspondances dans son titre + sa description normalisés, et retombe sur GENERAL en
 * l'absence de toute correspondance — sur un jeu de titres/descriptions réalistes, comme les
 * autres tests du pipeline (`ClassificationServiceTest`).
 */
final class KeywordDictionaryThemeClassifierTest extends TestCase
{
    private KeywordDictionaryThemeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new KeywordDictionaryThemeClassifier(
            new UnicodeNormalizer(),
            dirname(__DIR__, 3).'/src/Synthesis/Resources/themes',
        );
    }

    public function testClassifiesAnElectionArticle(): void
    {
        $article = $this->makeArticle(
            'Élection présidentielle au Sénégal : les électeurs appelés aux urnes',
            "La campagne électorale s'achève à la veille du scrutin.",
        );

        self::assertSame(Theme::ELECTIONS, $this->classifier->classify($article));
    }

    public function testClassifiesASecurityArticle(): void
    {
        $article = $this->makeArticle(
            'Attaque terroriste dans le nord du Mali',
            "L'armée malienne annonce une opération contre des groupes djihadistes.",
        );

        self::assertSame(Theme::SECURITY, $this->classifier->classify($article));
    }

    public function testClassifiesAFootballArticleAsCultureSport(): void
    {
        $article = $this->makeArticle(
            "Coupe d'Afrique des nations : le Nigeria qualifié",
            "L'équipe nationale s'est imposée lors du championnat de football.",
        );

        self::assertSame(Theme::CULTURE_SPORT, $this->classifier->classify($article));
    }

    public function testFallsBackToGeneralWhenNoKeywordMatches(): void
    {
        $article = $this->makeArticle('Un chat retrouve son propriétaire après dix jours', 'Une histoire insolite rapportée par des habitants du quartier.');

        self::assertSame(Theme::GENERAL, $this->classifier->classify($article));
    }

    public function testClassifiesAnEnglishArticleUsingTheEnglishDictionary(): void
    {
        $feed = new Feed('https://example.com/en/rss.xml', Language::ENGLISH);
        $article = new Article('Central bank raises interest rates amid inflation concerns', 'https://example.com/a1', $feed, new \DateTimeImmutable());
        $article->setDescription('The economy is showing signs of slowing growth as the budget deficit widens.');

        self::assertSame(Theme::ECONOMY, $this->classifier->classify($article));
    }

    private function makeArticle(string $title, string $description): Article
    {
        $feed = new Feed('https://example.com/fr/rss.xml', Language::FRENCH);
        $article = new Article($title, 'https://example.com/'.md5($title), $feed, new \DateTimeImmutable());
        $article->setDescription($description);

        return $article;
    }
}
