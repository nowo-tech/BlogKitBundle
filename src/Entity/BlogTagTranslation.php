<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Repository\BlogTagTranslationRepository;

#[ORM\Entity(repositoryClass: BlogTagTranslationRepository::class)]
#[ORM\Table(name: 'content_blog_tag_translation')]
#[ORM\UniqueConstraint(name: 'uniq_blog_tag_locale', columns: ['translatable_id', 'locale'])]
/**
 * Locale-specific display name for a {@see BlogTag}.
 */
class BlogTagTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BlogTag::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'translatable_id', nullable: false, onDelete: 'CASCADE')]
    private BlogTag $translatable;

    #[ORM\Column(length: 5)]
    private string $locale = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getTranslatable(): BlogTag
    {
        return $this->translatable;
    }

    public function setTranslatable(BlogTag $translatable): self
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
