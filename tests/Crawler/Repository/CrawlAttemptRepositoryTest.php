<?php

namespace App\Tests\Crawler\Repository;

use App\Crawler\Entity\CrawlAttempt;
use App\Crawler\Repository\CrawlAttemptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrawlAttemptRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CrawlAttemptRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(CrawlAttemptRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);

        parent::tearDown();
    }

    public function testSuccessRateByDomainAggregatesAcrossBotProfiles(): void
    {
        $this->entityManager->persist(new CrawlAttempt('exemple.com', 'https://exemple.com/a', 'bot-1', true, 200));
        $this->entityManager->persist(new CrawlAttempt('exemple.com', 'https://exemple.com/b', 'bot-2', false, 403));
        $this->entityManager->persist(new CrawlAttempt('exemple.com', 'https://exemple.com/c', 'bot-1', true, 200));
        $this->entityManager->persist(new CrawlAttempt('bloque.com', 'https://bloque.com/a', 'bot-1', false, 403));
        $this->entityManager->flush();

        $rows = $this->repository->successRateByDomain();

        self::assertSame([
            ['domain' => 'bloque.com', 'total' => 1, 'success' => 0],
            ['domain' => 'exemple.com', 'total' => 3, 'success' => 2],
        ], $rows);
    }

    public function testEmptyLogYieldsNoRows(): void
    {
        self::assertSame([], $this->repository->successRateByDomain());
    }
}
