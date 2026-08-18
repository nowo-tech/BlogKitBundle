/**
 * Infinite-scroll masonry for the public blog list.
 *
 * Works without a host Stimulus application. Looks for
 * `[data-controller="blog-infinite"]` and the matching data-* values/targets.
 * HTML fragments come from the same-origin `blog_index?partial=1` endpoint.
 */
(function () {
    'use strict';

    /**
     * @param {HTMLElement} root
     */
    function boot(root) {
        const list = root.querySelector('[data-blog-infinite-target="list"]');
        const sentinel = root.querySelector('[data-blog-infinite-target="sentinel"]');
        const status = root.querySelector('[data-blog-infinite-target="status"]');
        if (!(list instanceof HTMLElement) || !(sentinel instanceof HTMLElement)) {
            return;
        }

        let page = Number(root.dataset.blogInfinitePageValue || '1');
        const totalPages = Number(root.dataset.blogInfiniteTotalPagesValue || '1');
        const urlValue = root.dataset.blogInfiniteUrlValue || '/blog';
        const colsMobile = Number(root.dataset.blogInfiniteColsMobileValue || '1');
        const colsTablet = Number(root.dataset.blogInfiniteColsTabletValue || '2');
        const colsDesktop = Number(root.dataset.blogInfiniteColsDesktopValue || '2');

        /** @type {IntersectionObserver | null} */
        let observer = null;
        let loading = false;
        /** @type {HTMLElement[]} */
        let columns = [];
        /** @type {MediaQueryList[]} */
        const mediaQueries = [
            window.matchMedia('(min-width: 640px)'),
            window.matchMedia('(min-width: 960px)'),
        ];

        function columnCount() {
            if (window.matchMedia('(min-width: 960px)').matches) {
                return Math.max(1, Math.min(3, colsDesktop || 2));
            }
            if (window.matchMedia('(min-width: 640px)').matches) {
                return Math.max(1, Math.min(2, colsTablet || 2));
            }

            return Math.max(1, Math.min(2, colsMobile || 1));
        }

        function collectItems() {
            return Array.from(list.querySelectorAll('.blog-masonry__item'));
        }

        /**
         * @param {HTMLElement} item
         */
        function appendToShortest(item) {
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

        /**
         * @param {HTMLElement[]} items
         */
        function rebuildColumns(items) {
            const count = columnCount();
            list.replaceChildren();
            columns = [];
            for (let i = 0; i < count; i++) {
                const col = document.createElement('div');
                col.className = 'blog-masonry__col';
                col.setAttribute('role', 'presentation');
                list.appendChild(col);
                columns.push(col);
            }
            items.forEach(appendToShortest);
        }

        /**
         * @param {string} html
         */
        function appendHtml(html) {
            const template = document.createElement('template');
            template.innerHTML = html;
            const items = Array.from(template.content.querySelectorAll('.blog-masonry__item'));
            if (items.length === 0) {
                list.append(template.content);
                return;
            }
            items.forEach(appendToShortest);
        }

        function setStatusVisible(visible) {
            if (!(status instanceof HTMLElement)) {
                return;
            }
            status.hidden = !visible;
        }

        async function loadMore() {
            if (loading || page >= totalPages) {
                return;
            }
            loading = true;
            setStatusVisible(true);

            const nextPage = page + 1;
            const url = new URL(urlValue, window.location.origin);
            const current = new URL(window.location.href);
            ['q', 'tag'].forEach((key) => {
                const value = current.searchParams.get(key);
                if (value) {
                    url.searchParams.set(key, value);
                }
            });
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
                    throw new Error('HTTP ' + response.status);
                }
                const html = (await response.text()).trim();
                if (html !== '') {
                    appendHtml(html);
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
        }

        function onMediaChange() {
            rebuildColumns(collectItems());
        }

        list.classList.add('blog-masonry--cols');
        mediaQueries.forEach((mq) => mq.addEventListener('change', onMediaChange));
        rebuildColumns(collectItems());

        if (page >= totalPages) {
            sentinel.hidden = true;
            return;
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

        root.addEventListener(
            'blog-infinite:disconnect',
            () => {
                observer?.disconnect();
                observer = null;
                mediaQueries.forEach((mq) => mq.removeEventListener('change', onMediaChange));
                columns = [];
            },
            { once: true },
        );
    }

    function start() {
        document.querySelectorAll('[data-controller="blog-infinite"]').forEach((el) => {
            if (el instanceof HTMLElement) {
                boot(el);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
