<?php

namespace App\Crawler\Entity;

use App\Crawler\Repository\CrawlAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'une tentative de crawl HTTP réelle (§9.4, observabilité) : un enregistrement par
 * requête effectivement envoyée à un site source, quel que soit son issue. Un refus par
 * `robots.txt` n'en crée pas, faute de requête émise — voir `CrawlerService`.
 *
 * Alimente le tableau de bord par domaine (taux de succès par bot et par domaine, alerte si un
 * domaine bloque systématiquement tous les profils).
 */
#[ORM\Entity(repositoryClass: CrawlAttemptRepository::class)]
class CrawlAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $domain;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(length: 100)]
    private string $botProfileName;

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(nullable: true)]
    private ?int $httpStatusCode = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $domain, string $url, string $botProfileName, bool $success, ?int $httpStatusCode)
    {
        $this->domain = $domain;
        $this->url = $url;
        $this->botProfileName = $botProfileName;
        $this->success = $success;
        $this->httpStatusCode = $httpStatusCode;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getBotProfileName(): string
    {
        return $this->botProfileName;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
