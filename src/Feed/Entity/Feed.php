<?php

namespace App\Feed\Entity;

use App\Article\Entity\Article;
use App\Feed\Repository\FeedRepository;
use App\Shared\Utils\TimestampableTrait;
use App\Shared\ValueObject\Language;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Flux RSS/Atom d'un média partenaire.
 */
#[ORM\Entity(repositoryClass: FeedRepository::class)]
#[UniqueEntity('urlHash', message: 'Ce flux est déjà enregistré.')]
class Feed
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Nullable : au moment de la création (notamment lorsqu'un visiteur soumet un article par
     * URL, §3.2), le nom du flux n'est pas encore connu tant qu'il n'a pas été interrogé.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(length: 32, unique: true)]
    private string $urlHash;

    #[ORM\Column(enumType: Language::class)]
    private Language $language;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $sponsored = false;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'feed')]
    private Collection $articles;

    public function __construct(string $url, Language $language)
    {
        $this->setUrl($url);
        $this->language = $language;
        $this->articles = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        // md5 conservé à l'identique de l'ancienne application (simple hash de déduplication,
        // pas un usage cryptographique) pour faciliter le rapprochement lors de la migration des
        // données existantes (§11.2 phase 2).
        $this->urlHash = md5($url);

        return $this;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function setLanguage(Language $language): static
    {
        $this->language = $language;

        return $this;
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

    public function toggleActive(): static
    {
        $this->active = !$this->active;

        return $this;
    }

    public function isSponsored(): bool
    {
        return $this->sponsored;
    }

    public function setSponsored(bool $sponsored): static
    {
        $this->sponsored = $sponsored;

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
