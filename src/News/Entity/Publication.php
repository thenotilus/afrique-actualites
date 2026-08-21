<?php

namespace App\News\Entity;

use App\News\Repository\PublicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Encart/bandeau (publicitaire ou éditorial) inséré dans les listes d'articles, rattaché à une ou
 * plusieurs "Unes".
 */
#[ORM\Entity(repositoryClass: PublicationRepository::class)]
class Publication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $link = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = false;

    /** Affiché sur toutes les pages génériques plutôt que sur une "Une" en particulier. */
    #[ORM\Column]
    private bool $general = false;

    /** @var Collection<int, UserNews> */
    #[ORM\ManyToMany(targetEntity: UserNews::class, inversedBy: 'publications')]
    #[ORM\JoinTable(name: 'publication_user_news')]
    private Collection $newsList;

    public function __construct(string $title, string $content)
    {
        $this->title = $title;
        $this->content = $content;
        $this->newsList = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    public function isGeneral(): bool
    {
        return $this->general;
    }

    public function setGeneral(bool $general): static
    {
        $this->general = $general;

        return $this;
    }

    /** @return Collection<int, UserNews> */
    public function getNewsList(): Collection
    {
        return $this->newsList;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
