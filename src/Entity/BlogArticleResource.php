<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Repository\BlogArticleResourceRepository;

#[ORM\Entity(repositoryClass: BlogArticleResourceRepository::class)]
#[ORM\Table(name: 'content_blog_article_resource')]
/**
 * Sidebar resource for a blog article (e.g. LinkedIn conceptual board image).
 */
class BlogArticleResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BlogArticle::class, inversedBy: 'resources')]
    #[ORM\JoinColumn(name: 'article_id', nullable: false, onDelete: 'CASCADE')]
    private BlogArticle $blogArticle;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    private string $image = '';

    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getArticle(): BlogArticle
    {
        return $this->blogArticle;
    }

    public function setArticle(BlogArticle $blogArticle): self
    {
        $this->blogArticle = $blogArticle;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title !== null && trim($title) !== '' ? trim($title) : null;

        return $this;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function setImage(string $image): self
    {
        $this->image = trim($image);

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

    /** @return array{id: int|null, title: string|null, image: string, position: int} */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'title'    => $this->title,
            'image'    => $this->image,
            'position' => $this->position,
        ];
    }
}
