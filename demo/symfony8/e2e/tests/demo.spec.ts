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
    await expect(page.locator('[data-testid="blog-kit-articles"] table tbody tr').first()).toBeVisible();
    await expect(page.locator('[data-testid="blog-kit-nav-articles"]')).toHaveAttribute('aria-current', 'page');
    await page.close();
  });

  test('admin pages render on the UiKit page contract (REQ-UI-003)', async ({ browser }) => {
    const page = await browser.newPage({
      httpCredentials: { username: 'admin', password: 'admin' },
    });

    await page.goto('/admin/blog');
    await expect(page.locator('[data-nowo-ui-page="list"][data-testid="blog-kit-articles"]')).toBeVisible();
    // Delete opens the UiKit confirm <dialog> (no window.confirm), Cancel closes it.
    await page.locator('[data-testid="blog-kit-articles"] .nowo-ui-action--delete').first().click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-nowo-confirm-close], [data-nowo-ui-dialog-close]').first().click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    await page.locator('[data-testid="blog-kit-nav-tags"]').click();
    await expect(page.locator('[data-testid="blog-kit-tags"] table tbody tr').first()).toBeVisible();

    await page.locator('[data-testid="blog-kit-nav-comments"]').click();
    await expect(page.locator('[data-testid="blog-kit-comments"]')).toBeVisible();
    await expect(page.locator('[data-testid="blog-kit-comments-status-pending"]')).toHaveAttribute('aria-current', 'page');

    await page.locator('[data-testid="blog-kit-nav-settings"]').click();
    await expect(page.locator('[data-nowo-ui-page="settings"]')).toBeVisible();
    await expect(page.locator('[data-testid="blog-kit-settings-listing"] form')).toBeVisible();
    await page.goto('/admin/blog/settings/article');
    await expect(page.locator('[data-testid="blog-kit-settings-article"]')).toBeVisible();

    await page.goto('/admin/blog/1/edit');
    await expect(page.locator('[data-nowo-ui-page="form"][data-testid="blog-kit-article-form"]')).toBeVisible();
    await expect(page.locator('[data-testid="blog-kit-locale-tabs"] [role="tab"]').first()).toBeVisible();
    await page.close();
  });

  test('public article renders on the guest layout', async ({ page }) => {
    const response = await page.goto('/blog/lorem-ipsum-01');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('[data-testid="blog-kit-article"]')).toBeVisible();
    await expect(page.locator('.nowo-ui-page-header h1')).toBeVisible();
  });
});
