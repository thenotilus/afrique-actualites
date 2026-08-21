<?php

namespace App\Newsletter\Entity;

use App\Article\Entity\Article;
use App\Newsletter\Repository\WeeklyNewsletterRepository;
use App\Taxonomy\Entity\Taxonomy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Newsletter hebdomadaire. La newsletter quotidienne de l'ancienne application n'est pas reprise
 * (abandonnée, §3.7).
 *
 * Contrairement à l'ancienne application (contenu sérialisé dans une unique colonne JSON,
 * `json_array` — type Doctrine déprécié, cf. dette listée en §5), le contenu est modélisé avec de
 * vraies colonnes et de vraies relations, plus faciles à faire évoluer et à interroger.
 */
#[ORM\Entity(repositoryClass: WeeklyNewsletterRepository::class)]
class WeeklyNewsletter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $text = '';

    #[ORM\Column(type: 'text')]
    private string $additionalText = '';

    /** @var Collection<int, Taxonomy> */
    #[ORM\ManyToMany(targetEntity: Taxonomy::class)]
    #[ORM\JoinTable(name: 'weekly_newsletter_taxonomy')]
    private Collection $keywords;

    /** @var Collection<int, Article> */
    #[ORM\ManyToMany(targetEntity: Article::class)]
    #[ORM\JoinTable(name: 'weekly_newsletter_article')]
    private Collection $articles;

    #[ORM\Column]
    private bool $allSubscribers = true;

    /** @var Collection<int, NewsletterSubscriber> Pertinent seulement si allSubscribers = false. */
    #[ORM\ManyToMany(targetEntity: NewsletterSubscriber::class)]
    #[ORM\JoinTable(name: 'weekly_newsletter_subscriber')]
    private Collection $targetedSubscribers;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(string $subject)
    {
        $this->subject = $subject;
        $this->keywords = new ArrayCollection();
        $this->articles = new ArrayCollection();
        $this->targetedSubscribers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getAdditionalText(): string
    {
        return $this->additionalText;
    }

    public function setAdditionalText(string $additionalText): static
    {
        $this->additionalText = $additionalText;

        return $this;
    }

    /** @return Collection<int, Taxonomy> */
    public function getKeywords(): Collection
    {
        return $this->keywords;
    }

    public function addKeyword(Taxonomy $keyword): static
    {
        if (!$keyword->isValidated()) {
            throw new \LogicException('Seul un mot-clé validé peut être ajouté à une newsletter.');
        }

        if (!$this->keywords->contains($keyword)) {
            $this->keywords->add($keyword);
        }

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
        }

        return $this;
    }

    public function isAllSubscribers(): bool
    {
        return $this->allSubscribers;
    }

    public function setAllSubscribers(bool $allSubscribers): static
    {
        $this->allSubscribers = $allSubscribers;

        return $this;
    }

    /** @return Collection<int, NewsletterSubscriber> */
    public function getTargetedSubscribers(): Collection
    {
        return $this->targetedSubscribers;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markAsSent(): static
    {
        $this->sentAt = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('#%d — %s', $this->id ?? 0, $this->subject);
    }
}
