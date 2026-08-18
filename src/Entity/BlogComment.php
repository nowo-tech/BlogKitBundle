<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\Repository\BlogCommentRepository;

#[ORM\Entity(repositoryClass: BlogCommentRepository::class)]
#[ORM\Table(name: 'content_blog_comment')]
#[ORM\Index(name: 'IDX_BLOG_COMMENT_ARTICLE_STATUS', columns: [
    'article_id',
    'status',
    'created_at'])]
#[ORM\Index(name: 'IDX_BLOG_COMMENT_PARENT', columns: ['parent_id'])]
/**
 * Visitor or staff comment on a blog article, with optional threaded replies.
 */
class BlogComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BlogArticle::class)]
    #[ORM\JoinColumn(name: 'article_id', nullable: false, onDelete: 'CASCADE')]
    private BlogArticle $blogArticle;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'parent_id', onDelete: 'CASCADE')]
    private ?BlogComment $blogComment = null;

    /** @var Collection<int, BlogComment> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'blogComment', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $replies;

    #[ORM\Column(length: 120)]
    private string $authorName = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $authorEmail = null;

    #[ORM\ManyToOne(targetEntity: BlogUserInterface::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?BlogUserInterface $staffAuthor = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(name: 'status', length: 16, enumType: BlogCommentStatus::class)]
    private BlogCommentStatus $blogCommentStatus = BlogCommentStatus::Pending;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $moderatedAt = null;

    #[ORM\ManyToOne(targetEntity: BlogUserInterface::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?BlogUserInterface $moderatedBy = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ipHash = null;

    public function __construct()
    {
        $this->replies   = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function isStaffReply(): bool
    {
        return $this->staffAuthor instanceof BlogUserInterface;
    }

    public function displayAuthorName(): string
    {
        if ($this->isStaffReply()) {
            return $this->authorName !== '' ? $this->authorName : 'Equipo';
        }

        return $this->authorName;
    }

    public function isVisibleOnSite(): bool
    {
        return $this->blogCommentStatus === BlogCommentStatus::Approved;
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

    public function getArticle(): BlogArticle
    {
        return $this->blogArticle;
    }

    public function setArticle(BlogArticle $blogArticle): self
    {
        $this->blogArticle = $blogArticle;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->blogComment;
    }

    public function setParent(?self $parent): self
    {
        $this->blogComment = $parent;

        return $this;
    }

    /** @return Collection<int, BlogComment> */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function addReply(self $reply): self
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setParent($this);
        }

        return $this;
    }

    /** @return list<BlogComment> */
    public function getApprovedReplies(): array
    {
        return array_values(array_filter(
            $this->replies->toArray(),
            static fn (BlogComment $blogComment): bool => $blogComment->isVisibleOnSite(),
        ));
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $authorName): self
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getAuthorEmail(): ?string
    {
        return $this->authorEmail;
    }

    public function setAuthorEmail(?string $authorEmail): self
    {
        $this->authorEmail = $authorEmail;

        return $this;
    }

    public function getStaffAuthor(): ?BlogUserInterface
    {
        return $this->staffAuthor;
    }

    public function setStaffAuthor(?BlogUserInterface $user): self
    {
        $this->staffAuthor = $user;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getStatus(): BlogCommentStatus
    {
        return $this->blogCommentStatus;
    }

    public function setStatus(BlogCommentStatus $blogCommentStatus): self
    {
        $this->blogCommentStatus = $blogCommentStatus;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getModeratedAt(): ?DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function setModeratedAt(?DateTimeImmutable $moderatedAt): self
    {
        $this->moderatedAt = $moderatedAt;

        return $this;
    }

    public function getModeratedBy(): ?BlogUserInterface
    {
        return $this->moderatedBy;
    }

    public function setModeratedBy(?BlogUserInterface $user): self
    {
        $this->moderatedBy = $user;

        return $this;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $ipHash): self
    {
        $this->ipHash = $ipHash;

        return $this;
    }
}
