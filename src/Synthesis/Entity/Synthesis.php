<?php

namespace App\Synthesis\Entity;

use App\Article\Entity\Article;
use App\Geography\Entity\Country;
use App\Geography\Enum\Region;
use App\Shared\ValueObject\Language;
use App\Synthesis\Enum\SynthesisStatus;
use App\Synthesis\Repository\SynthesisRepository;
use App\User\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Synthèse hebdomadaire générée automatiquement à partir des articles déjà en base pour un pays
 * (ou, à défaut, pour la région du pays s'il n'a pas assez d'articles sur la semaine — voir
 * `WeeklySelector`), sous forme de contenu éditorial original produit par le pipeline
 * sélection → clustering par sous-thème → résumé par cluster (map) → assemblage (reduce),
 * orchestré par `SynthesisGenerator`.
 *
 * Rattachement exclusif à un pays OU une région : jamais les deux, jamais aucun des deux — imposé
 * par les constructeurs nommés `forCountry()`/`forRegion()`, le constructeur par défaut n'étant
 * volontairement pas exposé. `sourceArticles` trace les articles effectivement utilisés par le
 * pipeline (traçabilité et attribution, § "Nouvelle table syntheses") : ce n'est pas une relation
 * éditoriale comme `WeeklyNewsletter::$articles`, on n'y ajoute jamais d'article a posteriori.
 *
 * Toujours créée au statut DRAFT par `SynthesisGenerator`, sauf si le flag `synthesis.auto_publish`
 * est activé (§ "Scheduling" / "Workflow de validation") : le circuit de validation humaine
 * (`publish()`/`reject()`) reste la voie par défaut tant que le pipeline n'a pas fait ses preuves
 * sur plusieurs semaines.
 */
#[ORM\Entity(repositoryClass: SynthesisRepository::class)]
#[ORM\Table(name: 'sf_synthesis')]
class Synthesis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    private ?Country $country = null;

    #[ORM\Column(enumType: Region::class, nullable: true)]
    private ?Region $region = null;

    #[ORM\Column(enumType: Language::class)]
    private Language $language;

    #[ORM\Column]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column]
    private \DateTimeImmutable $weekEnd;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $lead;

    /** Corps structuré en sections par sous-thème, assemblé en HTML par `SynthesisAssembler` à partir de texte déjà échappé — jamais de HTML brut renvoyé par le LLM (§ "Assemblage final"). */
    #[ORM\Column(type: 'text')]
    private string $body;

    /**
     * Articles effectivement utilisés par le pipeline pour cette synthèse (traçabilité et
     * attribution). Simple lien de lecture, jamais retouché après génération.
     *
     * @var Collection<int, Article>
     */
    #[ORM\ManyToMany(targetEntity: Article::class)]
    #[ORM\JoinTable(name: 'sf_synthesis_article')]
    private Collection $sourceArticles;

    #[ORM\Column(enumType: SynthesisStatus::class)]
    private SynthesisStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** Administrateur ayant publié/rejeté la synthèse. Reste nul si publiée automatiquement (`synthesis.auto_publish`). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $reviewedBy = null;

    private function __construct(Language $language, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd)
    {
        $this->language = $language;
        $this->weekStart = $weekStart;
        $this->weekEnd = $weekEnd;
        $this->title = '';
        $this->lead = '';
        $this->body = '';
        $this->sourceArticles = new ArrayCollection();
        $this->status = SynthesisStatus::DRAFT;
        $this->generatedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->generatedAt;
    }

    public static function forCountry(Country $country, Language $language, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): self
    {
        $synthesis = new self($language, $weekStart, $weekEnd);
        $synthesis->country = $country;

        return $synthesis;
    }

    public static function forRegion(Region $region, Language $language, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): self
    {
        $synthesis = new self($language, $weekStart, $weekEnd);
        $synthesis->region = $region;

        return $synthesis;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function isRegional(): bool
    {
        return null !== $this->region;
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getWeekEnd(): \DateTimeImmutable
    {
        return $this->weekEnd;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLead(): string
    {
        return $this->lead;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Renseigne le contenu produit par le pipeline (ou une correction manuelle depuis le
     * back-office) : `$body` est du HTML déjà assemblé par `SynthesisAssembler` à partir de texte
     * échappé, jamais du HTML brut renvoyé tel quel par le LLM.
     */
    public function setContent(string $title, string $lead, string $body): static
    {
        $this->title = $title;
        $this->lead = $lead;
        $this->body = $body;
        $this->touchUpdatedAt();

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getSourceArticles(): Collection
    {
        return $this->sourceArticles;
    }

    public function addSourceArticle(Article $article): static
    {
        if (!$this->sourceArticles->contains($article)) {
            $this->sourceArticles->add($article);
        }

        return $this;
    }

    public function getStatus(): SynthesisStatus
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return SynthesisStatus::DRAFT === $this->status;
    }

    public function publish(User $admin): static
    {
        $this->status = SynthesisStatus::PUBLISHED;
        $this->reviewedBy = $admin;
        $this->publishedAt = new \DateTimeImmutable();
        $this->touchUpdatedAt();

        return $this;
    }

    /**
     * Publication « système » sans administrateur, utilisée uniquement quand
     * `synthesis.auto_publish` (env `AUTO_PUBLISH`) est activé — voir le docblock de classe.
     * `reviewedBy` reste nul, ce qui distingue une publication automatique d'une publication
     * manuelle dans le back-office.
     */
    public function publishAutomatically(): static
    {
        $this->status = SynthesisStatus::PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable();
        $this->touchUpdatedAt();

        return $this;
    }

    public function reject(User $admin): static
    {
        $this->status = SynthesisStatus::REJECTED;
        $this->reviewedBy = $admin;
        $this->touchUpdatedAt();

        return $this;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    private function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Pays ou région affiché en back-office (§ écran d'administration) — jamais de synthèse sans l'un des deux, voir les constructeurs nommés. */
    public function getScopeLabel(): string
    {
        if (null !== $this->country) {
            return $this->country->getNameFr();
        }

        return $this->region?->labelFr() ?? '?';
    }

    /** Segment `{scope}` de la route publique `app_synthesis_show` (code pays ISO en minuscules, ou slug de région). */
    public function getUrlScope(): string
    {
        if (null !== $this->country) {
            return strtolower($this->country->getCode());
        }

        return $this->region?->slug() ?? '';
    }

    public function __toString(): string
    {
        return sprintf('#%d — %s', $this->id ?? 0, $this->title);
    }
}
