/**
 * @vitest-environment happy-dom
 */

declare global {
    var __BLOG_KIT_TEST__: boolean | undefined;
}

globalThis.__BLOG_KIT_TEST__ = true;

