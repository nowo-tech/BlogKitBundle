import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: infinite scroll for the public blog masonry list.
 *
 * CSS multi-column (`column-count`) fills vertically and does not rebalance
 * reliably when HTML is appended. This controller uses explicit columns and
 * always places each card into the shortest one (true cascade).
 */
export default class extends Controller {
    static targets = ['list', 'sentinel', 'status'];

    static values = {
        url: String,
        page: Number,
        totalPages: Number,
        colsMobile: { type: Number, default: 1 },
        colsTablet: { type: Number, default: 2 },
        colsDesktop: { type: Number, default: 2 },
    };

    declare readonly listTarget: HTMLElement;
    declare readonly sentinelTarget: HTMLElement;
    declare readonly hasStatusTarget: boolean;
    declare readonly statusTarget: HTMLElement;
    declare urlValue: string;
    declare pageValue: number;
    declare totalPagesValue: number;
    declare colsMobileValue: number;
    declare colsTabletValue: number;
    declare colsDesktopValue: number;

    private observer: IntersectionObserver | null = null;
    private loading = false;
    private columns: HTMLElement[] = [];
    private mediaQueries: MediaQueryList[] = [];
    private onMediaChange: (() => void) | null = null;

    connect(): void {
        this.initMasonry();

        if (this.pageValue >= this.totalPagesValue) {
            this.sentinelTarget.hidden = true;
            return;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    void this.loadMore();
                }
            },
            { rootMargin: '240px 0px' },
        );
        this.observer.observe(this.sentinelTarget);
    }

    disconnect(): void {
        this.observer?.disconnect();
        this.observer = null;

        if (this.onMediaChange) {
            for (const mq of this.mediaQueries) {
                mq.removeEventListener('change', this.onMediaChange);
            }
        }
        this.mediaQueries = [];
        this.onMediaChange = null;
        this.columns = [];
    }

    private initMasonry(): void {
        this.listTarget.classList.add('blog-masonry--cols');

        this.mediaQueries = [
            window.matchMedia('(min-width: 640px)'),
            window.matchMedia('(min-width: 960px)'),
        ];
        this.onMediaChange = () => this.rebuildColumns(this.collectItems());
        for (const mq of this.mediaQueries) {
            mq.addEventListener('change', this.onMediaChange);
        }

        this.rebuildColumns(this.collectItems());
    }

    private columnCount(): number {
        if (window.matchMedia('(min-width: 960px)').matches) {
            return Math.max(1, Math.min(3, this.colsDesktopValue || 2));
        }
        if (window.matchMedia('(min-width: 640px)').matches) {
            return Math.max(1, Math.min(2, this.colsTabletValue || 2));
        }

        return Math.max(1, Math.min(2, this.colsMobileValue || 1));
    }

    private collectItems(): HTMLElement[] {
        return Array.from(
            this.listTarget.querySelectorAll<HTMLElement>('.blog-masonry__item'),
        );
    }

    private rebuildColumns(items: HTMLElement[]): void {
        const count = this.columnCount();
        const list = this.listTarget;

        list.replaceChildren();
        this.columns = [];

        for (let i = 0; i < count; i++) {
            const col = document.createElement('div');
            col.className = 'blog-masonry__col';
            col.setAttribute('role', 'presentation');
            list.appendChild(col);
            this.columns.push(col);
        }

        for (const item of items) {
            this.appendToShortest(item);
        }
    }

    private appendToShortest(item: HTMLElement): void {
        if (this.columns.length === 0) {
            this.listTarget.appendChild(item);
            return;
        }

        let shortest = this.columns[0];
        let shortestHeight = shortest.getBoundingClientRect().height;

        for (let i = 1; i < this.columns.length; i++) {
            const col = this.columns[i];
            const height = col.getBoundingClientRect().height;
            if (height < shortestHeight) {
                shortest = col;
                shortestHeight = height;
            }
        }

        shortest.appendChild(item);
    }

    private appendHtml(html: string): void {
        const template = document.createElement('template');
        template.innerHTML = html;

        const items = Array.from(template.content.querySelectorAll<HTMLElement>('.blog-masonry__item'));
        if (items.length === 0) {
            // Fallback: append raw nodes if markup is unexpected.
            this.listTarget.append(template.content);
            return;
        }

        for (const item of items) {
            this.appendToShortest(item);
        }
    }

    private async loadMore(): Promise<void> {
        if (this.loading || this.pageValue >= this.totalPagesValue) {
            return;
        }

        this.loading = true;
        this.setStatusVisible(true);

        const nextPage = this.pageValue + 1;
        const url = new URL(this.urlValue, window.location.origin);
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
                this.appendHtml(html);
            }

            this.pageValue = nextPage;

            if (this.pageValue >= this.totalPagesValue) {
                this.sentinelTarget.hidden = true;
                this.observer?.disconnect();
            }
        } catch {
            // Keep sentinel visible so the user can retry by scrolling again.
        } finally {
            this.loading = false;
            this.setStatusVisible(false);
        }
    }

    private setStatusVisible(visible: boolean): void {
        if (!this.hasStatusTarget) {
            return;
        }

        this.statusTarget.hidden = !visible;
    }
}
