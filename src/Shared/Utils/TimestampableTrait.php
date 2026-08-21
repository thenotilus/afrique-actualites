<?php

namespace App\Shared\Utils;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ajoute createdAt/updatedAt à une entité.
 *
 * L'ancienne application n'avait ces colonnes sur aucune entité (Article, Feed, Taxonomy) — voir
 * le constat de dette dans la documentation fonctionnelle (§5). Ce trait évite de dupliquer la
 * mécanique dans chaque entité qui en a besoin.
 */
trait TimestampableTrait
{
    #[ORM\Column]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column]
    protected \DateTimeImmutable $updatedAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    protected function initializeTimestamps(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
