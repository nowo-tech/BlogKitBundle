# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [Unreleased](#unreleased)
- [1.1.6 - 2026-08-19](#116---2026-08-19)
- [1.1.5 - 2026-08-19](#115---2026-08-19)
- [1.1.3 - 2026-08-19](#113---2026-08-19)
- [1.1.2 - 2026-08-19](#112---2026-08-19)
- [1.1.1 - 2026-08-19](#111---2026-08-19)
- [1.1.0 - 2026-08-18](#110---2026-08-18)
- [1.0.0 - 2026-08-18](#100---2026-08-18)

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.1.3...HEAD
[1.1.3]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.0.0
