<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Repository\BlogArticleTranslationRepository;

#[ORM\Entity(repositoryClass: BlogArticleTranslationRepository::class)]
#[ORM\Table(name: 'content_blog_article_translation')]
#[ORM\UniqueConstraint(name: 'uniq_blog_locale', columns: ['translatable_id', 'locale'])]
/**
 * Locale-specific title, excerpt, and body for a {@see BlogArticle}.
 */
class BlogArticleTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BlogArticle::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'translatable_id', nullable: false, onDelete: 'CASCADE')]
    private BlogArticle $translatable;

    #[ORM\Column(length: 2)]
    private string $locale = 'es';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 255)]
    private string $metaTitle = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getTranslatable(): BlogArticle
    {
        return $this->translatable;
    }

    public function setTranslatable(BlogArticle $translatable): self
    {
        $this->translatable = $translatable;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getMetaTitle(): string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(string $metaTitle): self
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): self
    {
        $this->excerpt = $excerpt;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = $body;

        return $this;
    }
}
