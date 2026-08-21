<?php

namespace App\Tests\Crawler;

use App\Crawler\RobotsTxtChecker;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RobotsTxtCheckerTest extends \PHPUnit\Framework\TestCase
{
    public function testDisallowedPathIsRejected(): void
    {
        $checker = $this->makeChecker(new MockResponse(
            "User-agent: *\nDisallow: /premium/\nDisallow: /admin\n",
        ));

        self::assertFalse($checker->isAllowed('https://exemple.com/premium/article-1', 'AfriqueActualitesBot/1.0'));
        self::assertTrue($checker->isAllowed('https://exemple.com/actualites/article-1', 'AfriqueActualitesBot/1.0'));
    }

    public function testMoreSpecificAllowOverridesADisallowPrefix(): void
    {
        $checker = $this->makeChecker(new MockResponse(
            "User-agent: *\nDisallow: /premium/\nAllow: /premium/gratuit/\n",
        ));

        self::assertFalse($checker->isAllowed('https://exemple.com/premium/article-1', 'AfriqueActualitesBot/1.0'));
        self::assertTrue($checker->isAllowed('https://exemple.com/premium/gratuit/article-1', 'AfriqueActualitesBot/1.0'));
    }

    public function testRulesOutsideTheWildcardGroupAreIgnored(): void
    {
        $checker = $this->makeChecker(new MockResponse(
            "User-agent: GoogleBot\nDisallow: /premium/\n\nUser-agent: *\nDisallow: /admin\n",
        ));

        // Le groupe "GoogleBot" ne nous concerne pas : seul "/admin" (groupe *) doit être bloqué.
        self::assertTrue($checker->isAllowed('https://exemple.com/premium/article-1', 'AfriqueActualitesBot/1.0'));
        self::assertFalse($checker->isAllowed('https://exemple.com/admin', 'AfriqueActualitesBot/1.0'));
    }

    public function testMissingRobotsTxtAllowsEverything(): void
    {
        $checker = $this->makeChecker(new MockResponse('', ['http_code' => 404]));

        self::assertTrue($checker->isAllowed('https://exemple.com/quoi-que-ce-soit', 'AfriqueActualitesBot/1.0'));
    }

    public function testTransportFailureAllowsEverything(): void
    {
        $checker = $this->makeChecker(new MockResponse('', ['error' => 'Connection timed out']));

        self::assertTrue($checker->isAllowed('https://exemple.com/quoi-que-ce-soit', 'AfriqueActualitesBot/1.0'));
    }

    public function testRulesAreFetchedOnlyOncePerHost(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse("User-agent: *\nDisallow: /premium/\n");
        });
        $checker = new RobotsTxtChecker($httpClient, new NullLogger());

        $checker->isAllowed('https://exemple.com/a', 'Bot/1.0');
        $checker->isAllowed('https://exemple.com/b', 'Bot/1.0');
        $checker->isAllowed('https://exemple.com/premium/c', 'Bot/1.0');

        self::assertSame(1, $requestCount);
    }

    private function makeChecker(MockResponse $robotsTxtResponse): RobotsTxtChecker
    {
        return new RobotsTxtChecker(new MockHttpClient($robotsTxtResponse), new NullLogger());
    }
}
