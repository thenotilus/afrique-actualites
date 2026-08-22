<?php

namespace App\News\Entity;

use App\News\Repository\UserNewsRepository;
use App\Taxonomy\Entity\Taxonomy;
use App\User\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Une" thématique : liste nommée de mots-clés créée par un utilisateur, qui génère une page de
 * veille listant les articles associés à au moins un de ces mots-clés.
 */
#[ORM\Entity(repositoryClass: UserNewsRepository::class)]
#[ORM\Table(name: 'sf_user_news')]
class UserNews
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userNews')]
    private ?User $user = null;

    #[ORM\Column]
    private bool $private = false;

    /** @var Collection<int, Taxonomy> Uniquement des taxonomies VALIDATED, voir §10.4. */
    #[ORM\ManyToMany(targetEntity: Taxonomy::class)]
    #[ORM\JoinTable(name: 'sf_user_news_taxonomy')]
    private Collection $taxonomies;

    /** @var Collection<int, Publication> */
    #[ORM\ManyToMany(targetEntity: Publication::class, mappedBy: 'newsList')]
    private Collection $publications;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $modifiedAt;

    public function __construct(string $label, ?User $user = null)
    {
        $this->label = $label;
        $this->user = $user;
        $this->taxonomies = new ArrayCollection();
        $this->publications = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->modifiedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function setPrivate(bool $private): static
    {
        $this->private = $private;

        return $this;
    }

    /** @return Collection<int, Taxonomy> */
    public function getTaxonomies(): Collection
    {
        return $this->taxonomies;
    }

    public function addTaxonomy(Taxonomy $taxonomy): static
    {
        if (!$taxonomy->isValidated()) {
            throw new \LogicException('Seule une taxonomie validée peut être ajoutée à une Une.');
        }

        if (!$this->taxonomies->contains($taxonomy)) {
            $this->taxonomies->add($taxonomy);
        }

        return $this;
    }

    public function removeTaxonomy(Taxonomy $taxonomy): static
    {
        $this->taxonomies->removeElement($taxonomy);

        return $this;
    }

    /** @return Collection<int, Publication> */
    public function getPublications(): Collection
    {
        return $this->publications;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getModifiedAt(): \DateTimeImmutable
    {
        return $this->modifiedAt;
    }

    public function touchModifiedAt(): static
    {
        $this->modifiedAt = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
