/**
 * Blog Kit public/admin IIFE entry (REQ-ASSETS-001 / REQ-UX-001).
 *
 * Boots infinite-scroll masonry and CollectionType add/remove without a host
 * Stimulus application. Safe to load on every Blog Kit page: missing roots are no-ops.
 */

import { bindFormCollection } from './blog-form-collection';
import { startBlogInfinite } from './blog-infinite-controller';

/**
 * Start Blog Kit browser behaviours.
 *
 * @returns void
 */
export function startBlogKit(): void {
    bindFormCollection();
    startBlogInfinite();
}

/**
 * Attach autostart for the IIFE build (skipped in Vitest via global flag).
 *
 * @returns void
 */
export function attachBlogKitAutostart(): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startBlogKit);
        return;
    }
    startBlogKit();
}

declare global {
    // eslint-disable-next-line no-var
    var __BLOG_KIT_TEST__: boolean | undefined;
}

if (globalThis.__BLOG_KIT_TEST__ !== true) {
    attachBlogKitAutostart();
}
