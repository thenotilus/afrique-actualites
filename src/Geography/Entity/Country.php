<?php

namespace App\Geography\Entity;

use App\Article\Entity\Article;
use App\Geography\Repository\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Référentiel pays, support de la page "actualité par pays" (§3.13 de la documentation
 * fonctionnelle : croisement pays × mot-clé + consultation d'archives).
 *
 * Contrairement à l'ancienne application (qui reliait un pays à une `UserNews` de mots-clés
 * associés, un simple détour), le rattachement aux articles est natif — voir `Article::$countries`.
 */
#[ORM\Entity(repositoryClass: CountryRepository::class)]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 5, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $nameFr;

    #[ORM\Column(length: 100)]
    private string $nameEn;

    #[ORM\Column]
    private bool $active = false;

    /** @var Collection<int, Article> */
    #[ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'countries')]
    private Collection $articles;

    public function __construct(string $code, string $nameFr, string $nameEn)
    {
        $this->code = $code;
        $this->nameFr = $nameFr;
        $this->nameEn = $nameEn;
        $this->articles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getNameFr(): string
    {
        return $this->nameFr;
    }

    public function setNameFr(string $nameFr): static
    {
        $this->nameFr = $nameFr;

        return $this;
    }

    public function getNameEn(): string
    {
        return $this->nameEn;
    }

    public function setNameEn(string $nameEn): static
    {
        $this->nameEn = $nameEn;

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

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function __toString(): string
    {
        return $this->nameFr;
    }
}
