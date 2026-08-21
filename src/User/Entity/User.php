<?php

namespace App\User\Entity;

use App\News\Entity\UserNews;
use App\Shared\Utils\TimestampableTrait;
use App\User\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte utilisateur du site.
 *
 * Remplace FOSUserBundle (abandonné) par le composant Security natif de Symfony (§9.1). L'email
 * est l'identifiant de connexion. L'inscription reste facultative pour consulter le site (§12) :
 * cette entité ne sert qu'aux comptes qui créent des "Unes" personnalisées ou accèdent au
 * back-office, pas aux simples abonnés de la newsletter hebdomadaire (voir
 * App\Newsletter\Entity\NewsletterSubscriber, un modèle volontairement distinct).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** Nullable : un compte créé via connexion Facebook n'a pas nécessairement de mot de passe. */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $facebookId = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $twitterUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $facebookUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $websiteUrl = null;

    /**
     * Résumé des mots-clés (identifiants de Taxonomy validées) vus par l'utilisateur sur les 7
     * derniers jours, repris tel quel de l'ancienne application.
     *
     * @var list<int>
     */
    #[ORM\Column(type: 'json')]
    private array $weeklyKeywords = [];

    /** @var Collection<int, UserNews> */
    #[ORM\OneToMany(targetEntity: UserNews::class, mappedBy: 'user')]
    private Collection $userNews;

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->userNews = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->getRoles(), true);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Aucune donnée sensible temporaire à effacer pour l'instant.
    }

    public function getFacebookId(): ?string
    {
        return $this->facebookId;
    }

    public function setFacebookId(?string $facebookId): static
    {
        $this->facebookId = $facebookId;

        return $this;
    }

    public function getTwitterUrl(): ?string
    {
        return $this->twitterUrl;
    }

    public function setTwitterUrl(?string $twitterUrl): static
    {
        $this->twitterUrl = $twitterUrl;

        return $this;
    }

    public function getFacebookUrl(): ?string
    {
        return $this->facebookUrl;
    }

    public function setFacebookUrl(?string $facebookUrl): static
    {
        $this->facebookUrl = $facebookUrl;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    /** @return list<int> */
    public function getWeeklyKeywords(): array
    {
        return $this->weeklyKeywords;
    }

    /** @param list<int> $weeklyKeywords */
    public function setWeeklyKeywords(array $weeklyKeywords): static
    {
        $this->weeklyKeywords = $weeklyKeywords;

        return $this;
    }

    /** @return Collection<int, UserNews> */
    public function getUserNews(): Collection
    {
        return $this->userNews;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
