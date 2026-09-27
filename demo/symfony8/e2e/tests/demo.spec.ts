import { test, expect } from '@playwright/test';

test.describe('BlogKit demo', () => {
  test('home links to public blog and admin', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('.demo-hero')).toBeVisible();
    await expect(page.getByRole('link', { name: /Leer el blog|Read/i })).toBeVisible();
  });

  test('public blog index shows article cards', async ({ page }) => {
    const response = await page.goto('/blog');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('header.demo-masthead')).toBeVisible();
    await expect(page.locator('.blog-item, .blog-feed, .blog-search').first()).toBeVisible({
      timeout: 10000,
    });
  });

  test('admin articles list requires HTTP Basic', async ({ browser }) => {
    const denied = await browser.newPage();
    const unauth = await denied.goto('/admin/blog');
    expect(unauth?.status()).toBe(401);
    await denied.close();

    const page = await browser.newPage({
      httpCredentials: { username: 'admin', password: 'admin' },
    });
    const response = await page.goto('/admin/blog');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('header.demo-masthead')).toBeVisible();
    await expect(page.locator('.nowo-blog-kit-admin-tabs, .demo-admin').first()).toBeVisible();
    await page.close();
  });
});
