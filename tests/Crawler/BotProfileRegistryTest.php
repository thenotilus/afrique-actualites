<?php

namespace App\Tests\Crawler;

use App\Crawler\BotProfileRegistry;
use PHPUnit\Framework\TestCase;

final class BotProfileRegistryTest extends TestCase
{
    public function testAllReturnsProfilesInDeclarationOrder(): void
    {
        $registry = new BotProfileRegistry([
            ['name' => 'primary', 'user_agent' => 'BotOne/1.0'],
            ['name' => 'secondary', 'user_agent' => 'BotTwo/1.0', 'additional_headers' => ['Accept' => 'text/html'], 'timeout' => 12.0],
        ]);

        $profiles = $registry->all();

        self::assertCount(2, $profiles);
        self::assertSame('primary', $profiles[0]->name);
        self::assertSame('BotOne/1.0', $profiles[0]->userAgent);
        self::assertSame(8.0, $profiles[0]->timeoutSeconds);
        self::assertSame('secondary', $profiles[1]->name);
        self::assertSame(['Accept' => 'text/html'], $profiles[1]->additionalHeaders);
        self::assertSame(12.0, $profiles[1]->timeoutSeconds);
    }

    public function testRejectsAnEmptyPool(): void
    {
        $this->expectException(\LogicException::class);

        new BotProfileRegistry([]);
    }
}
