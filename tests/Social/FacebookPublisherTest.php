<?php

namespace App\Tests\Social;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Shared\ValueObject\Language;
use App\Social\Exception\FacebookPublishException;
use App\Social\FacebookPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FacebookPublisherTest extends TestCase
{
    public function testPublishesToThePageFeedAndReturnsThePostId(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"id":"123456_789"}', ['http_code' => 200]);
        });

        $postId = $this->makePublisher($httpClient)->publish($this->makeArticle());

        self::assertSame('123456_789', $postId);
        self::assertCount(1, $requests);
        [$method, $url, $options] = $requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://graph.facebook.com/v21.0/123456789/feed', $url);
        // `body` est encodé en `application/x-www-form-urlencoded` par le client HTTP à partir du
        // tableau fourni : on le redécode plutôt que de comparer une chaîne figée.
        parse_str((string) $options['body'], $body);
        self::assertSame('Un titre à partager', $body['message']);
        self::assertSame('https://exemple.com/article-1', $body['link']);
        self::assertSame('page-token', $body['access_token']);
    }

    public function testThrowsOnGraphApiError(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"error":{"message":"Invalid OAuth access token.","type":"OAuthException","code":190}}',
            ['http_code' => 400],
        ));

        $this->expectException(FacebookPublishException::class);
        $this->expectExceptionMessage('Invalid OAuth access token.');

        $this->makePublisher($httpClient)->publish($this->makeArticle());
    }

    public function testThrowsWhenResponseHasNoPostId(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 200]));

        $this->expectException(FacebookPublishException::class);

        $this->makePublisher($httpClient)->publish($this->makeArticle());
    }

    private function makePublisher(MockHttpClient $httpClient): FacebookPublisher
    {
        return new FacebookPublisher($httpClient, '123456789', 'page-token', 'v21.0', 5.0);
    }

    private function makeArticle(): Article
    {
        $feed = new Feed('https://exemple.com/rss.xml', Language::FRENCH);
        $article = new Article('Un titre à partager', 'https://exemple.com/article-1', $feed, new \DateTimeImmutable());
        $article->setDescription('Résumé.');
        $article->setImage('https://exemple.com/image.jpg');

        return $article;
    }
}
