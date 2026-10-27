<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Service;

use Nowo\BlogKitBundle\Repository\BlogArticleRepository;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Security\BlogProtection;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_slice;
use function count;
use function is_string;

/**
 * Read-only public blog catalog (list, detail, sidebars).
 */
final readonly class BlogCatalog
{
    public function __construct(
        private RequestStack $requestStack,
        private BlogArticleRepository $blogArticleRepository,
        private BlogTagRepository $blogTagRepository,
        private BlogSettingsProvider $blogSettingsProvider,
        private BlogHashtagProcessor $blogHashtagProcessor,
        private BlogArticleBodyEnhancer $blogArticleBodyEnhancer,
        private BlogLocalesLocaleResolver $localeResolver,
        private ?BlogProtection $blogProtection = null,
    ) {
    }

    public function locale(): string
    {
        return $this->localeResolver->resolve($this->requestStack->getCurrentRequest());
    }

    public function blogSettings(): BlogSettingsProvider
    {
        return $this->blogSettingsProvider;
    }

    public function blogListingMode(): string
    {
        return $this->blogSettingsProvider->listingMode()->value;
    }

    public function blogPerPage(): int
    {
        return $this->blogSettingsProvider->perPage();
    }

    public function blogMasonryStrategy(): string
    {
        return $this->blogSettingsProvider->masonryStrategy()->value;
    }

    /**
     * @return array{mobile: int, tablet: int, desktop: int}
     */
    public function blogMasonryColumns(): array
    {
        return $this->blogSettingsProvider->masonryColumns();
    }

    /** @return list<array<string, mixed>> */
    public function blogArticles(): array
    {
        return $this->blogArticleRepository->fetchPublishedListData($this->locale());
    }

    /**
     * @return array{
     *     articles: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int},
     *     filters: array{q: string, tag: string}
     * }
     */
    public function blogArticlesPage(
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $tagSlug = null,
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $search  = $search !== null ? trim($search) : '';
        $tagSlug = $tagSlug !== null ? trim($tagSlug) : '';
        $filters = [
            'q'   => $search,
            'tag' => $tagSlug,
        ];

        $result = $this->blogArticleRepository->fetchPublishedPaginatedData(
            $page,
            $perPage,
            $this->locale(),
            $search !== '' ? $search : null,
            $tagSlug !== '' ? $tagSlug : null,
        );
        $total      = $result['total'];
        $totalPages = $total === 0 ? 0 : max(1, (int) ceil($total / $perPage));

        return [
            'articles'   => $result['items'],
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'filters' => $filters,
        ];
    }

    /** @return list<array{slug: string, name: string, count: int}> */
    public function blogTags(?int $limit = null): array
    {
        $tags = $this->blogTagRepository->findPublishedTagSummaries($this->locale());
        $limit ??= $this->blogSettingsProvider->indexTagsLimit();

        if ($limit > 0 && count($tags) > $limit) {
            return array_slice($tags, 0, $limit);
        }

        return $tags;
    }

    /**
     * @return array{
     *     latest: list<array<string, mixed>>,
     *     related_tags: list<array{slug: string, name: string, count: int}>
     * }
     */
    public function blogSidebar(?string $search = null, ?string $tagSlug = null, ?int $latestLimit = null): array
    {
        $search  = $search !== null ? trim($search) : '';
        $tagSlug = $tagSlug !== null ? trim($tagSlug) : '';
        $locale  = $this->locale();
        $latestLimit ??= $this->blogSettingsProvider->indexLatestLimit();
        $tagsLimit = $this->blogSettingsProvider->indexAsideTagsLimit();

        $relatedTags = ($search === '' && $tagSlug === '')
            ? $this->blogTagRepository->findPublishedTagSummaries($locale)
            : $this->blogArticleRepository->fetchTagSummariesMatchingFilters(
                $locale,
                $search !== '' ? $search : null,
                $tagSlug !== '' ? $tagSlug : null,
            );

        return [
            'latest' => $this->blogArticleRepository->fetchLatestMatchingFilters(
                $locale,
                $search !== '' ? $search : null,
                $tagSlug !== '' ? $tagSlug : null,
                $latestLimit,
            ),
            'related_tags' => $tagsLimit > 0 ? array_slice($relatedTags, 0, $tagsLimit) : $relatedTags,
        ];
    }

    /**
     * @return array{
     *     related: list<array<string, mixed>>,
     *     tags: list<array{slug: string, name: string, count: int}>,
     *     resources: list<array{id: int, title: string|null, image: string, position: int}>
     * }
     */
    public function blogArticleSidebar(int $articleId, ?int $relatedLimit = null): array
    {
        if ($articleId <= 0) {
            return [
                'related'   => [],
                'tags'      => [],
                'resources' => [],
            ];
        }

        $locale = $this->locale();
        $relatedLimit ??= $this->blogSettingsProvider->relatedLimit();

        return [
            'related'   => $this->blogArticleRepository->fetchRelatedBySharedTags($articleId, $locale, $relatedLimit),
            'tags'      => $this->blogArticleRepository->fetchArticleTagSummaries($articleId, $locale),
            'resources' => $this->blogArticleRepository->fetchResourcesByArticleIds([$articleId])[$articleId] ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    public function blogArticleBySlug(string $slug): ?array
    {
        $article = $this->blogArticleRepository->fetchPublishedDetailBySlug($slug, $this->locale());

        if ($article === null) {
            return null;
        }

        // Stored before the URL constraint existed (or written outside the form): never print a non-http(s) link.
        $article['linkedin_url'] = self::httpUrl($article['linkedin_url'] ?? null);

        $body = (string) ($article['body'] ?? '');

        if ($body !== '') {
            // Rewrite first, sanitize LAST (SEC-004 M1): the hashtag / enhancer passes rewrite markup, so their output
            // must go through the sanitizer before it is printed |raw.
            $body            = $this->blogHashtagProcessor->localizeHashtagLinks($body);
            $body            = $this->blogArticleBodyEnhancer->enhance($body);
            $article['body'] = $this->blogProtection?->htmlSanitizer()->sanitize($body) ?? $body;
        }

        return $article;
    }

    /**
     * The URL when it is an absolute http(s) URL, else null (`javascript:`, `data:`, … are dropped, SEC-004 L1).
     */
    public static function httpUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $url = trim($url);

        return preg_match('#^https?://[^\s/\\\\]#i', $url) === 1 ? $url : null;
    }
}
