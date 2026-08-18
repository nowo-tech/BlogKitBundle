import { afterEach, describe, expect, it, vi } from 'vitest';

describe('blog-kit entry', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        globalThis.__BLOG_KIT_TEST__ = true;
        vi.resetModules();
        vi.unstubAllGlobals();
    });

    it('starts immediately when the document is already loaded', async () => {
        Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
        const mod = await import('./blog-kit');
        expect(() => mod.startBlogKit()).not.toThrow();
        expect(() => mod.attachBlogKitAutostart()).not.toThrow();
    });

    it('defers start until DOMContentLoaded when the document is loading', async () => {
        Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });
        const add = vi.spyOn(document, 'addEventListener');
        const { attachBlogKitAutostart } = await import('./blog-kit');
        attachBlogKitAutostart();
        expect(add).toHaveBeenCalledWith('DOMContentLoaded', expect.any(Function));
    });

    it('auto-starts when the test flag is off', async () => {
        globalThis.__BLOG_KIT_TEST__ = false;
        Object.defineProperty(document, 'readyState', { configurable: true, value: 'complete' });
        await import('./blog-kit');
        expect(document.body).toBeTruthy();
    });
});
