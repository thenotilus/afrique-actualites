<?php

namespace App\Newsletter\Entity;

use App\Newsletter\Repository\NewsletterSubscriberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Abonné à la newsletter hebdomadaire.
 *
 * Modélisé séparément du compte `User` : le site ne requiert pas d'inscription pour être
 * consulté, et les destinataires actuels de la newsletter (une quarantaine, §12) ne sont pas
 * nécessairement des comptes utilisateur. Cette séparation est la réponse par défaut retenue dans
 * la documentation fonctionnelle (§3.7) ; la question reste listée comme point de suivi (§12.2)
 * auprès du porteur produit et cette entité pourra être fusionnée avec `User` si la réponse va
 * dans ce sens.
 */
#[ORM\Entity(repositoryClass: NewsletterSubscriberRepository::class)]
#[ORM\Table(name: 'sf_newsletter_subscriber')]
#[UniqueEntity('email')]
class NewsletterSubscriber
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $subscribedAt;

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->subscribedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getSubscribedAt(): \DateTimeImmutable
    {
        return $this->subscribedAt;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
