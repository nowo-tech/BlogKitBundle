import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — full demo frame: demo masthead/toolbar + blog UI
 * (+ Symfony WebProfiler when present).
 *
 * Playwright clips to the viewport, so we enlarge the viewport before capture.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

type Box = { x: number; y: number; width: number; height: number };

async function boxOf(
  page: import('@playwright/test').Page,
  selector: string,
): Promise<Box | null> {
  const loc = page.locator(selector).first();
  if ((await loc.count()) === 0) {
    return null;
  }
  return loc.boundingBox();
}

/** Masthead → main → profiler (union), padded. */
async function clipDemoFrame(page: import('@playwright/test').Page): Promise<Box> {
  const masthead =
    (await boxOf(page, 'header.demo-masthead')) ?? (await boxOf(page, '.demo-masthead'));
  const main = await boxOf(page, 'main');
  const profiler =
    (await boxOf(page, '.sf-toolbar')) ?? (await boxOf(page, '.sf-minitoolbar'));
  if (!masthead || !main) {
    throw new Error('Missing demo-masthead or main for BlogKit screenshot clip');
  }
  const boxes = [masthead, main, profiler].filter(Boolean) as Box[];
  const x = Math.min(...boxes.map((b) => b.x));
  const y = Math.min(...boxes.map((b) => b.y));
  const right = Math.max(...boxes.map((b) => b.x + b.width));
  const bottom = Math.max(...boxes.map((b) => b.y + b.height));
  const pad = 8;
  return {
    x: Math.max(0, x - pad),
    y: Math.max(0, y - pad),
    width: right - x + pad * 2,
    height: bottom - y + pad * 2,
  };
}

async function fitViewport(page: import('@playwright/test').Page) {
  // Tall enough for masthead + listing/admin table + profiler.
  await page.setViewportSize({ width: 1280, height: 1600 });
}

async function waitProfiler(page: import('@playwright/test').Page) {
  await page
    .locator('.sf-toolbar .sf-toolbar-block, .sf-toolbar-status, .sf-minitoolbar')
    .first()
    .waitFor({ state: 'visible', timeout: 10000 })
    .catch(() => {});
}

async function capture(page: import('@playwright/test').Page, file: string) {
  const clip = await clipDemoFrame(page);
  if (clip.height >= 700) {
    await page.screenshot({ path: resolve(outDir, file), clip });
  } else {
    await page.screenshot({ path: resolve(outDir, file), fullPage: true });
  }
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('BlogKit screenshots (full demo context)', () => {
  test('overview — masthead + public blog index', async ({ page }) => {
    await fitViewport(page);
    await page.goto('/blog');
    await expect(page.locator('header.demo-masthead')).toBeVisible();
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('.blog-item, .blog-feed, .blog-search').first()).toBeVisible({
      timeout: 15000,
    });
    await waitProfiler(page);
    await capture(page, 'overview.png');
  });

  test('article — masthead + published article detail', async ({ page }) => {
    await fitViewport(page);
    await page.goto('/blog');
    const first = page.locator('a[href*="/blog/lorem-ipsum-"], a[href^="/blog/"][href*="lorem"]').first();
    if ((await first.count()) > 0) {
      await first.click();
    } else {
      await page.goto('/blog/lorem-ipsum-01');
    }
    await expect(page.locator('header.demo-masthead')).toBeVisible();
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('article, .blog-article, .blog-item__title, h1').first()).toBeVisible({
      timeout: 15000,
    });
    await waitProfiler(page);
    await capture(page, 'article.png');
  });

  test('admin — masthead + articles CRUD (HTTP Basic)', async ({ browser }) => {
    const page = await browser.newPage({
      httpCredentials: { username: 'admin', password: 'admin' },
    });
    await fitViewport(page);
    await page.goto('/admin/blog');
    await expect(page.locator('header.demo-masthead')).toBeVisible();
    await expect(page.locator('.demo-admin, .nowo-blog-kit-admin-tabs').first()).toBeVisible({
      timeout: 15000,
    });
    await waitProfiler(page);
    await capture(page, 'admin.png');
    await page.close();
  });
});
