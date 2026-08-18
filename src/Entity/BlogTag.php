<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Stringable;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: BlogTagRepository::class)]
#[ORM\Table(name: 'content_blog_tag')]
#[UniqueEntity(fields: ['slug'], message: 'Ya existe una etiqueta con este slug.')]
/**
 * Blog tag with slug, display name translations, and linked articles.
 */
class BlogTag implements Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    /** @var Collection<int, BlogTagTranslation> */
    #[ORM\OneToMany(targetEntity: BlogTagTranslation::class, mappedBy: 'translatable', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    /** @var Collection<int, BlogArticle> */
    #[ORM\ManyToMany(targetEntity: BlogArticle::class, mappedBy: 'tags')]
    private Collection $articles;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->articles     = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->slug;
    }

    public function getTranslation(string $locale): ?BlogTagTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getTranslationOrFallback(string $locale, ?string $fallbackLocale = null): BlogTagTranslation
    {
        $translation = $this->getTranslation($locale);

        if ($translation instanceof BlogTagTranslation) {
            return $translation;
        }

        if ($fallbackLocale !== null) {
            $fallback = $this->getTranslation($fallbackLocale);
            if ($fallback instanceof BlogTagTranslation) {
                return $fallback;
            }
        }

        $first = $this->translations->first();

        return ($first instanceof BlogTagTranslation ? $first : null)
            ?? new BlogTagTranslation();
    }

    public function getName(?string $locale = null): string
    {
        if ($locale === null || $locale === '') {
            $first = $this->translations->first();

            return $first instanceof BlogTagTranslation ? $first->getName() : '';
        }

        return $this->getTranslationOrFallback($locale)->getName();
    }

    /** @return array{slug: string, name: string} */
    public function toArray(string $locale): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->getName($locale),
        ];
    }

    public function addTranslation(BlogTagTranslation $blogTagTranslation): self
    {
        if (!$this->translations->contains($blogTagTranslation)) {
            $this->translations->add($blogTagTranslation);
            $blogTagTranslation->setTranslatable($this);
        }

        return $this;
    }

    /** @param list<string> $locales */
    public function ensureTranslations(array $locales = ['es', 'en']): self
    {
        foreach ($locales as $locale) {
            if (!$this->getTranslation($locale) instanceof BlogTagTranslation) {
                $this->addTranslation(new BlogTagTranslation()->setLocale($locale));
            }
        }

        return $this;
    }

    /** @return Collection<int, BlogTagTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = strtolower(trim($slug));

        return $this;
    }

    /** @return Collection<int, BlogArticle> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }
}
