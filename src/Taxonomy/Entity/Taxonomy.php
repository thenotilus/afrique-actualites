<?php

namespace App\Taxonomy\Entity;

use App\Article\Entity\Article;
use App\Shared\Utils\TimestampableTrait;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\Taxonomy\Repository\TaxonomyRepository;
use App\User\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Mot candidat (suggestion) ou mot-clé validé permettant de classer les articles.
 *
 * Une même chaîne peut exister comme deux taxonomies distinctes dans deux langues différentes
 * (ex. "élection" en français et "election" en anglais) — voir §3bis et §10.2 : chaque langue a
 * son propre pipeline de classification (stopwords, racinisation, entités nommées), donc son
 * propre espace de taxonomies.
 */
#[ORM\Entity(repositoryClass: TaxonomyRepository::class)]
#[UniqueEntity(fields: ['label', 'language'], message: 'Cette taxonomie existe déjà dans cette langue.')]
class Taxonomy
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(enumType: Language::class)]
    private Language $language;

    #[ORM\Column(enumType: TaxonomyStatus::class)]
    private TaxonomyStatus $status;

    /**
     * Score de pertinence calculé par `ClassificationService` (§10.1.7) : poids cumulé du terme
     * dans le dernier lot d'articles traité (présence en titre pondérée plus fort qu'en
     * description). Recalculé à chaque passage de classification, pas un champ éditorial figé.
     *
     * Contrairement à l'ancienne application, où ce champ existait (note attribuée manuellement
     * par un administrateur) mais n'était jamais lu par la logique de classement (dette listée en
     * §4.3, L11), il sert désormais de repère de tri sur l'écran de validation des suggestions
     * (§3.12) pour aider à prioriser les suggestions les plus significatives.
     */
    #[ORM\Column(nullable: true)]
    private ?int $mark = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $validatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    /** @var Collection<int, Article> Articles dont cette taxonomie fait partie du "sac de mots". */
    #[ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'taxonomies')]
    private Collection $articles;

    /** @var Collection<int, Article> Articles pour lesquels cette taxonomie est un mot-clé retenu. */
    #[ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'keywords')]
    private Collection $keywordArticles;

    public function __construct(string $label, Language $language)
    {
        $this->label = $label;
        $this->language = $language;
        $this->status = TaxonomyStatus::SUGGESTED;
        $this->articles = new ArrayCollection();
        $this->keywordArticles = new ArrayCollection();
        $this->initializeTimestamps();
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

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function getStatus(): TaxonomyStatus
    {
        return $this->status;
    }

    public function isValidated(): bool
    {
        return TaxonomyStatus::VALIDATED === $this->status;
    }

    public function validate(User $admin): static
    {
        $this->status = TaxonomyStatus::VALIDATED;
        $this->validatedBy = $admin;
        $this->validatedAt = new \DateTimeImmutable();
        $this->touchUpdatedAt();

        return $this;
    }

    /**
     * Validation « système » sans administrateur : les taxonomies fraîchement détectées par le
     * pipeline de classification sont validées par défaut (décision produit). `validatedBy` reste
     * nul — c'est justement ce qui distingue une validation automatique d'une validation manuelle
     * dans l'écran de modération, où un administrateur peut toujours rejeter le terme a posteriori.
     */
    public function validateAutomatically(): static
    {
        $this->status = TaxonomyStatus::VALIDATED;
        $this->validatedAt = new \DateTimeImmutable();
        $this->touchUpdatedAt();

        return $this;
    }

    public function reject(User $admin): static
    {
        $this->status = TaxonomyStatus::REJECTED;
        $this->validatedBy = $admin;
        $this->validatedAt = new \DateTimeImmutable();
        $this->touchUpdatedAt();

        return $this;
    }

    public function archive(): static
    {
        $this->status = TaxonomyStatus::ARCHIVED;
        $this->touchUpdatedAt();

        return $this;
    }

    public function getMark(): ?int
    {
        return $this->mark;
    }

    public function setMark(?int $mark): static
    {
        $this->mark = $mark;

        return $this;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    /** @return Collection<int, Article> */
    public function getKeywordArticles(): Collection
    {
        return $this->keywordArticles;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
