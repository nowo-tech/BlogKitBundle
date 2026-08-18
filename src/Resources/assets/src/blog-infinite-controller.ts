/**
 * Infinite-scroll masonry for the public blog list.
 *
 * Boots `[data-controller="blog-infinite"]` without a host Stimulus application.
 * HTML fragments come from the same-origin `blog_index?partial=1` endpoint.
 */

export interface BlogInfiniteColumnConfig {
    mobile: number;
    tablet: number;
    desktop: number;
}

/**
 * Resolve how many masonry columns to show for the current viewport.
 *
 * @param config Mobile / tablet / desktop column counts from data attributes.
 * @param matchesMedia `window.matchMedia` result helper (injectable in tests).
 * @returns A column count between 1 and 3.
 */
export function columnCount(
    config: BlogInfiniteColumnConfig,
    matchesMedia: (query: string) => boolean = (query) => window.matchMedia(query).matches,
): number {
    if (matchesMedia('(min-width: 960px)')) {
        return Math.max(1, Math.min(3, config.desktop || 2));
    }
    if (matchesMedia('(min-width: 640px)')) {
        return Math.max(1, Math.min(2, config.tablet || 2));
    }

    return Math.max(1, Math.min(2, config.mobile || 1));
}

/**
 * Parse a same-origin HTML fragment into element nodes (no `innerHTML` assignment).
 *
 * @param html Markup returned by `blog_index?partial=1`.
 * @returns Parsed child nodes of a wrapper div.
 */
export function parseHtmlFragment(html: string): Node[] {
    const parsed = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const wrap = parsed.body.firstElementChild;

    return wrap ? Array.from(wrap.childNodes) : [];
}

/**
 * Append a partial HTML fragment into masonry columns.
 *
 * @param list Masonry root that holds `.blog-masonry__col` columns.
 * @param html Card markup from the server.
 * @param columns Live column elements (shortest-column cascade).
 * @returns void
 */
export function appendHtml(list: HTMLElement, html: string, columns: HTMLElement[]): void {
    const nodes = parseHtmlFragment(html);
    const items = nodes.filter(
        (node): node is HTMLElement =>
            node instanceof HTMLElement && node.classList.contains('blog-masonry__item'),
    );

    if (items.length === 0) {
        for (const node of nodes) {
            list.appendChild(document.importNode(node, true));
        }
        return;
    }

    for (const item of items) {
        appendToShortest(document.importNode(item, true), columns, list);
    }
}

/**
 * Place one card into the shortest masonry column.
 *
 * @param item Card element.
 * @param columns Column wrappers.
 * @param list Fallback parent when no columns exist.
 * @returns void
 */
export function appendToShortest(item: HTMLElement, columns: HTMLElement[], list: HTMLElement): void {
    if (columns.length === 0) {
        list.appendChild(item);
        return;
    }

    let shortest = columns[0];
    let shortestHeight = shortest.getBoundingClientRect().height;

    for (let i = 1; i < columns.length; i++) {
        const col = columns[i];
        const height = col.getBoundingClientRect().height;
        if (height < shortestHeight) {
            shortest = col;
            shortestHeight = height;
        }
    }

    shortest.appendChild(item);
}

function collectItems(list: HTMLElement): HTMLElement[] {
    return Array.from(list.querySelectorAll<HTMLElement>('.blog-masonry__item'));
}

function rebuildColumns(
    list: HTMLElement,
    items: HTMLElement[],
    columns: HTMLElement[],
    config: BlogInfiniteColumnConfig,
): HTMLElement[] {
    const count = columnCount(config);
    list.replaceChildren();
    columns.length = 0;

    for (let i = 0; i < count; i++) {
        const col = document.createElement('div');
        col.className = 'blog-masonry__col';
        col.setAttribute('role', 'presentation');
        list.appendChild(col);
        columns.push(col);
    }

    for (const item of items) {
        appendToShortest(item, columns, list);
    }

    return columns;
}

/**
 * Bind infinite scroll on one `[data-controller="blog-infinite"]` root.
 *
 * @param root Controller root element.
 * @returns Disconnect callback that removes observers and media listeners.
 */
export function bootBlogInfinite(root: HTMLElement): () => void {
    const list = root.querySelector<HTMLElement>('[data-blog-infinite-target="list"]');
    const sentinel = root.querySelector<HTMLElement>('[data-blog-infinite-target="sentinel"]');
    const status = root.querySelector<HTMLElement>('[data-blog-infinite-target="status"]');
    if (!(list instanceof HTMLElement) || !(sentinel instanceof HTMLElement)) {
        return () => undefined;
    }

    let page = Number(root.dataset.blogInfinitePageValue || '1');
    const totalPages = Number(root.dataset.blogInfiniteTotalPagesValue || '1');
    const urlValue = root.dataset.blogInfiniteUrlValue || '/blog';
    const config: BlogInfiniteColumnConfig = {
        mobile: Number(root.dataset.blogInfiniteColsMobileValue || '1'),
        tablet: Number(root.dataset.blogInfiniteColsTabletValue || '2'),
        desktop: Number(root.dataset.blogInfiniteColsDesktopValue || '2'),
    };

    let observer: IntersectionObserver | null = null;
    let loading = false;
    const columns: HTMLElement[] = [];
    const mediaQueries = [
        window.matchMedia('(min-width: 640px)'),
        window.matchMedia('(min-width: 960px)'),
    ];

    const setStatusVisible = (visible: boolean): void => {
        if (status instanceof HTMLElement) {
            status.hidden = !visible;
        }
    };

    const onMediaChange = (): void => {
        rebuildColumns(list, collectItems(list), columns, config);
    };

    const loadMore = async (): Promise<void> => {
        if (loading || page >= totalPages) {
            return;
        }

        loading = true;
        setStatusVisible(true);

        const nextPage = page + 1;
        const url = new URL(urlValue, window.location.origin);
        const current = new URL(window.location.href);

        for (const key of ['q', 'tag'] as const) {
            const value = current.searchParams.get(key);
            if (value) {
                url.searchParams.set(key, value);
            }
        }

        url.searchParams.set('page', String(nextPage));
        url.searchParams.set('partial', '1');

        try {
            const response = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'text/html',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = (await response.text()).trim();
            if (html !== '') {
                appendHtml(list, html, columns);
            }

            page = nextPage;

            if (page >= totalPages) {
                sentinel.hidden = true;
                observer?.disconnect();
            }
        } catch {
            // Keep the sentinel so the user can retry by scrolling again.
        } finally {
            loading = false;
            setStatusVisible(false);
        }
    };

    list.classList.add('blog-masonry--cols');
    mediaQueries.forEach((mq) => mq.addEventListener('change', onMediaChange));
    rebuildColumns(list, collectItems(list), columns, config);

    const disconnect = (): void => {
        observer?.disconnect();
        observer = null;
        mediaQueries.forEach((mq) => mq.removeEventListener('change', onMediaChange));
        columns.length = 0;
    };

    if (page >= totalPages) {
        sentinel.hidden = true;
        return disconnect;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                void loadMore();
            }
        },
        { rootMargin: '240px 0px' },
    );
    observer.observe(sentinel);

    return disconnect;
}

/**
 * Boot every infinite-scroll root under `scope`.
 *
 * @param scope Document or container to search.
 * @returns void
 */
export function startBlogInfinite(scope: ParentNode = document): void {
    scope.querySelectorAll<HTMLElement>('[data-controller="blog-infinite"]').forEach((el) => {
        bootBlogInfinite(el);
    });
}
