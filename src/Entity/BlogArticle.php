<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\BlogKitBundle\Model\BlameableUserTrait;
use Nowo\BlogKitBundle\Repository\BlogArticleRepository;

#[ORM\Entity(repositoryClass: BlogArticleRepository::class)]
#[ORM\Table(name: 'content_blog_article')]
/**
 * Published blog article with slug, metadata, tags, and translations.
 */
class BlogArticle implements AuditableInterface
{
    use BlameableUserTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkedinUrl = null;

    /** @var Collection<int, BlogArticleTranslation> */
    #[ORM\OneToMany(targetEntity: BlogArticleTranslation::class, mappedBy: 'translatable', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    /** @var Collection<int, BlogTag> */
    #[ORM\ManyToMany(targetEntity: BlogTag::class, inversedBy: 'articles')]
    #[ORM\JoinTable(name: 'content_blog_article_tag')]
    private Collection $tags;

    /** @var Collection<int, BlogArticleResource> */
    #[ORM\OneToMany(targetEntity: BlogArticleResource::class, mappedBy: 'blogArticle', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $resources;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->tags         = new ArrayCollection();
        $this->resources    = new ArrayCollection();
    }

    public function getTranslation(string $locale): ?BlogArticleTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getTranslationOrFallback(string $locale, ?string $fallbackLocale = null): BlogArticleTranslation
    {
        $translation = $this->getTranslation($locale);

        if ($translation instanceof BlogArticleTranslation) {
            return $translation;
        }

        if ($fallbackLocale !== null) {
            $fallback = $this->getTranslation($fallbackLocale);
            if ($fallback instanceof BlogArticleTranslation) {
                return $fallback;
            }
        }

        $first = $this->translations->first();

        return ($first instanceof BlogArticleTranslation ? $first : null)
            ?? new BlogArticleTranslation();
    }

    /** @return array<string, mixed> */
    public function toArray(string $locale): array
    {
        $blogArticleTranslation = $this->getTranslationOrFallback($locale);

        return [
            'slug'         => $this->slug,
            'title'        => $blogArticleTranslation->getTitle(),
            'excerpt'      => $blogArticleTranslation->getExcerpt() ?? '',
            'body'         => $blogArticleTranslation->getBody(),
            'image'        => $this->image,
            'published_at' => $this->publishedAt?->format('Y-m-d'),
            'linkedin_url' => $this->linkedinUrl,
            'tags'         => array_map(
                static fn (BlogTag $blogTag): array => $blogTag->toArray($locale),
                $this->getTagsSorted(),
            ),
        ];
    }

    /** @return list<BlogTag> */
    public function getTagsSorted(): array
    {
        $tags = $this->tags->toArray();
        usort(
            $tags,
            static fn (BlogTag $a, BlogTag $b): int => strcmp($a->getSlug(), $b->getSlug()),
        );

        return $tags;
    }

    public function addTag(BlogTag $blogTag): self
    {
        if (!$this->tags->contains($blogTag)) {
            $this->tags->add($blogTag);
        }

        return $this;
    }

    public function removeTag(BlogTag $blogTag): self
    {
        $this->tags->removeElement($blogTag);

        return $this;
    }

    /** @return Collection<int, BlogTag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /** @param iterable<BlogTag> $tags */
    public function setTags(iterable $tags): self
    {
        $this->tags = new ArrayCollection();

        foreach ($tags as $tag) {
            $this->addTag($tag);
        }

        return $this;
    }

    public function addTranslation(BlogArticleTranslation $blogArticleTranslation): self
    {
        if (!$this->translations->contains($blogArticleTranslation)) {
            $this->translations->add($blogArticleTranslation);
            $blogArticleTranslation->setTranslatable($this);
        }

        return $this;
    }

    /** @return Collection<int, BlogArticleTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    /** @param list<string> $locales */
    public function ensureTranslations(array $locales = ['es', 'en']): self
    {
        foreach ($locales as $locale) {
            if (!$this->getTranslation($locale) instanceof BlogArticleTranslation) {
                $this->addTranslation(new BlogArticleTranslation()->setLocale($locale));
            }
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getTitle(?string $locale = null): string
    {
        if ($locale === null || $locale === '') {
            $first = $this->translations->first();

            return $first instanceof BlogArticleTranslation ? $first->getTitle() : '';
        }

        return $this->getTranslationOrFallback($locale)->getTitle();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getLinkedinUrl(): ?string
    {
        return $this->linkedinUrl;
    }

    public function setLinkedinUrl(?string $linkedinUrl): self
    {
        $this->linkedinUrl = $linkedinUrl;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): self
    {
        $this->published = $published;

        return $this;
    }

    public function addResource(BlogArticleResource $blogArticleResource): self
    {
        if (!$this->resources->contains($blogArticleResource)) {
            $this->resources->add($blogArticleResource);
            $blogArticleResource->setArticle($this);
        }

        return $this;
    }

    public function removeResource(BlogArticleResource $blogArticleResource): self
    {
        $this->resources->removeElement($blogArticleResource);

        return $this;
    }

    /** @return Collection<int, BlogArticleResource> */
    public function getResources(): Collection
    {
        return $this->resources;
    }
}
