<?php

namespace App\Article\Entity;

use App\Article\Repository\ArticleRepository;
use App\Feed\Entity\Feed;
use App\Geography\Entity\Country;
use App\Shared\Utils\TimestampableTrait;
use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Article agrégé depuis un flux (ou soumis manuellement par URL).
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'sf_article')]
#[UniqueEntity('urlHash', message: 'Cet article est déjà enregistré.')]
class Article
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $image = null;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(length: 32, unique: true)]
    private string $urlHash;

    #[ORM\Column(enumType: Language::class)]
    private Language $language;

    #[ORM\ManyToOne(targetEntity: Feed::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Feed $feed = null;

    #[ORM\Column]
    private \DateTimeImmutable $publicationDate;

    #[ORM\Column]
    private bool $publish = false;

    #[ORM\Column]
    private bool $shared = false;

    /**
     * Toutes les taxonomies extraites du contenu, y compris celles qui ne sont encore que des
     * suggestions en attente de validation (§4.4). Ne pas utiliser pour l'affichage public.
     *
     * @var Collection<int, Taxonomy>
     */
    #[ORM\ManyToMany(targetEntity: Taxonomy::class, inversedBy: 'articles')]
    #[ORM\JoinTable(name: 'sf_article_taxonomy')]
    private Collection $taxonomies;

    /**
     * Sous-ensemble des taxonomies retenu comme mots-clés officiels : uniquement des taxonomies
     * au statut VALIDATED (§10.4). C'est cette collection qui alimente les pages publiques, la
     * recherche et les exports.
     *
     * @var Collection<int, Taxonomy>
     */
    #[ORM\ManyToMany(targetEntity: Taxonomy::class, inversedBy: 'keywordArticles')]
    #[ORM\JoinTable(name: 'sf_article_keyword')]
    private Collection $keywords;

    /**
     * Rattachement natif aux pays concernés (§3.13) — remplace le détour par UserNews de
     * l'ancienne application. Alimenté par la reconnaissance d'entités nommées du pipeline de
     * classification (§10.1.6) ou manuellement depuis le back-office.
     *
     * @var Collection<int, Country>
     */
    #[ORM\ManyToMany(targetEntity: Country::class, inversedBy: 'articles')]
    #[ORM\JoinTable(name: 'sf_article_country')]
    private Collection $countries;

    public function __construct(string $title, string $url, Feed $feed, \DateTimeImmutable $publicationDate)
    {
        $this->title = $title;
        $this->setUrl($url);
        $this->feed = $feed;
        $this->language = $feed->getLanguage();
        $this->publicationDate = $publicationDate;
        $this->taxonomies = new ArrayCollection();
        $this->keywords = new ArrayCollection();
        $this->countries = new ArrayCollection();
        $this->initializeTimestamps();
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
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

    public function getFeed(): ?Feed
    {
        return $this->feed;
    }

    public function setFeed(?Feed $feed): static
    {
        $this->feed = $feed;

        return $this;
    }

    public function getPublicationDate(): \DateTimeImmutable
    {
        return $this->publicationDate;
    }

    public function setPublicationDate(\DateTimeImmutable $publicationDate): static
    {
        $this->publicationDate = $publicationDate;

        return $this;
    }

    /**
     * Un article est « complet » lorsqu'il porte les trois métadonnées d'affichage : titre,
     * description et image. C'est le critère de publication automatique par défaut (décision
     * produit) appliqué à l'ingestion et après le crawl de repli (§9.4).
     */
    public function isComplete(): bool
    {
        return '' !== trim($this->title)
            && '' !== trim($this->description)
            && null !== $this->image;
    }

    public function isPublish(): bool
    {
        return $this->publish;
    }

    public function setPublish(bool $publish): static
    {
        $this->publish = $publish;

        return $this;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): static
    {
        $this->shared = $shared;

        return $this;
    }

    /** @return Collection<int, Taxonomy> */
    public function getTaxonomies(): Collection
    {
        return $this->taxonomies;
    }

    public function addTaxonomy(Taxonomy $taxonomy): static
    {
        if (!$this->taxonomies->contains($taxonomy)) {
            $this->taxonomies->add($taxonomy);
        }

        return $this;
    }

    /** @return Collection<int, Taxonomy> */
    public function getKeywords(): Collection
    {
        return $this->keywords;
    }

    public function getKeywordsCount(): int
    {
        return $this->keywords->count();
    }

    /**
     * Rattache un mot-clé validé à l'article. N'accepte que des taxonomies VALIDATED : le jalon
     * critique du plan de refonte (§11.3) veut qu'un mot-clé suggéré ne fuite jamais côté public,
     * donc pas même jusqu'à cette collection.
     */
    public function addKeyword(Taxonomy $keyword): static
    {
        if (!$keyword->isValidated()) {
            throw new \LogicException(sprintf('La taxonomie "%s" doit être validée avant de devenir un mot-clé (statut actuel : %s).', $keyword->getLabel(), $keyword->getStatus()->name));
        }

        if (!$this->keywords->contains($keyword)) {
            $this->keywords->add($keyword);
        }

        return $this;
    }

    /** @return Collection<int, Country> */
    public function getCountries(): Collection
    {
        return $this->countries;
    }

    public function addCountry(Country $country): static
    {
        if (!$this->countries->contains($country)) {
            $this->countries->add($country);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
