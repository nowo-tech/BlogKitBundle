import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    appendHtml,
    appendToShortest,
    bootBlogInfinite,
    columnCount,
    parseHtmlFragment,
    startBlogInfinite,
} from './blog-infinite-controller';

class FakeIntersectionObserver {
    static last: FakeIntersectionObserver | null = null;

    readonly callback: IntersectionObserverCallback;

    disconnected = false;

    constructor(callback: IntersectionObserverCallback) {
        this.callback = callback;
        FakeIntersectionObserver.last = this;
    }

    observe(): void {
        // no-op until tests trigger
    }

    disconnect(): void {
        this.disconnected = true;
    }

    unobserve(): void {
        // no-op
    }

    takeRecords(): IntersectionObserverEntry[] {
        return [];
    }

    trigger(isIntersecting: boolean): void {
        this.callback(
            [{ isIntersecting } as IntersectionObserverEntry],
            this as unknown as IntersectionObserver,
        );
    }
}

function installIo(): void {
    FakeIntersectionObserver.last = null;
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
}

function setMatchMedia(desktop: boolean, tablet: boolean): { fireChange: () => void } {
    const listeners: Array<() => void> = [];
    vi.stubGlobal('matchMedia', (query: string) => {
        const matches = query.includes('960') ? desktop : query.includes('640') ? tablet : false;
        return {
            matches,
            media: query,
            addEventListener: (_event: string, cb: () => void) => {
                listeners.push(cb);
            },
            removeEventListener: (_event: string, cb: () => void) => {
                const index = listeners.indexOf(cb);
                if (index >= 0) {
                    listeners.splice(index, 1);
                }
            },
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
            onchange: null,
        } as unknown as MediaQueryList;
    });

    return {
        fireChange: () => {
            listeners.forEach((cb) => cb());
        },
    };
}

function feedHtml(): string {
    return `
      <div data-controller="blog-infinite"
           data-blog-infinite-url-value="/blog"
           data-blog-infinite-page-value="1"
           data-blog-infinite-total-pages-value="2"
           data-blog-infinite-cols-mobile-value="1"
           data-blog-infinite-cols-tablet-value="2"
           data-blog-infinite-cols-desktop-value="2">
        <div data-blog-infinite-target="list" class="blog-masonry">
          <article class="blog-masonry__item">one</article>
        </div>
        <div data-blog-infinite-target="sentinel"></div>
        <p data-blog-infinite-target="status" hidden>loading</p>
      </div>
    `;
}

describe('blog-infinite-controller', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('resolves column counts for mobile, tablet, and desktop', () => {
        expect(columnCount({ mobile: 1, tablet: 2, desktop: 3 }, () => false)).toBe(1);
        expect(columnCount({ mobile: 1, tablet: 2, desktop: 3 }, (q) => q.includes('640'))).toBe(2);
        expect(columnCount({ mobile: 1, tablet: 2, desktop: 3 }, (q) => q.includes('960'))).toBe(3);
        expect(columnCount({ mobile: 0, tablet: 0, desktop: 0 }, () => false)).toBe(1);
    });

    it('parses HTML fragments and appends masonry items to the shortest column', () => {
        const list = document.createElement('div');
        const short = document.createElement('div');
        const tall = document.createElement('div');
        vi.spyOn(short, 'getBoundingClientRect').mockReturnValue({ height: 10 } as DOMRect);
        vi.spyOn(tall, 'getBoundingClientRect').mockReturnValue({ height: 80 } as DOMRect);

        appendHtml(list, '<article class="blog-masonry__item">card</article>', [tall, short]);
        expect(short.querySelector('.blog-masonry__item')?.textContent).toBe('card');
    });

    it('falls back to appending raw nodes when no masonry items exist', () => {
        const list = document.createElement('div');
        appendHtml(list, '<p class="note">hello</p>', []);
        expect(list.querySelector('.note')?.textContent).toBe('hello');
    });

    it('appends directly to the list when there are no columns', () => {
        const list = document.createElement('div');
        const item = document.createElement('article');
        appendToShortest(item, [], list);
        expect(list.contains(item)).toBe(true);
    });

    it('parses empty wrappers as no nodes', () => {
        expect(parseHtmlFragment('')).toHaveLength(0);
    });

    it('no-ops when required targets are missing', () => {
        const root = document.createElement('div');
        const stop = bootBlogInfinite(root);
        stop();
        expect(root.childElementCount).toBe(0);
    });

    it('hides the sentinel when already on the last page', () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml().replace(
            'data-blog-infinite-total-pages-value="2"',
            'data-blog-infinite-total-pages-value="1"',
        );
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        bootBlogInfinite(root);
        const sentinel = root.querySelector<HTMLElement>('[data-blog-infinite-target="sentinel"]')!;
        expect(sentinel.hidden).toBe(true);
    });

    it('loads the next page when the sentinel intersects', async () => {
        setMatchMedia(true, true);
        installIo();
        document.body.innerHTML = feedHtml();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                text: async () => '<article class="blog-masonry__item">two</article>',
            }),
        );

        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        bootBlogInfinite(root);
        FakeIntersectionObserver.last?.trigger(true);
        await vi.waitFor(() => {
            expect(root.querySelectorAll('.blog-masonry__item')).toHaveLength(2);
        });
        expect(root.querySelector('[data-blog-infinite-target="list"]')?.classList.contains('blog-masonry--cols')).toBe(
            true,
        );
        expect(root.querySelector<HTMLElement>('[data-blog-infinite-target="sentinel"]')?.hidden).toBe(
            true,
        );
    });

    it('keeps the sentinel visible when fetch fails', async () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500, text: async () => '' }));

        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        bootBlogInfinite(root);
        FakeIntersectionObserver.last?.trigger(true);
        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalled();
        });
        expect(root.querySelector<HTMLElement>('[data-blog-infinite-target="sentinel"]')?.hidden).toBe(
            false,
        );
    });

    it('copies q/tag search params onto the partial request', async () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml();
        window.history.replaceState({}, '', '/blog?q=symfony&tag=php');
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, text: async () => '' });
        vi.stubGlobal('fetch', fetchMock);

        startBlogInfinite();
        FakeIntersectionObserver.last?.trigger(true);
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        const requested = String(fetchMock.mock.calls[0]?.[0]);
        expect(requested).toContain('q=symfony');
        expect(requested).toContain('tag=php');
        expect(requested).toContain('partial=1');
    });

    it('rebuilds columns on media-query change and skips empty partials', async () => {
        const media = setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                text: async () => '   ',
            }),
        );
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        bootBlogInfinite(root);
        media.fireChange();
        FakeIntersectionObserver.last?.trigger(true);
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(root.querySelectorAll('.blog-masonry__item')).toHaveLength(1);
    });

    it('uses default dataset values when optional attributes are omitted', () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = `
          <div data-controller="blog-infinite">
            <div data-blog-infinite-target="list" class="blog-masonry"></div>
            <div data-blog-infinite-target="sentinel"></div>
          </div>
        `;
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        bootBlogInfinite(root);
        expect(root.querySelector<HTMLElement>('[data-blog-infinite-target="sentinel"]')?.hidden).toBe(
            true,
        );
    });

    it('boots without a status target', () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml().replace(
            '<p data-blog-infinite-target="status" hidden>loading</p>',
            '',
        );
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        expect(() => bootBlogInfinite(root)).not.toThrow();
    });

    it('ignores a second intersect while a fetch is in flight and after the last page', async () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml();
        let resolveFetch: (value: { ok: boolean; text: () => Promise<string> }) => void = () => undefined;
        const fetchMock = vi.fn().mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveFetch = resolve;
                }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        bootBlogInfinite(root);
        FakeIntersectionObserver.last?.trigger(true);
        FakeIntersectionObserver.last?.trigger(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        resolveFetch({ ok: true, text: async () => '<article class="blog-masonry__item">two</article>' });
        await vi.waitFor(() => {
            expect(root.querySelectorAll('.blog-masonry__item')).toHaveLength(2);
        });
        FakeIntersectionObserver.last?.trigger(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('ignores non-intersecting observer entries', () => {
        setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml();
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        const stop = bootBlogInfinite(root);
        FakeIntersectionObserver.last?.trigger(false);
        expect(fetchMock).not.toHaveBeenCalled();
        stop();
        expect(FakeIntersectionObserver.last?.disconnected).toBe(true);
    });

    it('appends linearly without packing columns when layout is grid', async () => {
        setMatchMedia(true, true);
        installIo();
        document.body.innerHTML = feedHtml().replace(
            'data-blog-infinite-cols-desktop-value="2"',
            'data-blog-infinite-cols-desktop-value="2" data-blog-infinite-layout-value="grid"',
        );
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                text: async () => '<article class="blog-masonry__item">two</article>',
            }),
        );

        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        const list = root.querySelector<HTMLElement>('[data-blog-infinite-target="list"]')!;
        bootBlogInfinite(root);

        expect(list.classList.contains('blog-masonry--cols')).toBe(false);
        expect(list.querySelectorAll('.blog-masonry__col')).toHaveLength(0);

        FakeIntersectionObserver.last?.trigger(true);
        await vi.waitFor(() => {
            expect(root.querySelectorAll('.blog-masonry__item')).toHaveLength(2);
        });
        expect(list.querySelectorAll(':scope > .blog-masonry__item')).toHaveLength(2);
    });

    it('does not rebuild columns on media change when layout is list', () => {
        const media = setMatchMedia(false, false);
        installIo();
        document.body.innerHTML = feedHtml().replace(
            'data-blog-infinite-cols-desktop-value="2"',
            'data-blog-infinite-cols-desktop-value="2" data-blog-infinite-layout-value="list"',
        );
        const root = document.querySelector<HTMLElement>('[data-controller="blog-infinite"]')!;
        const list = root.querySelector<HTMLElement>('[data-blog-infinite-target="list"]')!;
        bootBlogInfinite(root);
        media.fireChange();

        expect(list.classList.contains('blog-masonry--cols')).toBe(false);
        expect(list.querySelectorAll('.blog-masonry__col')).toHaveLength(0);
        expect(list.querySelectorAll('.blog-masonry__item')).toHaveLength(1);
    });
});
