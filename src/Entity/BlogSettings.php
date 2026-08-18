<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Entity;

use BackedEnum;
use Doctrine\ORM\Mapping as ORM;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;

#[ORM\Entity(repositoryClass: BlogSettingsRepository::class)]
#[ORM\Table(name: 'content_blog_settings')]
/**
 * Singleton professional blog configuration (listing, asides, detail).
 */
class BlogSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, options: ['default' => 'inherit'])]
    private string $listingMode = 'inherit';

    #[ORM\Column(options: ['default' => 6])]
    private int $perPage = 6;

    #[ORM\Column(length: 16, options: ['default' => 'inherit'])]
    private string $masonryStrategy = 'inherit';

    #[ORM\Column(options: ['default' => 0])]
    private int $masonryColumnsMobile = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $masonryColumnsTablet = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $masonryColumnsDesktop = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $showCardImage = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showCardExcerpt = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showCardTags = true;

    #[ORM\Column(options: ['default' => 20])]
    private int $indexTagsLimit = 20;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $indexAsideSearch = BlogAsidePlacement::Right->value;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $indexAsideLatest = BlogAsidePlacement::Right->value;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $indexAsideTags = BlogAsidePlacement::Right->value;

    #[ORM\Column(options: ['default' => 5])]
    private int $indexLatestLimit = 5;

    #[ORM\Column(options: ['default' => 20])]
    private int $indexAsideTagsLimit = 20;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $showAsideSearch = BlogAsidePlacement::Right->value;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $showAsideRelated = BlogAsidePlacement::Right->value;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $showAsideArticleTags = BlogAsidePlacement::Right->value;

    #[ORM\Column(length: 16, options: ['default' => 'right'])]
    private string $showAsideResources = BlogAsidePlacement::Right->value;

    #[ORM\Column(options: ['default' => 5])]
    private int $relatedLimit = 5;

    #[ORM\Column(options: ['default' => true])]
    private bool $resourcesIncludeLinkedin = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showShare = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showComments = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showSourceLink = true;

    #[ORM\Column(length: 16, options: ['default' => 'contain'])]
    private string $heroImageMode = BlogHeroImageMode::Contain->value;

    #[ORM\Column(length: 32, options: ['default' => 'inherit'])]
    private string $commentRateLimitStrategy = 'inherit';

    #[ORM\Column(options: ['default' => 0])]
    private int $commentRateLimitLimit = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $commentRateLimitIntervalSeconds = 0;

    #[ORM\Column(length: 32, options: ['default' => 'inherit'])]
    private string $commentCaptchaStrategy = 'inherit';

    #[ORM\Column(length: 32, options: ['default' => 'inherit'])]
    private string $htmlSanitizeStrategy = 'inherit';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getListingMode(): string
    {
        return $this->listingMode;
    }

    public function setListingMode(string $listingMode): self
    {
        $this->listingMode = $this->normalizeOverrideStrategy(
            $listingMode,
            BlogListingMode::class,
        );

        return $this;
    }

    public function listingMode(): BlogListingMode
    {
        return BlogListingMode::tryFrom($this->listingMode) ?? BlogListingMode::Paginated;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function setPerPage(int $perPage): self
    {
        $this->perPage = max(1, min(24, $perPage));

        return $this;
    }

    public function getMasonryStrategy(): string
    {
        return $this->masonryStrategy;
    }

    public function setMasonryStrategy(string $masonryStrategy): self
    {
        $this->masonryStrategy = $this->normalizeOverrideStrategy(
            $masonryStrategy,
            BlogMasonryStrategy::class,
        );

        return $this;
    }

    public function masonryStrategy(): BlogMasonryStrategy
    {
        return BlogMasonryStrategy::tryFrom($this->masonryStrategy) ?? BlogMasonryStrategy::Masonry;
    }

    public function getMasonryColumnsMobile(): int
    {
        return $this->masonryColumnsMobile;
    }

    public function setMasonryColumnsMobile(int $masonryColumnsMobile): self
    {
        $this->masonryColumnsMobile = max(0, min(2, $masonryColumnsMobile));

        return $this;
    }

    public function getMasonryColumnsTablet(): int
    {
        return $this->masonryColumnsTablet;
    }

    public function setMasonryColumnsTablet(int $masonryColumnsTablet): self
    {
        $this->masonryColumnsTablet = max(0, min(2, $masonryColumnsTablet));

        return $this;
    }

    public function getMasonryColumnsDesktop(): int
    {
        return $this->masonryColumnsDesktop;
    }

    public function setMasonryColumnsDesktop(int $masonryColumnsDesktop): self
    {
        $this->masonryColumnsDesktop = max(0, min(3, $masonryColumnsDesktop));

        return $this;
    }

    public function isShowCardImage(): bool
    {
        return $this->showCardImage;
    }

    public function setShowCardImage(bool $showCardImage): self
    {
        $this->showCardImage = $showCardImage;

        return $this;
    }

    public function isShowCardExcerpt(): bool
    {
        return $this->showCardExcerpt;
    }

    public function setShowCardExcerpt(bool $showCardExcerpt): self
    {
        $this->showCardExcerpt = $showCardExcerpt;

        return $this;
    }

    public function isShowCardTags(): bool
    {
        return $this->showCardTags;
    }

    public function setShowCardTags(bool $showCardTags): self
    {
        $this->showCardTags = $showCardTags;

        return $this;
    }

    public function getIndexTagsLimit(): int
    {
        return $this->indexTagsLimit;
    }

    public function setIndexTagsLimit(int $indexTagsLimit): self
    {
        $this->indexTagsLimit = max(0, min(100, $indexTagsLimit));

        return $this;
    }

    public function getIndexAsideSearch(): string
    {
        return $this->indexAsideSearch;
    }

    public function setIndexAsideSearch(string $indexAsideSearch): self
    {
        $this->indexAsideSearch = $this->normalizePlacement($indexAsideSearch);

        return $this;
    }

    public function indexAsideSearch(): BlogAsidePlacement
    {
        return $this->placement($this->indexAsideSearch);
    }

    public function getIndexAsideLatest(): string
    {
        return $this->indexAsideLatest;
    }

    public function setIndexAsideLatest(string $indexAsideLatest): self
    {
        $this->indexAsideLatest = $this->normalizePlacement($indexAsideLatest);

        return $this;
    }

    public function indexAsideLatest(): BlogAsidePlacement
    {
        return $this->placement($this->indexAsideLatest);
    }

    public function getIndexAsideTags(): string
    {
        return $this->indexAsideTags;
    }

    public function setIndexAsideTags(string $indexAsideTags): self
    {
        $this->indexAsideTags = $this->normalizePlacement($indexAsideTags);

        return $this;
    }

    public function indexAsideTags(): BlogAsidePlacement
    {
        return $this->placement($this->indexAsideTags);
    }

    public function getIndexLatestLimit(): int
    {
        return $this->indexLatestLimit;
    }

    public function setIndexLatestLimit(int $indexLatestLimit): self
    {
        $this->indexLatestLimit = max(1, min(24, $indexLatestLimit));

        return $this;
    }

    public function getIndexAsideTagsLimit(): int
    {
        return $this->indexAsideTagsLimit;
    }

    public function setIndexAsideTagsLimit(int $indexAsideTagsLimit): self
    {
        $this->indexAsideTagsLimit = max(0, min(100, $indexAsideTagsLimit));

        return $this;
    }

    public function getShowAsideSearch(): string
    {
        return $this->showAsideSearch;
    }

    public function setShowAsideSearch(string $showAsideSearch): self
    {
        $this->showAsideSearch = $this->normalizePlacement($showAsideSearch);

        return $this;
    }

    public function showAsideSearch(): BlogAsidePlacement
    {
        return $this->placement($this->showAsideSearch);
    }

    public function getShowAsideRelated(): string
    {
        return $this->showAsideRelated;
    }

    public function setShowAsideRelated(string $showAsideRelated): self
    {
        $this->showAsideRelated = $this->normalizePlacement($showAsideRelated);

        return $this;
    }

    public function showAsideRelated(): BlogAsidePlacement
    {
        return $this->placement($this->showAsideRelated);
    }

    public function getShowAsideArticleTags(): string
    {
        return $this->showAsideArticleTags;
    }

    public function setShowAsideArticleTags(string $showAsideArticleTags): self
    {
        $this->showAsideArticleTags = $this->normalizePlacement($showAsideArticleTags);

        return $this;
    }

    public function showAsideArticleTags(): BlogAsidePlacement
    {
        return $this->placement($this->showAsideArticleTags);
    }

    public function getShowAsideResources(): string
    {
        return $this->showAsideResources;
    }

    public function setShowAsideResources(string $showAsideResources): self
    {
        $this->showAsideResources = $this->normalizePlacement($showAsideResources);

        return $this;
    }

    public function showAsideResources(): BlogAsidePlacement
    {
        return $this->placement($this->showAsideResources);
    }

    public function getRelatedLimit(): int
    {
        return $this->relatedLimit;
    }

    public function setRelatedLimit(int $relatedLimit): self
    {
        $this->relatedLimit = max(1, min(24, $relatedLimit));

        return $this;
    }

    public function isResourcesIncludeLinkedin(): bool
    {
        return $this->resourcesIncludeLinkedin;
    }

    public function setResourcesIncludeLinkedin(bool $resourcesIncludeLinkedin): self
    {
        $this->resourcesIncludeLinkedin = $resourcesIncludeLinkedin;

        return $this;
    }

    public function isShowShare(): bool
    {
        return $this->showShare;
    }

    public function setShowShare(bool $showShare): self
    {
        $this->showShare = $showShare;

        return $this;
    }

    public function isShowComments(): bool
    {
        return $this->showComments;
    }

    public function setShowComments(bool $showComments): self
    {
        $this->showComments = $showComments;

        return $this;
    }

    public function isShowSourceLink(): bool
    {
        return $this->showSourceLink;
    }

    public function setShowSourceLink(bool $showSourceLink): self
    {
        $this->showSourceLink = $showSourceLink;

        return $this;
    }

    public function getHeroImageMode(): string
    {
        return $this->heroImageMode;
    }

    public function setHeroImageMode(string $heroImageMode): self
    {
        $this->heroImageMode = (BlogHeroImageMode::tryFrom($heroImageMode) ?? BlogHeroImageMode::Contain)->value;

        return $this;
    }

    public function heroImageMode(): BlogHeroImageMode
    {
        return BlogHeroImageMode::tryFrom($this->heroImageMode) ?? BlogHeroImageMode::Contain;
    }

    public function getCommentRateLimitStrategy(): string
    {
        return $this->commentRateLimitStrategy;
    }

    public function setCommentRateLimitStrategy(string $commentRateLimitStrategy): self
    {
        $this->commentRateLimitStrategy = $this->normalizeOverrideStrategy(
            $commentRateLimitStrategy,
            CommentRateLimitStrategy::class,
        );

        return $this;
    }

    public function getCommentRateLimitLimit(): int
    {
        return $this->commentRateLimitLimit;
    }

    public function setCommentRateLimitLimit(int $commentRateLimitLimit): self
    {
        $this->commentRateLimitLimit = max(0, min(1000, $commentRateLimitLimit));

        return $this;
    }

    public function getCommentRateLimitIntervalSeconds(): int
    {
        return $this->commentRateLimitIntervalSeconds;
    }

    public function setCommentRateLimitIntervalSeconds(int $commentRateLimitIntervalSeconds): self
    {
        $this->commentRateLimitIntervalSeconds = max(0, min(86400, $commentRateLimitIntervalSeconds));

        return $this;
    }

    public function getCommentCaptchaStrategy(): string
    {
        return $this->commentCaptchaStrategy;
    }

    public function setCommentCaptchaStrategy(string $commentCaptchaStrategy): self
    {
        $this->commentCaptchaStrategy = $this->normalizeOverrideStrategy(
            $commentCaptchaStrategy,
            CommentCaptchaStrategy::class,
        );

        return $this;
    }

    public function getHtmlSanitizeStrategy(): string
    {
        return $this->htmlSanitizeStrategy;
    }

    public function setHtmlSanitizeStrategy(string $htmlSanitizeStrategy): self
    {
        $this->htmlSanitizeStrategy = $this->normalizeOverrideStrategy(
            $htmlSanitizeStrategy,
            HtmlSanitizeStrategy::class,
        );

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'listing_mode'                        => $this->listingMode,
            'per_page'                            => $this->perPage,
            'masonry_strategy'                    => $this->masonryStrategy,
            'masonry_columns_mobile'              => $this->masonryColumnsMobile,
            'masonry_columns_tablet'              => $this->masonryColumnsTablet,
            'masonry_columns_desktop'             => $this->masonryColumnsDesktop,
            'show_card_image'                     => $this->showCardImage,
            'show_card_excerpt'                   => $this->showCardExcerpt,
            'show_card_tags'                      => $this->showCardTags,
            'index_tags_limit'                    => $this->indexTagsLimit,
            'index_aside_search'                  => $this->indexAsideSearch,
            'index_aside_latest'                  => $this->indexAsideLatest,
            'index_aside_tags'                    => $this->indexAsideTags,
            'index_latest_limit'                  => $this->indexLatestLimit,
            'index_aside_tags_limit'              => $this->indexAsideTagsLimit,
            'show_aside_search'                   => $this->showAsideSearch,
            'show_aside_related'                  => $this->showAsideRelated,
            'show_aside_article_tags'             => $this->showAsideArticleTags,
            'show_aside_resources'                => $this->showAsideResources,
            'related_limit'                       => $this->relatedLimit,
            'resources_include_linkedin'          => $this->resourcesIncludeLinkedin,
            'show_share'                          => $this->showShare,
            'show_comments'                       => $this->showComments,
            'show_source_link'                    => $this->showSourceLink,
            'hero_image_mode'                     => $this->heroImageMode,
            'comment_rate_limit_strategy'         => $this->commentRateLimitStrategy,
            'comment_rate_limit_limit'            => $this->commentRateLimitLimit,
            'comment_rate_limit_interval_seconds' => $this->commentRateLimitIntervalSeconds,
            'comment_captcha_strategy'            => $this->commentCaptchaStrategy,
            'html_sanitize_strategy'              => $this->htmlSanitizeStrategy,
        ];
    }

    private function normalizePlacement(string $value): string
    {
        return (BlogAsidePlacement::tryFrom($value) ?? BlogAsidePlacement::Right)->value;
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    private function normalizeOverrideStrategy(string $value, string $enumClass): string
    {
        if ($value === 'inherit' || $value === '') {
            return 'inherit';
        }

        $resolved = $enumClass::tryFrom($value);
        if (!$resolved instanceof BackedEnum || $resolved->value === 'service') {
            return 'inherit';
        }

        return (string) $resolved->value;
    }

    private function placement(string $value): BlogAsidePlacement
    {
        return BlogAsidePlacement::tryFrom($value) ?? BlogAsidePlacement::Right;
    }
}
