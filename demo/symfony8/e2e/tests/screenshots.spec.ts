import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { frameShot } from './frame';

/**
 * REQ-DEMO-013 — full demo frame: the demo platform shell + the blog UI, without the Symfony WebProfiler toolbar.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

async function fitViewport(page: import('@playwright/test').Page) {
  await page.setViewportSize({ width: 1280, height: 900 });
}

/** Waits for web fonts and visible images (demo covers come from picsum.photos). */
async function waitMedia(page: import('@playwright/test').Page) {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page.evaluate(() => document.fonts.ready).catch(() => {});
  await page
    .waitForFunction(
      () =>
        Array.from(document.images)
          .filter((img) => img.getBoundingClientRect().top < window.innerHeight)
          .every((img) => img.complete),
      undefined,
      { timeout: 20000 },
    )
    .catch(() => {});
}

async function capture(page: import('@playwright/test').Page, file: string) {
  await waitMedia(page);
  await frameShot(page, resolve(outDir, file));
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('BlogKit screenshots (full demo context)', () => {
  test('overview — shell + public blog index', async ({ page }) => {
    await fitViewport(page);
    await page.goto('/blog');
    await expect(page.locator('[data-testid="demo-platform"]')).toBeVisible();
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('.blog-item, .blog-feed, .blog-search').first()).toBeVisible({
      timeout: 15000,
    });
    await capture(page, 'overview.png');
  });

  test('article — shell + published article detail', async ({ page }) => {
    await fitViewport(page);
    await page.goto('/blog');
    const first = page.locator('a[href*="/blog/lorem-ipsum-"], a[href^="/blog/"][href*="lorem"]').first();
    if ((await first.count()) > 0) {
      await first.click();
    } else {
      await page.goto('/blog/lorem-ipsum-01');
    }
    await expect(page.locator('[data-testid="demo-platform"]')).toBeVisible();
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('article, .blog-article, .blog-item__title, h1').first()).toBeVisible({
      timeout: 15000,
    });
    await capture(page, 'article.png');
  });

  test('admin — shell + articles CRUD (HTTP Basic)', async ({ browser }) => {
    const page = await browser.newPage({
      httpCredentials: { username: 'admin', password: 'admin' },
    });
    await fitViewport(page);
    await page.goto('/admin/blog');
    await expect(page.locator('[data-testid="demo-platform"]')).toBeVisible();
    await expect(page.locator('[data-testid="blog-kit-articles"] table tbody tr').first()).toBeVisible({
      timeout: 15000,
    });
    await capture(page, 'admin.png');
    await page.close();
  });
});
