# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [Unreleased](#unreleased)
- [1.4.2 - 2026-10-09](#142---2026-10-09)
- [1.4.1 - 2026-09-28](#141---2026-09-28)
- [1.4.0 - 2026-09-28](#140---2026-09-28)
- [1.3.1 - 2026-09-27](#131---2026-09-27)
- [1.3.0 - 2026-09-24](#130---2026-09-24)
- [1.2.0 - 2026-08-28](#120---2026-08-28)
- [1.1.6 - 2026-08-19](#116---2026-08-19)
- [1.1.5 - 2026-08-19](#115---2026-08-19)
- [1.1.3 - 2026-08-19](#113---2026-08-19)
- [1.1.2 - 2026-08-19](#112---2026-08-19)
- [1.1.1 - 2026-08-19](#111---2026-08-19)
- [1.1.0 - 2026-08-18](#110---2026-08-18)
- [1.0.0 - 2026-08-18](#100---2026-08-18)

## [Unreleased]

## [1.4.2] - 2026-10-09

### Security

- `AllowlistBlogHtmlSanitizer`: children of an unwrapped (disallowed) element are now sanitized — they were hoisted after the walk had moved past them, so `<section><script>…</script><img onerror>…</section>` survived unchanged.
- Executable / raw-text elements (`script`, `style`, `template`, `svg`, `math`, `object`, `embed`, `meta`, `link`, `base`, …) are removed with their content instead of being unwrapped.
- `href` / `src` starting with `/\` (read as `//host` by browsers) are rejected like protocol-relative URLs; ASCII tab / LF / CR are stripped before the check (`/<TAB>/host` is `//host`).

### Dependencies

- Bundle lockfile: `nowo-tech/audit-kit-bundle` 1.1.18, `nowo-tech/form-kit-bundle` 2.6.0, `nowo-tech/ui-kit-bundle` 1.9.1; dev `phpstan/phpstan` 2.3.1, `rector/rector` 2.7.0.
- Already on `main`: Symfony group bumps, Vite 8.3.2 / `@types/node` 26.6.4 with `pnpm-lock.yaml` sync, PHP CS Fixer bot fixes.
- Demo (`demo/symfony8`): Symfony 8.1.8, `doctrine/dbal` 4.5.0, `doctrine/orm` 3.7.4, `twig/twig` 3.30.0, `nowo-tech/routing-kit-bundle` 1.5.0; regenerated `config/reference.php`.

## [1.4.1] - 2026-09-28

### Changed

- Dev dependencies: bump `@types/node` / Vite and sync `pnpm-lock.yaml` so CI `pnpm install --frozen-lockfile` succeeds.

## [1.4.0] - 2026-09-28


### Security

- Default `html.sanitize.strategy` is **`allowlist`** (was `none`). Flex recipe ships allowlist; `when@prod` still forces allowlist. Set `none` only for fully trusted editors.

### Added

- **REQ-DEMO-013:** Playwright e2e under `demo/symfony8/e2e/` (`make test-e2e`), `demo-screenshots` target, and README gallery with full demo context (masthead + public index / article / admin; `docs/images/demo/overview.png`, `article.png`, `admin.png`).

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.3.1] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.4.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.4.0
[1.3.1]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-09-24

FrankenPHP worker mode with kernel **not** reset between requests (scenario B / `reset_kernel=false`): bundle-owned state is request-scoped without relying on `services_resetter`. Full write-up: [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md).

### Added

- **`BlogKitWorkerStateSubscriber`:** clears the bundle memos (settings, settings provider, tag caches, publish-event buffer) at the start of every main request and resets the blog entity manager when a previous request closed it.
- **Twig functions** `nowo_blog_kit_can_manage()`, `nowo_blog_kit_can_moderate()`, `nowo_blog_kit_can_configure()` evaluated per call.
- **`comments.captcha.timeout_seconds`** (default `5.0`) for the default `StreamCaptchaHttpClient`.

### Changed

- **Admin templates** use the new access functions instead of the per-user Twig globals.
- **`BlogSettingsRepository::findSingleton()`** refreshes the settings row from the database (`HINT_REFRESH`) when loading it, so a long-lived identity map cannot serve stale comment protection settings.

### Deprecated

- Twig globals `nowo_blog_kit_can_manage`, `nowo_blog_kit_can_moderate`, `nowo_blog_kit_can_configure` (frozen per Twig environment in worker mode without reset).

### Fixed

- **`BlogArticlePublishedDoctrineSubscriber`:** a failed flush no longer leaks its pending articles into the next flush (buffer cleared per top-level flush; implements `ResetInterface`).

[1.3.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-08-28

### Changed

- **Admin blog settings:** split into section routes (`/admin/blog/settings/{listing|cards|index-aside|article|comments}`); `/admin/blog/settings` redirects to listing.
- **`BlogSettingsType`:** optional `section` form option; `listingMode`, `masonryStrategy`, and `heroImageMode` render as `<select>` (`expanded: false`).
- **`BlogKitAdminAccessSubscriber`:** authorize all `admin_blog_settings*` routes via `canConfigure`.
- **Templates:** portable area nav, section tabs, and sectioned settings UI (`_area_nav`, `_settings_section_tabs`, `_nav_tabs`).

### Fixed

- **Demo (symfony8):** install `pdo_mysql` so Compose MySQL `DATABASE_URL` works (REQ-DEMO-011).

### Notes

- Hosts that overrode settings with a custom controller/form for section tabs can remove those overrides and use the bundle routes/form.
- Override Twig templates if you need host-specific admin chrome; form fields come from `BlogSettingsType`.

[1.2.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.2.0

## [1.1.7] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.1.7]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.7

## [1.1.6] - 2026-08-19

Restore **100%** PHP line coverage after the v1.1.5 query-memoization changes.

### Fixed

- **`BlogCatalog`:** cover sidebar tag resolution when no search/tag filters are active (published tag summaries path).

## [1.1.5] - 2026-08-19

Reduce duplicate Doctrine queries on public blog index and detail pages.

### Fixed

- **`BlogArticleRepository`:** memoize tags-by-article lookups per request (`ResetInterface`) so paginated lists and sidebars reuse cached tag rows instead of re-querying overlapping article ids.
- **`BlogTagRepository`:** memoize `findPublishedTagSummaries()` per locale per request.
- **`BlogCatalog`:** sidebar without search/tag filters reuses published tag summaries instead of a heavier filtered SQL query.

## [1.1.3] - 2026-08-19

Restore 100% PHP coverage for `BlogProtection` when no settings row exists yet.

### Fixed

- Test coverage for YAML fallback rate-limit limits when the settings singleton is absent

## [1.1.2] - 2026-08-19

Patch release fixing demo seeding on fresh databases (CI demo smoke).

### Fixed

- `BlogProtection` resolves comment/HTML strategies from YAML when no settings row exists yet, avoiding a nested Doctrine flush while seeding demo articles (`app:load-demo-blog`) with `html.sanitize.strategy: allowlist`

## [1.1.1] - 2026-08-19

Patch release restoring Symfony 7.4 CI matrix compatibility and hardening demo smoke on GitHub Actions.

### Fixed

- Test support user implements `UserInterface::eraseCredentials()` so PHPUnit runs on Symfony 7.4 (the method was removed from the interface in Symfony 8)
- Demo smoke retries longer and falls back to in-container HTTP checks when host port mapping is slow on CI runners

## [1.1.0] - 2026-08-18

Comment protection strategies, object-level publication access, listing YAML defaults, and a Bootstrap-ready FrankenPHP admin chrome.

### Added

- Configurable public-comment **rate-limit strategies**: `none`, `fixed_window` (per IP), `per_ip_article`, `sliding_window`, or a host `service`
- Configurable comment **CAPTCHA strategies**: `none`, `honeypot` (default), `recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`, or a host `service`
- Optional article **HTML sanitizer**: `none` (default), `strip`, `allowlist`, or a host `service` — applied on persist and public render
- Admin settings can override YAML strategies (`inherit` keeps YAML). CAPTCHA secrets stay in YAML only
- YAML `listing.mode` (`paginated` / `infinite`) with admin `inherit` override for the public index
- YAML `listing.masonry` (`strategy`: `masonry` / `grid` / `list`, plus column counts) with admin `inherit` / `0` override
- Object-level admin access for publications: `security.object_access.strategy` `none` / `owner` / host `service`, enforced by `BlogKitAccessDenied` (not Symfony voters)
- FormKit `type_map.entity` prepend so admin article tags (`EntityType`) resolve without a host type map

### Changed

- Demo FrankenPHP admin uses host `admin/layout.html.twig` (Bootstrap 5 + Icons, UiKit flashes) with FormKit Bootstrap profiles and `twig.form_themes` (`bootstrap_5_layout`)
- Admin list tables compose UiKit `card` / `table_wrap` macros so Bootstrap `table-responsive` applies without forking pages

### Security

- Default comment protection: 5 posts / 60s per IP plus a honeypot field
- Public staff replies now require `canModerate()` (not only an authenticated `BlogUserInterface`)
- Optional `owner` object access so editors cannot mutate another author's publications (configure roles still see all)

## [1.0.0] - 2026-08-18

Initial public release of **Blog Kit Bundle** (`nowo-tech/blog-kit-bundle`).

### Added

- Reusable Symfony blog domain: multilingual articles, tags, comments, resources, and settings
- Public index and article pages at `/blog` and `/blog/{slug}`
- Moderated public comments and staff replies
- Admin CRUD for articles, tags, comments, and singleton settings
- Paginated or infinite-scroll listing with configurable asides
- `nowo:blog:sync-hashtags` for LinkedIn-style trailing hashtags
- `BlogArticlePublishedEvent` after an article becomes published
- Canonical `security.access_roles` plus manage / moderate / configure roles, custom checker, and `allow_unauthenticated`
- Admin and public shells: `layout_template`, `public_layout_template`, `css_framework`, `icon_set`, `row_actions_display`
- Semantic public `blog-*` markup and UiKit composition so hosts pick Bootstrap, Tailwind, Foundation, or `custom` without forking pages
- Admin/public `base.html.twig` wrappers that stack CSS/JS with `{{ parent() }}` and nested `nowo_ui_styles` / `nowo_ui_scripts` (REQ-UI-001)
- Native `<dialog>` delete confirms (UiKit `_confirm` + CSRF in the footer) and inline CMS editor
- Vite + pnpm asset pipeline (`blog-kit.js`) for infinite scroll and CollectionType add/remove
- Named Symfony asset package `nowo_blog_kit` (`asset('blog.css', 'nowo_blog_kit')`)
- Optional Doctrine `table_prefix`
- Symfony Flex recipe (including `assets:install` post-install) and FrankenPHP demo (`demo/symfony8`, port `8105`)
- Demo seed command `app:load-demo-blog` and host chrome via `public_layout_template`
- Integrator documentation and Spec Kit baseline

### Security

- Default role guards: `ROLE_ADMIN` (`access_roles` / `configure_roles`), `ROLE_EDITOR` (`manage_roles`), `ROLE_MODERATOR` (`moderate_roles`)
- Symfony Security required by default when `allow_unauthenticated` is `false`
- CSRF-protected admin mutations and public comment forms; deletes go through native confirm dialogs
- Comment bodies escaped in Twig; article HTML documented as trusted-editor `|raw`

[Unreleased]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.2...HEAD
[1.4.2]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.1...v1.4.2
[1.1.3]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.0.0
