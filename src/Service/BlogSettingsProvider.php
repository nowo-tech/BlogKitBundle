<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use Nowo\BlogKitBundle\Repository\BlogSettingsRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped access to the blog settings singleton as a public array.
 */
final class BlogSettingsProvider implements ResetInterface
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly BlogSettingsRepository $blogSettingsRepository,
        #[Autowire('%nowo_blog_kit.listing.mode%')]
        private readonly string $listingModeDefault = 'paginated',
        #[Autowire('%nowo_blog_kit.listing.masonry.strategy%')]
        private readonly string $masonryStrategyDefault = 'masonry',
        #[Autowire('%nowo_blog_kit.listing.masonry.columns_mobile%')]
        private readonly int $masonryColumnsMobileDefault = 1,
        #[Autowire('%nowo_blog_kit.listing.masonry.columns_tablet%')]
        private readonly int $masonryColumnsTabletDefault = 2,
        #[Autowire('%nowo_blog_kit.listing.masonry.columns_desktop%')]
        private readonly int $masonryColumnsDesktopDefault = 2,
    ) {
    }

    public function reset(): void
    {
        $this->cached = null;
    }

    public function settings(): BlogSettings
    {
        return $this->blogSettingsRepository->getSingleton();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->cached ??= $this->settings()->toPublicArray();
    }

    public function listingMode(): BlogListingMode
    {
        $override = (string) ($this->all()['listing_mode'] ?? '');
        if ($override === BlogListingMode::Inherit || $override === '') {
            return $this->yamlListingMode();
        }

        return BlogListingMode::tryFrom($override) ?? $this->yamlListingMode();
    }

    public function masonryStrategy(): BlogMasonryStrategy
    {
        $override = (string) ($this->all()['masonry_strategy'] ?? '');
        if ($override === BlogMasonryStrategy::Inherit || $override === '') {
            return $this->yamlMasonryStrategy();
        }

        return BlogMasonryStrategy::tryFrom($override) ?? $this->yamlMasonryStrategy();
    }

    /**
     * @return array{mobile: int, tablet: int, desktop: int}
     */
    public function masonryColumns(): array
    {
        $settings = $this->all();

        return [
            'mobile' => $this->resolveMasonryColumn(
                (int) ($settings['masonry_columns_mobile'] ?? 0),
                $this->masonryColumnsMobileDefault,
                2,
            ),
            'tablet' => $this->resolveMasonryColumn(
                (int) ($settings['masonry_columns_tablet'] ?? 0),
                $this->masonryColumnsTabletDefault,
                2,
            ),
            'desktop' => $this->resolveMasonryColumn(
                (int) ($settings['masonry_columns_desktop'] ?? 0),
                $this->masonryColumnsDesktopDefault,
                3,
            ),
        ];
    }

    public function perPage(): int
    {
        return max(1, min(24, (int) $this->all()['per_page']));
    }

    public function indexLatestLimit(): int
    {
        return max(1, min(24, (int) $this->all()['index_latest_limit']));
    }

    public function relatedLimit(): int
    {
        return max(1, min(24, (int) $this->all()['related_limit']));
    }

    public function indexTagsLimit(): int
    {
        return max(0, min(100, (int) $this->all()['index_tags_limit']));
    }

    public function indexAsideTagsLimit(): int
    {
        return max(0, min(100, (int) $this->all()['index_aside_tags_limit']));
    }

    public function placement(string $key): BlogAsidePlacement
    {
        return BlogAsidePlacement::tryFrom((string) ($this->all()[$key] ?? 'right'))
            ?? BlogAsidePlacement::Right;
    }

    public function heroImageMode(): BlogHeroImageMode
    {
        return BlogHeroImageMode::tryFrom((string) $this->all()['hero_image_mode'])
            ?? BlogHeroImageMode::Contain;
    }

    public function bool(string $key, bool $default = true): bool
    {
        $value = $this->all()[$key] ?? $default;

        return (bool) $value;
    }

    /**
     * @param array<string, string> $widgets Map of widget id => settings key
     *
     * @return array{left: list<string>, right: list<string>}
     */
    public function asideSlots(array $widgets): array
    {
        $slots = ['left' => [], 'right' => []];

        foreach ($widgets as $widget => $key) {
            $placement = $this->placement($key);

            if ($placement->showsLeft()) {
                $slots['left'][] = $widget;
            }

            if ($placement->showsRight()) {
                $slots['right'][] = $widget;
            }
        }

        return $slots;
    }

    private function yamlListingMode(): BlogListingMode
    {
        return BlogListingMode::tryFrom($this->listingModeDefault) ?? BlogListingMode::Paginated;
    }

    private function yamlMasonryStrategy(): BlogMasonryStrategy
    {
        return BlogMasonryStrategy::tryFrom($this->masonryStrategyDefault) ?? BlogMasonryStrategy::Masonry;
    }

    private function resolveMasonryColumn(int $override, int $yaml, int $max): int
    {
        if ($override <= 0) {
            return max(1, min($max, $yaml));
        }

        return max(1, min($max, $override));
    }
}
